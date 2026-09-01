<?php

declare(strict_types=1);

namespace Saola\Compiler\Hydration;

use Saola\Compiler\Support\Balanced;
use Saola\Compiler\Support\Html;
use Saola\Compiler\Support\Re;

/**
 * Thêm hydrate class và marker SSR vào template Blade.
 *
 * Bộ xử lý duyệt cùng cấu trúc mà emitter JS duyệt: element, directive, case,
 * loop, output và component đều dùng chung quy tắc cấp id. Nhờ đó client có
 * thể claim đúng DOM server thay vì tạo node trùng.
 *
 * Port từ compiler/src/sao2blade/hydrate_processor.py.
 */
final class BladeHydrateProcessor
{
    /** @var array<string, true> */
    private const VOID_ELEMENTS = [
        'area' => true, 'base' => true, 'br' => true, 'col' => true,
        'embed' => true, 'hr' => true, 'img' => true, 'input' => true,
        'link' => true, 'meta' => true, 'param' => true, 'source' => true,
        'track' => true, 'wbr' => true,
    ];

    /** @var array<string, true> */
    private const EVENT_NAMES = [
        'click' => true, 'dblclick' => true, 'mousedown' => true, 'mouseup' => true,
        'mouseover' => true, 'mouseout' => true, 'mousemove' => true,
        'mouseenter' => true, 'mouseleave' => true, 'wheel' => true,
        'keydown' => true, 'keyup' => true, 'keypress' => true,
        'input' => true, 'change' => true, 'submit' => true, 'reset' => true,
        'invalid' => true, 'focus' => true, 'blur' => true, 'focusin' => true,
        'focusout' => true, 'touchstart' => true, 'touchmove' => true,
        'touchend' => true, 'touchcancel' => true, 'dragstart' => true,
        'drag' => true, 'dragend' => true, 'dragenter' => true,
        'dragleave' => true, 'dragover' => true, 'drop' => true,
        'scroll' => true, 'resize' => true, 'contextmenu' => true,
        'copy' => true, 'cut' => true, 'paste' => true, 'select' => true,
        'load' => true, 'error' => true, 'abort' => true,
        'animationstart' => true, 'animationend' => true, 'animationiteration' => true,
        'transitionstart' => true, 'transitionend' => true, 'transitionrun' => true,
        'transitioncancel' => true, 'pointerdown' => true, 'pointerup' => true,
        'pointermove' => true, 'pointerover' => true, 'pointerout' => true,
        'pointerenter' => true, 'pointerleave' => true, 'pointercancel' => true,
    ];

    /**
     * Directive chỉ có nghĩa ở CSR — Blade không đăng ký, nên phải BỎ khỏi
     * output giống hệt event. Để lọt thì `@transition('row')` in nguyên văn
     * vào thẻ mở, thành một attribute rác trong HTML SSR.
     *
     * @var array<string, true>
     */
    private const CSR_ONLY_DIRECTIVES = ['transition' => true];

    /** @var array<string, true> */
    private array $stateVariables;

    private HydrateIdGenerator $idGenerator;

    private IdMode $idMode;

    /**
     * @param iterable<string> $stateVariables
     */
    public function __construct(
        iterable $stateVariables = [],
        private readonly string $scopeClass = '',
        ?IdMode $idMode = null,
    )
    {
        $this->stateVariables = [];
        foreach ($stateVariables as $variable) {
            $this->stateVariables[$variable] = true;
        }

        $this->idGenerator = new HydrateIdGenerator();
        if ($idMode !== null) {
            $this->idMode = $idMode;
        } else {
            // Legacy direct-use compatibility. Public SaolaCompiler always
            // injects the mode explicitly, so compile() itself never reads env.
            $rawMode = getenv('SAOLA_ID_MODE');
            $this->idMode = IdMode::fromString($rawMode === false ? 'terse' : $rawMode);
        }
    }

    public function process(string $templateContent, bool $hasExtends = false): string
    {
        $this->idGenerator->reset();

        // ⓑ @verbatim nghĩa là "giữ nguyên văn". Không che thì `<pre>` bên
        // trong bị gắn hydrate id và `{{ x }}` bị bọc @startMarker — mà trong
        // @verbatim Blade KHÔNG thực thi directive, nên `@startMarker(...)` sẽ
        // hiện nguyên chữ ra trang. Preprocessor đã tôn trọng @verbatim; chỗ
        // này thì chưa.
        // Comment Blade `{{-- --}}` cũng phải che: nội dung bên trong là VĂN BẢN,
        // không phải markup. Không che thì thẻ HTML nêu làm ví dụ trong comment
        // ĂN MẤT bộ đếm element — `partials/head.sao` có `<head>`, `<link>`,
        // `<script>` trong comment, làm thẻ <meta> THẬT nhận id e121 trong khi
        // sao2js (vốn bỏ qua comment) cho nó e1 ⇒ lệch, không hydrate được.
        // ⓒ `<script>`/`<style>` NẰM TRONG thẻ bọc: sao2js bỏ qua hẳn
        // (RAW_CONTENT_ELEMENTS trong Ast/Parser), sao2blade thì không — nên
        // chúng ăn mất bộ đếm element và MỌI thẻ sau đó lệch id so với JS
        // (div=e1, script=e2, p=e3 ở blade nhưng p=e2 ở js ⇒ hydrate bám nhầm).
        // Tệ hơn: bộ xử lý còn CHUI VÀO nội dung script và viết đè lên mã JS —
        // `var t = "<span>"` thành `var t = "<span @class([...])>"`, hỏng
        // chuỗi lúc chạy. Che cả khối là khớp đúng hành vi sao2js.
        $verbatim = [];
        $templateContent = Re::replaceCallback(
            '/\{\{--.*?--\}\}|@verbatim\b.*?@endverbatim\b|<script\b[^>]*>[\s\S]*?<\/script>|<style\b[^>]*>[\s\S]*?<\/style>/si',
            static function (array $m) use (&$verbatim): string {
                $placeholder = '__SAO_VERBATIM_' . count($verbatim) . '__';
                $verbatim[] = $m[0];

                return $placeholder;
            },
            $templateContent,
        );

        // ⓐ Ghép thẻ MỞ trải nhiều dòng về một dòng. Vòng lặp dưới chạy theo
        // TỪNG DÒNG nên thẻ nhiều dòng không khớp được — element đó mất hydrate
        // id, tức không hydrate được. Chỉ ghép phần BÊN TRONG thẻ mở; nội dung
        // không bị đụng.
        $templateContent = Html::joinMultilineOpenTags($templateContent);
        // Directive điều khiển phải đứng riêng dòng: cả hai emitter xử lý
        // theo DÒNG nên nội dung dính cùng dòng sẽ mất (§14).
        $templateContent = Html::splitInlineDirectives($templateContent);

        $lines = explode("\n", $templateContent);
        $output = [];

        /** @var list<string> $tagStack */
        $tagStack = [];
        /** @var list<array{string, string, list<string>, array<string, ?string>}> $reactiveStack */
        $reactiveStack = [];
        /** @var array<string, int> $caseCounters */
        $caseCounters = [];
        /** @var list<array{string, string, string}> [reactive id, Blade var, JS var] */
        $loopScopes = [];
        $inSsr = false;

        foreach ($lines as $index => $rawLine) {
            $stripped = self::pyStrip($rawLine);
            Re::match('/^(\s*)/u', $rawLine, $indentMatch);
            $indent = $indentMatch[1] ?? '';

            if ($stripped === '') {
                $output[] = $rawLine;
                continue;
            }

            if (Re::match('/^\s*@key\s*\((.*?)\)\s*$/s', $stripped, $keyMatch)) {
                if ($loopScopes !== []) {
                    $expr = self::pyStrip($keyMatch[1]);
                    $last = count($loopScopes) - 1;
                    [$reactiveId, , $jsVar] = $loopScopes[$last];
                    $loopScopes[$last] = [$reactiveId, $this->saoToBladeExpr($expr), $jsVar];
                }
                continue;
            }

            if (Re::match('/^\s*@(?:serverside|serverSide|ssr|SSR|useSSR|useSsr)\b/', $stripped)) {
                $inSsr = true;
                continue;
            }
            if (Re::match('/^\s*@end(?:serverside|serverSide|ServerSide|SSR|Ssr|ssr|useSSR|useSsr)\b/', $stripped)) {
                $inSsr = false;
                continue;
            }
            if ($inSsr) {
                $output[] = $rawLine;
                continue;
            }

            if (Re::match('/^\s*@block\s*\(\s*[\'\"](\w+)[\'\"]\s*\)/u', $rawLine, $blockMatch)) {
                $blockName = $blockMatch[1];
                $this->idGenerator->pushBlock($blockName);
                $reactiveStack[] = ['block', $blockName, [], []];
                $output[] = $rawLine;
                continue;
            }
            if (Re::match('/^\s*@endblock\b/', $stripped)) {
                if ($reactiveStack !== [] && $reactiveStack[count($reactiveStack) - 1][0] === 'block') {
                    $this->idGenerator->popScope();
                    array_pop($reactiveStack);
                }
                $output[] = $rawLine;
                continue;
            }

            if (Re::match('/^(\s*)@if\s*\(/', $rawLine)) {
                $expr = $this->extractParensFromDirective($rawLine, '@if');
                $stateKeys = $this->getStateKeys($expr);
                $reactiveId = $this->idGenerator->pushReactive('if');
                $idValue = $this->bladeIdValue($reactiveId, $loopScopes);
                $caseCounters[$reactiveId] = 0;
                $reactiveStack[] = ['if', $reactiveId, $stateKeys, []];
                $keysPhp = self::phpArray($stateKeys);
                $output[] = "{$indent}@startMarker('reactive', {$idValue}, ['stateKey' => {$keysPhp}, 'type' => 'if'])";
                $caseCounters[$reactiveId]++;
                $this->idGenerator->pushCase($caseCounters[$reactiveId]);
                $output[] = $rawLine;
                continue;
            }

            if (Re::match('/^\s*@elseif\s*\(/', $stripped)) {
                if ($reactiveStack !== [] && $reactiveStack[count($reactiveStack) - 1][0] === 'if') {
                    $last = count($reactiveStack) - 1;
                    $reactiveId = $reactiveStack[$last][1];
                    $this->idGenerator->popScope();
                    $caseCounters[$reactiveId]++;
                    $this->idGenerator->pushCase($caseCounters[$reactiveId]);
                    $newExpr = $this->extractParensFromDirective($rawLine, '@elseif');
                    if ($newExpr !== null && $newExpr !== '') {
                        $merged = array_values(array_unique(array_merge(
                            $reactiveStack[$last][2],
                            $this->getStateKeys($newExpr),
                        )));
                        sort($merged, SORT_STRING);
                        $reactiveStack[$last] = ['if', $reactiveId, $merged, []];
                    }
                }
                $output[] = $rawLine;
                continue;
            }

            if (Re::match('/^\s*@else\s*$/', $stripped)) {
                if ($reactiveStack !== [] && $reactiveStack[count($reactiveStack) - 1][0] === 'if') {
                    $reactiveId = $reactiveStack[count($reactiveStack) - 1][1];
                    $this->idGenerator->popScope();
                    $caseCounters[$reactiveId]++;
                    $this->idGenerator->pushCase($caseCounters[$reactiveId]);
                }
                $output[] = $rawLine;
                continue;
            }

            if (Re::match('/^\s*@endif\b/', $stripped)) {
                if ($reactiveStack !== [] && $reactiveStack[count($reactiveStack) - 1][0] === 'if') {
                    $reactiveId = $reactiveStack[count($reactiveStack) - 1][1];
                    $this->idGenerator->popScope();
                    $this->idGenerator->popScope();
                    array_pop($reactiveStack);
                    unset($caseCounters[$reactiveId]);
                    $idValue = $this->bladeIdValue($reactiveId, $loopScopes);
                    $output[] = "{$indent}@endif";
                    $output[] = "{$indent}@endMarker('reactive', {$idValue})";
                    continue;
                }
                $output[] = $rawLine;
                continue;
            }

            if (Re::match('/^\s*@foreach\s*\(/', $stripped)) {
                $expr = $this->extractParensFromDirective($rawLine, '@foreach');
                $stateKeys = $this->getStateKeys($expr);
                $reactiveId = $this->idGenerator->pushReactive('foreach');
                $idValue = $this->bladeIdValue($reactiveId, $loopScopes);
                $reactiveStack[] = ['foreach', $reactiveId, $stateKeys, []];
                if ($stateKeys !== []) {
                    $keysPhp = self::phpArray($stateKeys);
                    $output[] = "{$indent}@startMarker('reactive', {$idValue}, ['stateKey' => {$keysPhp}, 'type' => 'foreach'])";
                }
                $foundKey = $this->findLoopKey($lines, $index);
                $bladeVar = $foundKey !== null ? $this->saoToBladeExpr($foundKey) : '$loop->index';
                $loopScopes[] = [$reactiveId, $bladeVar, '__loopIndex'];
                $output[] = $rawLine;
                continue;
            }

            if (Re::match('/^\s*@endforeach\b/', $stripped)) {
                if ($reactiveStack !== [] && $reactiveStack[count($reactiveStack) - 1][0] === 'foreach') {
                    $entry = array_pop($reactiveStack);
                    $this->idGenerator->popScope();
                    if ($loopScopes !== [] && $loopScopes[count($loopScopes) - 1][0] === $entry[1]) {
                        array_pop($loopScopes);
                    }
                    $output[] = "{$indent}@endforeach";
                    if ($entry[2] !== []) {
                        $idValue = $this->bladeIdValue($entry[1], $loopScopes);
                        $output[] = "{$indent}@endMarker('reactive', {$idValue})";
                    }
                    continue;
                }
                $output[] = $rawLine;
                continue;
            }

            if (Re::match('/^\s*@while\s*\(/', $stripped)) {
                $expr = $this->extractParensFromDirective($rawLine, '@while');
                $loopVar = $this->extractWhileVar($expr);
                $endValue = $this->extractWhileEnd($expr);
                $reactiveId = $this->idGenerator->pushReactive('while');
                $idValue = $this->bladeIdValue($reactiveId, $loopScopes);
                $reactiveStack[] = ['while', $reactiveId, [], ['loop_var' => $loopVar, 'end_val' => $endValue]];
                $options = [];
                if ($loopVar !== null && $loopVar !== '') {
                    $options[] = "'start' => {$loopVar}";
                }
                if ($endValue !== null && $endValue !== '') {
                    $options[] = "'end' => {$endValue}";
                }
                $optionsSource = $options === [] ? '' : ', [' . implode(', ', $options) . ']';
                $output[] = "{$indent}@startMarker('while', {$idValue}{$optionsSource})";
                $foundKey = $this->findLoopKey($lines, $index);
                $cleanLoopVar = $loopVar ?? '$i';
                $bladeVar = $foundKey !== null ? $this->saoToBladeExpr($foundKey) : $cleanLoopVar;
                $loopScopes[] = [$reactiveId, $bladeVar, ltrim($cleanLoopVar, '$') ?: 'i'];
                $output[] = $rawLine;
                continue;
            }

            if (Re::match('/^\s*@endwhile\b/', $stripped)) {
                if ($reactiveStack !== [] && $reactiveStack[count($reactiveStack) - 1][0] === 'while') {
                    $entry = array_pop($reactiveStack);
                    $this->idGenerator->popScope();
                    if ($loopScopes !== [] && $loopScopes[count($loopScopes) - 1][0] === $entry[1]) {
                        array_pop($loopScopes);
                    }
                    $idValue = $this->bladeIdValue($entry[1], $loopScopes);
                    $output[] = "{$indent}@endwhile";
                    $output[] = "{$indent}@endMarker('while', {$idValue})";
                    continue;
                }
                $output[] = $rawLine;
                continue;
            }

            if (Re::match('/^\s*@for\s*\(/', $stripped)) {
                $expr = $this->extractParensFromDirective($rawLine, '@for');
                $stateKeys = $this->getStateKeys($expr);
                $loopVar = $this->extractForVar($expr);
                $reactiveId = $this->idGenerator->pushReactive('for');
                $idValue = $this->bladeIdValue($reactiveId, $loopScopes);
                $reactiveStack[] = ['for', $reactiveId, $stateKeys, ['loop_var' => $loopVar]];
                if ($stateKeys !== []) {
                    $keysPhp = self::phpArray($stateKeys);
                    $output[] = "{$indent}@startMarker('reactive', {$idValue}, ['stateKey' => {$keysPhp}, 'type' => 'for'])";
                }
                $foundKey = $this->findLoopKey($lines, $index);
                $cleanLoopVar = $loopVar ?? '$i';
                $bladeVar = $foundKey !== null ? $this->saoToBladeExpr($foundKey) : $cleanLoopVar;
                $loopScopes[] = [$reactiveId, $bladeVar, ltrim($cleanLoopVar, '$') ?: 'i'];
                $output[] = $rawLine;
                continue;
            }

            if (Re::match('/^\s*@endfor\b/', $stripped)) {
                if ($reactiveStack !== [] && $reactiveStack[count($reactiveStack) - 1][0] === 'for') {
                    $entry = array_pop($reactiveStack);
                    $this->idGenerator->popScope();
                    if ($loopScopes !== [] && $loopScopes[count($loopScopes) - 1][0] === $entry[1]) {
                        array_pop($loopScopes);
                    }
                    $output[] = "{$indent}@endfor";
                    if ($entry[2] !== []) {
                        $idValue = $this->bladeIdValue($entry[1], $loopScopes);
                        $output[] = "{$indent}@endMarker('reactive', {$idValue})";
                    }
                    continue;
                }
                $output[] = $rawLine;
                continue;
            }

            if (Re::match('/^\s*@switch\s*\(/', $stripped)) {
                $expr = $this->extractParensFromDirective($rawLine, '@switch');
                $stateKeys = $this->getStateKeys($expr);
                $reactiveId = $this->idGenerator->pushReactive('switch');
                $idValue = $this->bladeIdValue($reactiveId, $loopScopes);
                $caseCounters[$reactiveId] = 0;
                $reactiveStack[] = ['switch', $reactiveId, $stateKeys, []];
                $keysPhp = self::phpArray($stateKeys);
                $output[] = "{$indent}@startMarker('reactive', {$idValue}, ['stateKey' => {$keysPhp}, 'type' => 'switch'])";
                $output[] = $rawLine;
                continue;
            }

            if (Re::match('/^\s*@case\s*\(/', $stripped) || Re::match('/^\s*@default\s*$/', $stripped)) {
                if ($reactiveStack !== [] && $reactiveStack[count($reactiveStack) - 1][0] === 'switch') {
                    $reactiveId = $reactiveStack[count($reactiveStack) - 1][1];
                    if (($caseCounters[$reactiveId] ?? 0) > 0) {
                        $this->idGenerator->popScope();
                    }
                    $caseCounters[$reactiveId] = ($caseCounters[$reactiveId] ?? 0) + 1;
                    $this->idGenerator->pushCase($caseCounters[$reactiveId]);
                }
                $output[] = $rawLine;
                continue;
            }

            if (Re::match('/^\s*@endswitch\b/', $stripped)) {
                if ($reactiveStack !== [] && $reactiveStack[count($reactiveStack) - 1][0] === 'switch') {
                    $entry = array_pop($reactiveStack);
                    if (($caseCounters[$entry[1]] ?? 0) > 0) {
                        $this->idGenerator->popScope();
                    }
                    $this->idGenerator->popScope();
                    unset($caseCounters[$entry[1]]);
                    $idValue = $this->bladeIdValue($entry[1], $loopScopes);
                    $output[] = "{$indent}@endswitch";
                    $output[] = "{$indent}@endMarker('reactive', {$idValue})";
                    continue;
                }
                $output[] = $rawLine;
                continue;
            }

            if (Re::match('/^(\s*)@include\s*\(/', $rawLine)) {
                $componentId = $this->idGenerator->nextComponent();
                $idValue = $this->bladeIdValue($componentId, $loopScopes);
                $output[] = "{$indent}@startMarker('component', {$idValue})";
                $output[] = $rawLine;
                $output[] = "{$indent}@endMarker('component', {$idValue})";
                continue;
            }

            if (Re::match('/^(\s*)@importInclude\s*\(/', $rawLine)) {
                $componentId = $this->idGenerator->pushComponent();
                $idValue = $this->bladeIdValue($componentId, $loopScopes);
                $output[] = "{$indent}@startMarker('component', {$idValue})";
                $output[] = $rawLine;
                continue;
            }

            if (Re::match('/^\s*@endImportInclude\b/', $stripped)) {
                $componentId = $this->idGenerator->currentScope()->prefix;
                $idValue = $this->bladeIdValue($componentId, $loopScopes);
                $this->idGenerator->popScope();
                $output[] = $rawLine;
                $output[] = "{$indent}@endMarker('component', {$idValue})";
                continue;
            }

            if (Re::match('/^(\s*)@yield\s*\(/', $rawLine)) {
                $yieldId = $this->idGenerator->nextYield();
                $idValue = $this->bladeIdValue($yieldId, $loopScopes);
                $output[] = "{$indent}@startMarker('yield', {$idValue})";
                $output[] = $rawLine;
                $output[] = "{$indent}@endMarker('yield', {$idValue})";
                continue;
            }

            if (Re::match('/^(\s*)@(?:useBlock|blockOutlet|blockoutlet)\s*\(/', $rawLine)) {
                $outletId = $this->idGenerator->nextBlockOutlet();
                $idValue = $this->bladeIdValue($outletId, $loopScopes);
                $output[] = "{$indent}@startMarker('blockoutlet', {$idValue})";
                $output[] = $rawLine;
                $output[] = "{$indent}@endMarker('blockoutlet', {$idValue})";
                continue;
            }

            $output[] = $this->processHtmlAndOutputs($rawLine, $tagStack, $loopScopes);
        }

        $result = implode("\n", $output);

        foreach ($verbatim as $i => $block) {
            $result = str_replace('__SAO_VERBATIM_' . $i . '__', $block, $result);
        }

        return $result;
    }

    /**
     * @param list<string> $tagStack
     * @param list<array{string, string, string}> $loopScopes
     */
    private function processHtmlAndOutputs(string $line, array &$tagStack, array $loopScopes): string
    {
        $parts = [];
        $pos = 0;
        $length = strlen($line);

        while ($pos < $length) {
            $remaining = substr($line, $pos);

            // Quét theo byte: $pos có thể đang ở giữa một codepoint UTF-8 khi
            // nhánh "regular char" tiến từng byte; thêm /u ở đây sẽ làm PCRE
            // ném malformed UTF-8 trên text tiếng Việt/emoji.
            if (Re::match('~^</\s*([a-zA-Z][\w-]*)\s*>~', $remaining, $close)) {
                $tagName = strtolower($close[1]);
                $parts[] = $close[0];
                $pos += strlen($close[0]);
                if ($tagStack !== [] && $tagStack[count($tagStack) - 1] === $tagName) {
                    array_pop($tagStack);
                    $this->idGenerator->popScope();
                }
                continue;
            }

            if (($open = self::matchOpenTag($remaining)) !== null) {
                $tagName = strtolower($open['tag']);
                $attrsSource = $open['attrs'];
                $slash = $open['slash'];
                $isVoid = isset(self::VOID_ELEMENTS[$tagName]) || $slash !== '';
                if ($isVoid) {
                    $elementId = $this->idGenerator->nextElement($tagName);
                } else {
                    $elementId = $this->idGenerator->pushElement($tagName);
                    $tagStack[] = $tagName;
                }

                [$classes, $regularAttrs, $directiveParts] = $this->parseHtmlAttrs($attrsSource);
                $newAttrs = $this->buildBladeAttrs(
                    $elementId,
                    $loopScopes,
                    $classes,
                    $regularAttrs,
                    $directiveParts,
                );
                $parts[] = '<' . $tagName . ' ' . $newAttrs . ($slash !== '' ? ' /' : '') . '>';
                $pos += $open['length'];
                continue;
            }

            if (Re::match('/^\{!!\s*(.*?)\s*!!\}/s', $remaining, $rawEcho)) {
                $parts[] = $this->markOutput($rawEcho[0], $rawEcho[1], $loopScopes);
                $pos += strlen($rawEcho[0]);
                continue;
            }

            if (substr($line, $pos, 3) === '{{-'
                && Re::match('/^\{\{--.*?--\}\}/s', $remaining, $comment)) {
                $parts[] = $comment[0];
                $pos += strlen($comment[0]);
                continue;
            }

            if (Re::match('/^\{\{(?!--)\s*(.*?)\s*\}\}/s', $remaining, $echo)) {
                $parts[] = $this->markOutput($echo[0], $echo[1], $loopScopes);
                $pos += strlen($echo[0]);
                continue;
            }

            $parts[] = $line[$pos];
            $pos++;
        }

        return implode('', $parts);
    }

    /**
     * @param list<array{string, string, string}> $loopScopes
     */
    private function markOutput(string $full, string $expr, array $loopScopes): string
    {
        if ($this->getStateKeys($expr) === [] && $loopScopes === []) {
            return $full;
        }

        $outputId = $this->idGenerator->nextOutput();
        $idValue = $this->bladeIdValue($outputId, $loopScopes);

        return "@startMarker('output', {$idValue}){$full}@endMarker('output', {$idValue})";
    }

    /**
     * @return array{
     *   list<string>,
     *   list<array{string, ?string, bool}>,
     *   list<string>
     * }
     */
    private function parseHtmlAttrs(string $attrsSource): array
    {
        $classes = [];
        $regularAttrs = [];
        $directiveParts = [];
        $pos = 0;
        $length = strlen($attrsSource);

        while ($pos < $length) {
            while ($pos < $length && self::isWhitespaceAt($attrsSource, $pos)) {
                $pos += self::charLengthAt($attrsSource, $pos);
            }
            if ($pos >= $length) {
                break;
            }

            $remaining = substr($attrsSource, $pos);
            if (Re::match('/^@(\w+)((?:\.\w+)*)\s*\(/u', $remaining, $directive)) {
                // Modifier phải nằm trong match: `\w+` không ăn dấu chấm, nên
                // `@click.stop(...)` từng rơi xuống nhánh boolean-attribute và
                // sinh ra `@attr(['click.stop' => true, 'removeUser' => true])`.
                $directiveName = strtolower($directive[1]);
                $parenStart = $pos + strlen($directive[0]) - 1;
                $content = $this->extractParens($attrsSource, $parenStart);
                if ($content !== null) {
                    $fullLength = $parenStart - $pos + strlen($content) + 2;
                    $isEvent = isset(self::EVENT_NAMES[$directiveName])
                        || (str_starts_with($directiveName, 'on')
                            && isset(self::EVENT_NAMES[substr($directiveName, 2)]));
                    if (! $isEvent && ! isset(self::CSR_ONLY_DIRECTIVES[$directiveName])) {
                        $directiveParts[] = substr($attrsSource, $pos, $fullLength);
                    }
                    $pos += $fullLength;
                } else {
                    $pos += strlen($directive[0]);
                }
                continue;
            }

            if (! Re::match('/^([a-zA-Z_:][\w:.-]*)\s*=\s*"([^"]*)"/u', $remaining, $attribute)
                && ! Re::match("/^([a-zA-Z_:][\\w:.-]*)\\s*=\\s*'([^']*)'/u", $remaining, $attribute)) {
                $attribute = null;
            }

            if ($attribute !== null) {
                $name = $attribute[1];
                $value = $attribute[2];
                if ($name === 'class') {
                    foreach (Re::matchAll('/(?:\{\{.*?\}\}|\{!!.*?!!\}|\S)+/u', $value) as $token) {
                        if ($token[0] !== '') {
                            $classes[] = $token[0];
                        }
                    }
                } else {
                    $regularAttrs[] = [$name, $value, str_contains($value, '{{') || str_contains($value, '{!!')];
                }
                $pos += strlen($attribute[0]);
                continue;
            }

            if (Re::match('/^([a-zA-Z_:][\w:.-]*)/u', $remaining, $boolean)) {
                $name = $boolean[1];
                if (! str_starts_with($name, '@')) {
                    $regularAttrs[] = [$name, null, false];
                }
                $pos += strlen($boolean[0]);
                continue;
            }

            $pos++;
        }

        return [$classes, $regularAttrs, $directiveParts];
    }

    private static function classTokenToPhp(string $token): string
    {
        $segments = Re::split('/(\{\{.*?\}\}|\{!!.*?!!\})/s', $token, -1, PREG_SPLIT_DELIM_CAPTURE);
        $parts = [];

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }
            if (Re::match('/^(?:\{\{\s*(.*?)\s*\}\}|\{!!\s*(.*?)\s*!!\})$/s', $segment, $match)) {
                $expr = isset($match[1]) && $match[1] !== '' ? $match[1] : ($match[2] ?? '');
                $parts[] = '(' . $expr . ')';
            } else {
                $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $segment);
                $parts[] = "'{$escaped}'";
            }
        }

        return $parts === [] ? "''" : implode('.', $parts);
    }

    /**
     * @param list<array{string, string, string}> $loopScopes
     * @param list<string> $classes
     * @param list<array{string, ?string, bool}> $regularAttrs
     * @param list<string> $directiveParts
     */
    private function buildBladeAttrs(
        string $elementId,
        array $loopScopes,
        array $classes,
        array $regularAttrs,
        array $directiveParts,
    ): string {
        $hashedId = HydrateId::hash($elementId, $this->idMode);
        $hydrateClass = $loopScopes !== []
            ? '$__VIEW_ID__ . "-' . $this->injectLoopVars($elementId, $loopScopes) . '"'
            : '$__VIEW_ID__ . \'-' . $hashedId . '\'';

        $allClasses = [$hydrateClass];
        if ($this->scopeClass !== '') {
            $allClasses[] = "'{$this->scopeClass}'";
        }
        foreach ($classes as $class) {
            $allClasses[] = self::classTokenToPhp($class);
        }

        // @class và @attr đều được GOM vào một directive duy nhất.
        //
        // Không gom @attr thì `<div @attr({a:1}) :b="x">` sinh ra HAI directive
        // `@attr(...)` cạnh nhau. Tệ hơn: khi trùng khoá (vd static style="..."
        // gặp :style="...") mảng PHP nuốt mất giá trị trước mà không báo gì.
        $finalDirectiveParts = [];
        $directiveAttrItems = [];

        foreach ($directiveParts as $part) {
            if (str_starts_with($part, '@class')) {
                if (Re::match('/^@class\s*\(\s*\[(.*)\]\s*\)/s', $part, $inner)) {
                    $content = self::pyStrip($inner[1]);
                    if ($content !== '') {
                        $allClasses[] = $content;
                    }
                }
                continue;
            }

            if (str_starts_with($part, '@attr')) {
                if (Re::match('/^@attr\s*\(\s*\[(.*)\]\s*\)/s', $part, $inner)) {
                    foreach (Balanced::splitTopLevelStripped($inner[1], ',') as $item) {
                        if ($item !== '') {
                            $directiveAttrItems[] = $item;
                        }
                    }
                }
                continue;
            }

            $finalDirectiveParts[] = $part;
        }

        $parts = ['@class([' . implode(', ', $allClasses) . '])'];
        if ($regularAttrs !== []) {
            $attrItems = [];
            foreach ($regularAttrs as [$name, $value, $binding]) {
                if ($value === null) {
                    $attrItems[] = "'{$name}' => true";
                } elseif ($binding) {
                    $attrItems[] = "'{$name}' => " . self::bindingAttrPhp($value);
                } else {
                    $attrItems[] = "'{$name}' => '{$value}'";
                }
            }
            $attrItems = array_merge($attrItems, $directiveAttrItems);
            $directiveAttrItems = [];
            $parts[] = '@attr([' . self::joinAttrItems($attrItems) . '])';
        } elseif ($directiveAttrItems !== []) {
            $parts[] = '@attr([' . self::joinAttrItems($directiveAttrItems) . '])';
        }

        array_push($parts, ...$finalDirectiveParts);

        return implode(' ', $parts);
    }


    /**
     * Giá trị attr có `{{ }}` → biểu thức PHP.
     *
     * Bản cũ chỉ BÓC cặp ngoặc rồi ghép thẳng vào văn bản xung quanh, nên
     * `data-m="tr{{ n }}sau"` thành `'data-m' => tr$nsau` — PHP đọc `tr` là
     * hằng chưa định nghĩa (Fatal ở PHP 8) và `$nsau` là biến không tồn tại.
     * `class` vốn đã làm đúng (`'language-'.($lang)`); chỗ này thì chưa.
     *
     * Một biểu thức phủ TRỌN giá trị thì trả thô để GIỮ KIỂU: `:disabled="n<1"`
     * phải ra boolean, không phải chuỗi "false" (xem JsEmitter::wholeInterpolation
     * cho nửa còn lại của bất biến này ở phía JS).
     */
    private static function bindingAttrPhp(string $value): string
    {
        $pattern = '/\{\{\s*(.*?)\s*\}\}|\{!!\s*(.*?)\s*!!\}/s';
        $segments = [];
        $offset = 0;

        while (Re::match($pattern, $value, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $m[0][1];

            if ($start > $offset) {
                $segments[] = ['text', substr($value, $offset, $start - $offset)];
            }

            $segments[] = ['expr', ($m[1][1] ?? -1) >= 0 ? $m[1][0] : ($m[2][0] ?? '')];
            $offset = $start + strlen($m[0][0]);
        }

        if ($offset < strlen($value)) {
            $segments[] = ['text', substr($value, $offset)];
        }

        if (count($segments) === 1 && $segments[0][0] === 'expr') {
            return $segments[0][1];
        }

        $pieces = [];

        foreach ($segments as [$kind, $text]) {
            $pieces[] = $kind === 'expr'
                ? '(' . $text . ')'
                : "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $text) . "'";
        }

        return implode('.', $pieces);
    }

    /**
     * Ghép các mục của @attr, LOẠI KHOÁ TRÙNG.
     *
     * PHP giữ VỊ TRÍ của lần xuất hiện đầu nhưng lấy GIÁ TRỊ của lần cuối, nên
     * `['style'=>'margin:0', 'style'=>$s]` thành `['style'=>$s]` — giá trị đầu
     * biến mất mà output không hề cho thấy điều đó. Ở đây khử trùng ngay lúc
     * biên dịch: hành vi y hệt, nhưng nhìn vào blade là biết cái nào thắng.
     *
     * Mục không nhận ra được khoá (biểu thức động, spread...) thì giữ nguyên,
     * không đụng tới.
     *
     * @param list<string> $items
     */
    private static function joinAttrItems(array $items): string
    {
        $byKey = [];
        $ordered = [];

        foreach ($items as $item) {
            if (! Re::match('/^\s*[\'"]([^\'"]+)[\'"]\s*=>/', $item, $m)) {
                $ordered[] = ['key' => null, 'item' => $item];
                continue;
            }

            $key = $m[1];

            if (isset($byKey[$key])) {
                $ordered[$byKey[$key]]['item'] = $item;   // giữ vị trí đầu, lấy giá trị cuối
                continue;
            }

            $byKey[$key] = count($ordered);
            $ordered[] = ['key' => $key, 'item' => $item];
        }

        return implode(', ', array_map(static fn (array $e): string => $e['item'], $ordered));
    }

    /**
     * Khớp thẻ MỞ ở đầu chuỗi, có đếm ngoặc và tôn trọng nháy.
     *
     * Trước đây dùng regex `~^<(tag)((?:\s+(?:=>|->|[^>'"]|...))*?)\s*(/?)>~`.
     * Regex đó dừng ở dấu '>' đầu tiên nằm ngoài nháy, nên bất kỳ '>' nào bên
     * trong đối số directive đều cắt thẻ sai chỗ:
     *
     *     <div @class({'hot': n > 10})>x</div>
     *       → <div @attr(['hot' => true, 'n' => true])> 10])>x</div>
     *
     * HTML vỡ, điều kiện mất, phần đuôi lọt ra thành text. `=>` và `->` từng
     * được vá riêng bằng nhánh alternation, nhưng '>' trần thì không vá được
     * bằng regex — Python `re` lại không có đệ quy nên hai bản không thể dùng
     * chung một pattern. Quét tay là cách duy nhất giống nhau ở cả hai.
     *
     * @return array{tag: string, attrs: string, slash: string, length: int}|null
     */
    private static function matchOpenTag(string $text): ?array
    {
        if (! Re::match('/^<([a-zA-Z][\w-]*)/', $text, $m)) {
            return null;
        }

        $tag = $m[1];
        $i = strlen($m[0]);
        $length = strlen($text);
        $attrsStart = $i;
        $parenDepth = 0;
        $quote = '';

        while ($i < $length) {
            $ch = $text[$i];

            if ($quote !== '') {
                if ($ch === $quote) {
                    $quote = '';
                }
                $i++;
                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                $i++;
                continue;
            }

            if ($ch === '(') {
                $parenDepth++;
                $i++;
                continue;
            }

            if ($ch === ')') {
                if ($parenDepth > 0) {
                    $parenDepth--;
                }
                $i++;
                continue;
            }

            if ($ch === '>' && $parenDepth === 0) {
                $attrs = substr($text, $attrsStart, $i - $attrsStart);
                $slash = '';

                if (Re::match('~(/)\s*$~', $attrs, $sm)) {
                    $slash = $sm[1];
                    $attrs = substr($attrs, 0, strlen($attrs) - strlen($sm[0]));
                }

                return ['tag' => $tag, 'attrs' => $attrs, 'slash' => $slash, 'length' => $i + 1];
            }

            $i++;
        }

        // Không có '>' đóng trong phần còn lại (vd thẻ trải nhiều dòng — bộ xử
        // lý này chạy theo TỪNG DÒNG nên không ghép được). Trả null để chỗ gọi
        // bỏ qua, giữ nguyên hành vi cũ.
        return null;
    }

    /** @param list<array{string, string, string}> $loopScopes */
    private function bladeIdValue(string $id, array $loopScopes): string
    {
        if ($loopScopes !== []) {
            return '"' . $this->injectLoopVars($id, $loopScopes) . '"';
        }

        return "'" . HydrateId::hash($id, $this->idMode) . "'";
    }

    /** @param list<array{string, string, string}> $loopScopes */
    private function injectLoopVars(string $id, array $loopScopes): string
    {
        $hashedId = HydrateId::hash($id, $this->idMode);
        $dynamic = [];
        foreach ($loopScopes as [, $bladeVar]) {
            $dynamic[] = '{' . $bladeVar . '}';
        }

        return $dynamic === [] ? $hashedId : $hashedId . '-' . implode('-', $dynamic);
    }

    private function saoToBladeExpr(?string $expr): string
    {
        if ($expr === null || $expr === '') {
            return $expr ?? '';
        }

        $result = self::pyStrip($expr);
        $result = Re::replace('/([a-zA-Z_]\w*)\.([a-zA-Z_]\w*)/u', '$1->$2', $result);
        $result = Re::replace('/->([a-zA-Z_]\w*)\.([a-zA-Z_]\w*)/u', '->$1->$2', $result);

        if (Re::match('/^\p{L}/u', $result)
            && ! str_starts_with($result, 'this->')
            && ! Re::match('/^(?:true|false|null|this)\b/', $result)) {
            $result = '$' . $result;
        }

        return $result;
    }

    /** @return list<string> */
    private function getStateKeys(?string $expr): array
    {
        if ($expr === null || $expr === '') {
            return [];
        }

        $found = [];
        foreach (Re::matchAll('/\$?([a-zA-Z_]\w*)/u', $expr) as $match) {
            if (isset($this->stateVariables[$match[1]])) {
                $found[$match[1]] = true;
            }
        }
        $keys = array_keys($found);
        sort($keys, SORT_STRING);

        return $keys;
    }

    /** @param list<string> $items */
    private static function phpArray(array $items): string
    {
        return '[' . implode(', ', array_map(static fn (string $item): string => "'{$item}'", $items)) . ']';
    }

    private function extractParensFromDirective(string $line, string $directive): ?string
    {
        $pattern = '~' . preg_quote($directive, '~') . '\s*\(~';
        if (! Re::match($pattern, $line, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $parenStart = $match[0][1] + strlen($match[0][0]) - 1;

        return $this->extractParens($line, $parenStart);
    }

    private function extractParens(string $text, int $start): ?string
    {
        $length = strlen($text);
        if ($start >= $length || $text[$start] !== '(') {
            return null;
        }

        $depth = 0;
        $quote = null;
        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];
            if ($quote !== null) {
                if ($char === '\\' && $i + 1 < $length) {
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start + 1, $i - $start - 1);
                }
            }
        }

        return null;
    }

    private function extractWhileVar(?string $expr): ?string
    {
        return Re::match('/^\s*\$(\w+)\s*[<>=!]/u', $expr ?? '', $match) ? '$' . $match[1] : null;
    }

    private function extractWhileEnd(?string $expr): ?string
    {
        return Re::match('/[<>]=?\s*(\d+)/', $expr ?? '', $match) ? $match[1] : null;
    }

    private function extractForVar(?string $expr): ?string
    {
        return Re::match('/^\s*\$(\w+)\s*=/u', $expr ?? '', $match) ? '$' . $match[1] : null;
    }

    /**
     * @param list<string> $lines
     */
    private function findLoopKey(array $lines, int $currentIndex): ?string
    {
        $limit = min($currentIndex + 5, count($lines));
        for ($index = $currentIndex + 1; $index < $limit; $index++) {
            $next = self::pyStrip($lines[$index]);
            if ($next === '') {
                continue;
            }
            if (Re::match('/^\s*@key\s*\((.*?)\)\s*$/s', $next, $key)) {
                return self::pyStrip($key[1]);
            }
            if (Re::match('/^\s*@(foreach|while|for)\b/', $next)) {
                break;
            }
        }

        return null;
    }

    private static function pyStrip(string $value): string
    {
        return Re::replace(
            '/^[\p{Z}\x{0009}-\x{000D}\x{001C}-\x{001F}\x{0085}]+|[\p{Z}\x{0009}-\x{000D}\x{001C}-\x{001F}\x{0085}]+$/u',
            '',
            $value,
        );
    }

    private static function isWhitespaceAt(string $value, int $offset): bool
    {
        return Re::match('/\G[\p{Z}\x{0009}-\x{000D}\x{001C}-\x{001F}\x{0085}]/u', $value, $match, 0, $offset);
    }

    private static function charLengthAt(string $value, int $offset): int
    {
        Re::match('/\G./us', $value, $match, 0, $offset);

        return strlen($match[0] ?? $value[$offset]);
    }
}
