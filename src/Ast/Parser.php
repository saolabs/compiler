<?php

declare(strict_types=1);

namespace Saola\Compiler\Ast;

use Saola\Compiler\Support\Html;
use Saola\Compiler\Directive\EventDirectiveProcessor;
use Saola\Compiler\Expr\ExpressionCompiler;
use Saola\Compiler\Support\Re;
use Saola\Compiler\Template\ChildrenSlot;
use Saola\Compiler\Template\ChildrenSlotError;

/** Port byte-oriented của sao2js/template_ast.py::TemplateASTParser. */
final class Parser
{
    /** @var array<string, true> */
    private array $stateVariables = [];

    private ?string $rawtextTag = null;

    private int $childrenPlaceholderCount = 0;

    private readonly EventDirectiveProcessor $eventProcessor;

    private const VOID_ELEMENTS = [
        'area' => true, 'base' => true, 'br' => true, 'col' => true,
        'embed' => true, 'hr' => true, 'img' => true, 'input' => true,
        'link' => true, 'meta' => true, 'param' => true, 'source' => true,
        'track' => true, 'wbr' => true,
    ];

    private const RCDATA_ELEMENTS = ['textarea' => true, 'title' => true];

    private const RAW_CONTENT_ELEMENTS = [
        'textarea' => true, 'title' => true, 'script' => true, 'style' => true,
    ];

    private const EVENT_NAMES = [
        'click', 'dblclick', 'mousedown', 'mouseup', 'mouseover', 'mouseout',
        'mousemove', 'mouseenter', 'mouseleave', 'wheel', 'keydown', 'keyup',
        'keypress', 'input', 'change', 'submit', 'reset', 'invalid', 'focus',
        'blur', 'focusin', 'focusout', 'touchstart', 'touchmove', 'touchend',
        'touchcancel', 'dragstart', 'drag', 'dragend', 'dragenter', 'dragleave',
        'dragover', 'drop', 'scroll', 'resize', 'contextmenu', 'copy', 'cut',
        'paste', 'select', 'load', 'error', 'abort', 'animationstart',
        'animationend', 'animationiteration', 'transitionstart', 'transitionend',
        'transitionrun', 'transitioncancel', 'pointerdown', 'pointerup',
        'pointermove', 'pointerover', 'pointerout', 'pointerenter',
        'pointerleave', 'pointercancel',
    ];

    private const EVENT_MODIFIERS = ['prevent', 'stop', 'self', 'once'];

    private const BOOL_PROP_DIRECTIVES = [
        'checked' => 'checked', 'disabled' => 'disabled', 'selected' => 'selected',
        'readonly' => 'readOnly', 'required' => 'required',
    ];

    private const SKIP_DIRECTIVES = [
        'extends', 'vars', 'useState', 'props', 'states', 'fetch', 'await',
        'oninit', 'endoninit', 'register', 'endregister', 'setup', 'endsetup',
        'script', 'endscript', 'import', 'pageStart', 'pageEnd', 'pageOpen',
        'pageClose', 'docStart', 'docEnd', 'wrapper', 'endWrapper', 'startMarker',
        'endMarker', 'hydrate', 'serverside', 'endserverside', 'ServerSide',
        'endServerSide', 'ssr', 'endssr', 'SSR', 'endSSR', 'clientside',
        'endclientside', 'ClientSide', 'endClientSide', 'csr', 'endcsr', 'CSR',
        'endCSR',
    ];

    /** @param iterable<string> $stateVariables */
    public function __construct(
        iterable $stateVariables = [],
        private readonly ExpressionCompiler $expressions = new ExpressionCompiler(),
    ) {
        foreach ($stateVariables as $name) {
            $this->stateVariables[$name] = true;
        }

        $this->eventProcessor = new EventDirectiveProcessor(array_keys($this->stateVariables), $this->expressions);
    }

    public function parse(string $templateContent): RootNode
    {
        $root = new RootNode();
        $this->rawtextTag = null;
        $this->childrenPlaceholderCount = 0;
        /** @var list<array{Node, string, mixed}> $stack */
        $stack = [[$root, 'root', null]];

        // Ghép thẻ MỞ trải nhiều dòng — PHẢI giống hệt sao2blade, nếu không id
        // hydrate hai bên lệch nhau. Dùng chung Support\Html để không thể lệch.
        $templateContent = Html::joinMultilineOpenTags($templateContent);
        // Directive điều khiển phải đứng riêng dòng: cả hai emitter xử lý
        // theo DÒNG nên nội dung dính cùng dòng sẽ mất (§14).
        $templateContent = Html::splitInlineDirectives($templateContent);

        foreach (explode("\n", $templateContent) as $line) {
            if ($this->rawtextTag !== null) {
                $this->processContentLine($line, $stack);
                continue;
            }

            $stripped = self::pyStrip($line);
            if ($stripped === '') {
                continue;
            }
            if (str_starts_with($stripped, '{{--') && str_ends_with($stripped, '--}}')) {
                continue;
            }
            if ($this->tryDirective($stripped, $stack)) {
                continue;
            }
            $this->processContentLine($stripped, $stack);
        }

        return $root;
    }

    /** @param list<array{Node, string, mixed}> $stack */
    private function tryDirective(string $line, array &$stack): bool
    {
        if (Re::match('/^@section\s*\(/', $line)) {
            $expr = $this->extractDirectiveParens($line, '@section');
            if ($expr !== null) {
                $comma = $this->findFirstComma($expr);
                if ($comma !== -1) {
                    $nameRaw = trim(substr($expr, 0, $comma));
                    $valueRaw = trim(substr($expr, $comma + 1));
                    if (Re::match('/^[\'"]([^\'"]*)[\'"]$/', $nameRaw, $m)) {
                        $this->addChild($stack, new SectionNode(
                            $m[1], $valueRaw,
                            $valueRaw !== '' ? $this->expressions->compileStatement($valueRaw) : "''",
                            'text', $this->getStateVars($valueRaw),
                        ));
                        return true;
                    }
                } elseif (Re::match('/^[\'"]([^\'"]*)[\'"]$/', trim($expr), $m)) {
                    $node = new LongSectionNode($m[1]);
                    $this->addChild($stack, $node);
                    $stack[] = [$node, 'section', null];
                    return true;
                }
            }
        }
        if (Re::match('/^@endsection\b/', $line)) {
            $this->popTo($stack, 'section');
            return true;
        }
        if (Re::match('/^@block\s*\(\s*[\'"](\w+)[\'"]/', $line, $m)) {
            $node = new BlockSection($m[1]);
            $this->addChild($stack, $node);
            $stack[] = [$node, 'block', null];
            return true;
        }
        if (Re::match('/^@end[Bb]lock\b/', $line)) {
            $this->popTo($stack, 'block');
            return true;
        }
        if (Re::match('/^@if\s*\(/', $line)) {
            $expr = $this->extractDirectiveParens($line, '@if');
            if ($expr !== null) {
                $node = new IfBlock();
                $node->stateVars = $this->getStateVars($expr);
                $node->branches[] = [$expr, $this->expressions->compileStatement($expr), []];
                $this->addChild($stack, $node);
                $stack[] = [$node, 'if', null];
                return true;
            }
        }
        if (Re::match('/^@elseif\s*\(/', $line)) {
            $expr = $this->extractDirectiveParens($line, '@elseif');
            if ($expr !== null && ($node = $this->findOnStack($stack, 'if')) instanceof IfBlock) {
                $node->stateVars += $this->getStateVars($expr);
                $node->branches[] = [$expr, $this->expressions->compileStatement($expr), []];
            }
            return true;
        }
        if (Re::match('/^@else\s*$/', $line)) {
            $node = $this->findOnStack($stack, 'if');
            if ($node instanceof IfBlock) {
                $node->branches[] = [null, null, []];
            }
            return true;
        }
        if (Re::match('/^@endif\b/', $line)) {
            $this->popTo($stack, 'if');
            return true;
        }
        if (Re::match('/^@foreach\s*\(/', $line)) {
            $expr = $this->extractDirectiveParens($line, '@foreach');
            if ($expr !== null && Re::match('/^\s*(.*?)\s+as\s+\$?(\w+)(\s*=>\s*\$?(\w+))?\s*$/s', $expr, $m)) {
                $hasKey = ($m[3] ?? '') !== '';
                $node = new ForeachBlock($m[1], $this->expressions->compileStatement($m[1]), $hasKey ? $m[4] : $m[2], $hasKey ? $m[2] : null);
                $node->stateVars = $this->getStateVars($m[1]);
                $this->addChild($stack, $node);
                $stack[] = [$node, 'foreach', null];
                return true;
            }
        }
        if (Re::match('/^@endforeach\b/', $line)) {
            $this->popTo($stack, 'foreach');
            return true;
        }
        if (Re::match('/^@key\s*\(/', $line)) {
            $expr = $this->extractDirectiveParens($line, '@key');
            if ($expr !== null) {
                foreach (array_reverse($stack) as [$node, $type]) {
                    if (in_array($type, ['foreach', 'while', 'for'], true)) {
                        $node->customKey = $expr;
                        $node->customKeyJs = $this->expressions->compileStatement($expr);
                        break;
                    }
                }
            }
            return true;
        }
        if (Re::match('/^@while\s*\(/', $line)) {
            $expr = $this->extractDirectiveParens($line, '@while');
            if ($expr !== null) {
                $node = new WhileBlock($expr, $this->expressions->compileStatement($expr), $this->extractWhileVar($expr), $this->extractWhileEnd($expr));
                $this->addChild($stack, $node);
                $stack[] = [$node, 'while', null];
                return true;
            }
        }
        if (Re::match('/^@endwhile\b/', $line)) {
            $this->popTo($stack, 'while');
            return true;
        }
        if (Re::match('/^@for\s*\(/', $line)) {
            $expr = $this->extractDirectiveParens($line, '@for');
            if ($expr !== null && Re::match('/^\s*\$?(\w+)\s*=\s*(.*?);\s*\$?\1\s*([<>=!]+)\s*(.*?);\s*\$?\1\s*\+\+\s*$/s', $expr, $m)) {
                $node = new ForBlock($m[1], $this->expressions->compileStatement(trim($m[2])), $this->expressions->compileStatement(trim($m[4])), $m[3]);
                $node->stateVars = $this->getStateVars($expr);
                $this->addChild($stack, $node);
                $stack[] = [$node, 'for', null];
                return true;
            }
        }
        if (Re::match('/^@endfor\b/', $line)) {
            $this->popTo($stack, 'for');
            return true;
        }
        if (Re::match('/^@switch\s*\(/', $line)) {
            $expr = $this->extractDirectiveParens($line, '@switch');
            if ($expr !== null) {
                $node = new SwitchBlock($expr, $this->expressions->compileStatement($expr));
                $node->stateVars = $this->getStateVars($expr);
                $this->addChild($stack, $node);
                $stack[] = [$node, 'switch', null];
                return true;
            }
        }
        if (Re::match('/^@case\s*\(/', $line)) {
            $expr = $this->extractDirectiveParens($line, '@case');
            $sw = $this->findOnStack($stack, 'switch');
            if ($expr !== null && $sw instanceof SwitchBlock) {
                if ($stack[array_key_last($stack)][1] === 'case') {
                    array_pop($stack);
                }
                $sw->cases[] = [$this->expressions->compileStatement($expr), []];
                $stack[] = [$sw, 'case', count($sw->cases) - 1];
            }
            return true;
        }
        if (Re::match('/^@default\s*$/', $line)) {
            $sw = $this->findOnStack($stack, 'switch');
            if ($sw instanceof SwitchBlock) {
                if ($stack[array_key_last($stack)][1] === 'case') {
                    array_pop($stack);
                }
                $sw->cases[] = [null, []];
                $stack[] = [$sw, 'case', count($sw->cases) - 1];
            }
            return true;
        }
        if (Re::match('/^@break\b/', $line)) {
            return true;
        }
        if (Re::match('/^@endswitch\b/', $line)) {
            if ($stack[array_key_last($stack)][1] === 'case') {
                array_pop($stack);
            }
            $this->popTo($stack, 'switch');
            return true;
        }
        if (Re::match('/^@(?:useBlock|blockOutlet|blockoutlet)\s*\(\s*[\'"](\w+)[\'"]/', $line, $m)) {
            $this->addChild($stack, new BlockOutlet($m[1]));
            return true;
        }
        if (Re::match('/^@yield\s*\(/', $line)) {
            $expr = $this->extractDirectiveParens($line, '@yield');
            if ($expr !== null) {
                $parts = $this->splitPhpArray($expr);
                $name = trim(trim($parts[0]), "'\"");
                $defaultPhp = isset($parts[1]) ? trim($parts[1]) : null;
                $defaultJs = $defaultPhp !== null ? $this->expressions->compileStatement($defaultPhp) : null;
                if ($defaultPhp !== null && ! str_starts_with($defaultPhp, '$') && $defaultJs !== null && ! str_starts_with($defaultJs, "'") && ! str_starts_with($defaultJs, '"')) {
                    $defaultJs = "'{$defaultJs}'";
                }
                $this->addChild($stack, new YieldNode($name, $defaultPhp, $defaultJs));
                return true;
            }
        }
        if (Re::match('/^@(exec|let|const)\s*\(/', $line, $m)) {
            $expr = $this->extractDirectiveParens($line, '@' . $m[1]);
            if ($expr !== null) {
                $parts = $m[1] === 'exec' ? $this->splitPhpArray($expr) : [$expr];
                $compiled = [];
                foreach ($parts as $part) {
                    if (trim($part) !== '') {
                        $compiled[] = $this->expressions->compileStatement($part);
                    }
                }
                $this->addChild($stack, new ExecNode(implode('; ', $compiled)));
                return true;
            }
        }
        if (Re::match('/^@include\s*\(/', $line)) {
            $expr = $this->extractDirectiveParens($line, '@include');
            if ($expr !== null) {
                [$pathPhp, $dataPhp] = $this->parseIncludeParams($expr);
                $this->addChild($stack, new IncludeNode(
                    $pathPhp, $pathPhp !== '' ? $this->convertPathToJs($pathPhp) : "''",
                    $dataPhp, $dataPhp !== null ? $this->expressions->compileStatement($dataPhp) : null,
                    $dataPhp !== null ? $this->getStateVars($dataPhp) : [],
                ));
                return true;
            }
        }
        if (Re::match('/^@importInclude\s*\(/', $line)) {
            $expr = $this->extractDirectiveParens($line, '@importInclude');
            if ($expr !== null) {
                [$pathPhp, $pathJs, $pairs, $svars] = $this->parseImportIncludeParams($expr);
                $node = new ImportIncludeNode($pathPhp, $pathJs, $pairs, $svars);
                $this->addChild($stack, $node);
                $stack[] = [$node, 'importInclude', null];
                return true;
            }
        }
        if (Re::match('/^@endImportInclude\b/', $line)) {
            $this->popTo($stack, 'importInclude');
            return true;
        }
        if (Re::match('/^@children\b[^\S\r\n]*(?:\([^\S\r\n]*\))?$/i', $line)) {
            $this->addChildrenPlaceholder($stack);
            return true;
        }
        if (Re::match('/^@(\w+)/', $line, $m) && in_array($m[1], self::SKIP_DIRECTIVES, true)) {
            return true;
        }

        return false;
    }

    /** @param list<array{Node, string, mixed}> $stack */
    private function processContentLine(string $line, array &$stack): void
    {
        $pos = 0;
        $length = strlen($line);
        while ($pos < $length) {
            if ($this->rawtextTag !== null) {
                $pos = $this->consumeRawtext($line, $pos, $stack);
                continue;
            }
            if ($pos === 0 && ($line[$pos] === ' ' || $line[$pos] === "\t")) {
                while ($pos < $length && ($line[$pos] === ' ' || $line[$pos] === "\t")) {
                    $pos++;
                }
                if ($pos >= $length) {
                    break;
                }
            }
            if (substr($line, $pos, 4) === '<!--') {
                $end = strpos($line, '-->', $pos + 4);
                if ($end === false) {
                    break;
                }
                $pos = $end + 3;
                continue;
            }
            if (substr($line, $pos, 2) === '<!') {
                $end = strpos($line, '>', $pos + 2);
                if ($end === false) {
                    break;
                }
                $pos = $end + 1;
                continue;
            }
            if (Re::match('/\G<\/\s*([a-zA-Z][\w-]*)\s*>/', $line, $m, 0, $pos)) {
                $this->popHtmlTag($stack, strtolower($m[1]));
                $pos += strlen($m[0]);
                continue;
            }
            if (Re::match('/\G<([a-zA-Z][\w-]*)/', $line, $m, 0, $pos)) {
                $tag = strtolower($m[1]);
                $pos += strlen($m[0]);
                [$attrs, $pos, $selfClosing] = $this->scanTagEnd($line, $pos);
                $void = isset(self::VOID_ELEMENTS[$tag]) || $selfClosing;
                $node = new HtmlElement($tag, $void);
                $this->parseElementAttributes($attrs, $node);
                $this->addChild($stack, $node);
                if (! $void) {
                    $stack[] = [$node, 'html', $tag];
                    if (isset(self::RAW_CONTENT_ELEMENTS[$tag])) {
                        $this->rawtextTag = $tag;
                    }
                }
                continue;
            }
            $nextTag = strpos($line, '<', $pos);
            $nextTag = $nextTag === false ? $length : $nextTag;
            if ($nextTag === $pos) {
                $pos++;
                continue;
            }
            $segment = substr($line, $pos, $nextTag - $pos);
            if (trim($segment) !== '') {
                $this->parseInlineContent($segment, $stack);
            }
            $pos = $nextTag;
        }
    }

    /** @param list<array{Node, string, mixed}> $stack */
    private function consumeRawtext(string $line, int $pos, array &$stack): int
    {
        $tag = $this->rawtextTag;
        if (Re::match('/<\/\s*' . preg_quote((string) $tag, '/') . '\s*>/i', $line, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $start = $m[0][1];
            $this->emitRawtext(substr($line, $pos, $start - $pos), $stack, (string) $tag);
            $this->popHtmlTag($stack, (string) $tag);
            $this->rawtextTag = null;
            return $start + strlen($m[0][0]);
        }
        $this->emitRawtext(substr($line, $pos), $stack, (string) $tag);
        return strlen($line);
    }

    /** @param list<array{Node, string, mixed}> $stack */
    private function emitRawtext(string $raw, array &$stack, string $tag): void
    {
        if ($raw === '' || trim($raw) === '') {
            return;
        }
        if (isset(self::RCDATA_ELEMENTS[$tag])) {
            $this->parseInlineContent($raw, $stack);
        } else {
            $this->addChild($stack, new TextNode($raw));
        }
    }

    /** @param list<array{Node, string, mixed}> $stack */
    private function parseInlineContent(string $content, array &$stack): void
    {
        $pos = 0;
        $length = strlen($content);
        $buffer = '';
        while ($pos < $length) {
            if (substr($content, $pos, 4) === '{{--') {
                $end = strpos($content, '--}}', $pos);
                if ($end !== false) {
                    $this->flushText($buffer, $stack);
                    $pos = $end + 4;
                    continue;
                }
            }
            if (substr($content, $pos, 3) === '{!!' && Re::match('/\G\{!!\s*(.*?)\s*!!\}/s', $content, $m, 0, $pos)) {
                $this->flushText($buffer, $stack);
                $this->addEchoOrChildren($stack, $m[1], false);
                $pos += strlen($m[0]);
                continue;
            }
            if (substr($content, $pos, 2) === '{{' && Re::match('/\G\{\{\s*(.*?)\s*\}\}/s', $content, $m, 0, $pos)) {
                $this->flushText($buffer, $stack);
                $this->addEchoOrChildren($stack, $m[1], true);
                $pos += strlen($m[0]);
                continue;
            }
            if (Re::match('/\G@children\b[^\S\r\n]*(?:\([^\S\r\n]*\))?/i', $content, $m, 0, $pos)) {
                $this->flushText($buffer, $stack);
                $this->addChildrenPlaceholder($stack);
                $pos += strlen($m[0]);
                continue;
            }
            if (Re::match('/\G@if\s*\(/', $content, $m, 0, $pos)) {
                $this->flushText($buffer, $stack);
                $end = $this->parseBlockIfInTextContent($content, $pos, $stack);
                if ($end !== null) {
                    $pos = $end;
                    continue;
                }
            }
            $buffer .= $content[$pos++];
        }
        $this->flushText($buffer, $stack);
    }

    /** @param list<array{Node, string, mixed}> $stack */
    private function flushText(string &$buffer, array &$stack): void
    {
        if ($buffer !== '') {
            $this->addChild($stack, new TextNode($buffer));
            $buffer = '';
        }
    }

    /** @param list<array{Node, string, mixed}> $stack */
    private function addEchoOrChildren(array &$stack, string $expr, bool $escaped): void
    {
        if (ChildrenSlot::isChildrenExpression($expr)) {
            $this->addChildrenPlaceholder($stack);
            return;
        }
        $this->addChild($stack, new EchoNode($expr, $this->expressions->compile($expr), $escaped, $this->getStateVars($expr)));
    }

    /** @param list<array{Node, string, mixed}> $stack */
    private function addChildrenPlaceholder(array &$stack): void
    {
        if (++$this->childrenPlaceholderCount > 1) {
            throw new ChildrenSlotError('A component template may contain only one children placeholder (@children or {{ $children }}).');
        }
        $this->addChild($stack, new ChildrenNode());
    }

    /** @param list<array{Node, string, mixed}> $stack */
    private function parseBlockIfInTextContent(string $content, int $start, array &$stack): ?int
    {
        $open = strpos($content, '(', $start);
        if ($open === false) {
            return null;
        }
        $close = $this->findCloseParen($content, $open);
        $expr = substr($content, $open + 1, $close - $open - 1);
        $depth = 0;
        $cursor = $close + 1;
        $elsePos = null;
        $endifPos = null;
        while ($cursor < strlen($content)) {
            if (Re::match('/\G@if\s*\(/', $content, $m, 0, $cursor)) {
                $nestedOpen = strpos($content, '(', $cursor);
                if ($nestedOpen === false) {
                    return null;
                }
                $depth++;
                $cursor = $this->findCloseParen($content, $nestedOpen) + 1;
                continue;
            }
            if (Re::match('/\G@endif\b/', $content, $m, 0, $cursor)) {
                if ($depth === 0) {
                    $endifPos = $cursor;
                    break;
                }
                $depth--;
                $cursor += 6;
                continue;
            }
            if ($depth === 0 && $elsePos === null && Re::match('/\G@else\b/', $content, $m, 0, $cursor)) {
                $elsePos = $cursor;
                $cursor += 5;
                continue;
            }
            $cursor++;
        }
        if ($endifPos === null) {
            return null;
        }
        $then = substr($content, $close + 1, ($elsePos ?? $endifPos) - $close - 1);
        $else = $elsePos !== null ? substr($content, $elsePos + 5, $endifPos - $elsePos - 5) : null;
        $node = new IfBlock();
        $node->stateVars = $this->getStateVars($expr);
        $node->branches[] = [$expr, $this->expressions->compileStatement($expr), []];
        $branchStack = [[$node, 'if', null]];
        $this->parseInlineContent($then, $branchStack);
        if ($else !== null) {
            $node->branches[] = [null, null, []];
            $this->parseInlineContent($else, $branchStack);
        }
        $this->addChild($stack, $node);
        return $endifPos + 6;
    }

    /** @return array{string, int, bool} */
    private function scanTagEnd(string $line, int $pos): array
    {
        $start = $pos;
        $quote = null;
        $parenDepth = 0;
        $bracketDepth = 0;
        while ($pos < strlen($line)) {
            $char = $line[$pos];
            if (($char === '"' || $char === "'") && ($pos === 0 || $line[$pos - 1] !== '\\')) {
                $quote = $quote === null ? $char : ($quote === $char ? null : $quote);
            } elseif ($quote === null) {
                if ($char === '(') $parenDepth++;
                elseif ($char === ')') $parenDepth--;
                elseif ($char === '[') $bracketDepth++;
                elseif ($char === ']') $bracketDepth--;
                elseif ($char === '>' && $parenDepth === 0 && $bracketDepth === 0) {
                    $attrs = substr($line, $start, $pos - $start);
                    $selfClosing = str_ends_with(rtrim($attrs), '/');
                    if ($selfClosing) $attrs = substr(rtrim($attrs), 0, -1);
                    return [$attrs, $pos + 1, $selfClosing];
                }
            }
            $pos++;
        }
        return [substr($line, $start), $pos, false];
    }

    private function parseElementAttributes(string $attrs, HtmlElement $element): void
    {
        $attrs = trim($attrs);
        $pos = 0;
        while ($pos < strlen($attrs)) {
            while ($pos < strlen($attrs) && str_contains(" \t\n\r", $attrs[$pos])) $pos++;
            if ($pos >= strlen($attrs)) break;
            $remaining = substr($attrs, $pos);
            if (Re::match('/^@(class|attr|style|subscribe|bind|val|transition)\s*\(/', $remaining, $m)) {
                $start = $pos + strlen($m[0]) - 1;
                $content = $this->extractBalanced($attrs, $start);
                if ($content !== null) {
                    match ($m[1]) {
                        'class' => $this->parseClassBinding($content, $element),
                        'attr' => $this->parseAttrBinding($content, $element),
                        'style' => $this->parseStyleBinding($content, $element),
                        'bind', 'val' => $element->bindKey = $this->expressions->compile(trim($content)),
                        'transition' => $this->parseTransition($content, $element),
                        default => null,
                    };
                    $pos = $this->findCloseParen($attrs, $start) + 1;
                    continue;
                }
            }
            if (Re::match('/^@(checked|disabled|selected|readonly|required)\s*\(/i', $remaining, $m)) {
                $start = $pos + strlen($m[0]) - 1;
                $content = $this->extractBalanced($attrs, $start);
                if ($content !== null) {
                    $expr = trim($content);
                    $element->bindingProps[self::BOOL_PROP_DIRECTIVES[strtolower($m[1])]] = ['php' => $expr, 'js' => $this->expressions->compile($expr), 'state_vars' => $this->getStateVars($expr)];
                    $pos = $this->findCloseParen($attrs, $start) + 1;
                    continue;
                }
            }
            if (Re::match('/^@(\w+)((?:\.\w+)*)\s*\(/', $remaining, $m)) {
                $directive = strtolower($m[1]);
                $modifiers = [];
                foreach (array_filter(explode('.', $m[2])) as $modifier) {
                    $modifier = strtolower($modifier);
                    if (in_array($modifier, self::EVENT_MODIFIERS, true)) $modifiers[] = $modifier;
                }
                $event = in_array($directive, self::EVENT_NAMES, true) ? $directive : (str_starts_with($directive, 'on') && in_array(substr($directive, 2), self::EVENT_NAMES, true) ? substr($directive, 2) : null);
                if ($event !== null) {
                    $start = $pos + strlen($m[0]) - 1;
                    $content = $this->extractBalanced($attrs, $start);
                    if ($content !== null) {
                        $element->events[$event] ??= [];
                        array_push($element->events[$event], ...$this->eventProcessor->processEventItems($content));
                        if ($modifiers !== []) {
                            $element->eventModifiers[$event] ??= [];
                            foreach ($modifiers as $modifier) if (! in_array($modifier, $element->eventModifiers[$event], true)) $element->eventModifiers[$event][] = $modifier;
                        }
                        $pos = $this->findCloseParen($attrs, $start) + 1;
                        continue;
                    }
                }
            }
            if (Re::match('/^class\s*=\s*"([^"]*)"/', $remaining, $m) || Re::match("/^class\\s*=\\s*'([^']*)'/", $remaining, $m)) {
                $value = $m[1];
                if (str_contains($value, '{{') || str_contains($value, '{!!')) {
                    $tokens = Re::matchAll('/(?:\{\{.*?\}\}|\{!!.*?!!\}|\S)+/s', $value);
                    foreach ($tokens as $tokenMatch) {
                        $token = $tokenMatch[0];
                        if (str_contains($token, '{{') || str_contains($token, '{!!')) {
                            [$js, $svars] = $this->convertAttrEchoValue($token);
                            $element->dynamicClasses[] = ['php' => $token, 'js' => $js, 'state_vars' => $svars];
                        } else $element->staticClasses[] = $token;
                    }
                } else {
                    array_push($element->staticClasses, ...preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY));
                }
                $pos += strlen($m[0]);
                continue;
            }
            if (Re::match('/^([a-zA-Z_:][\w:.-]*)\s*=\s*"([^"]*)"/', $remaining, $m) || Re::match("/^([a-zA-Z_:][\\w:.-]*)\\s*=\\s*'([^']*)'/", $remaining, $m)) {
                $name = $m[1]; $value = $m[2];
                if (str_starts_with($name, ':') && ! str_starts_with($name, '::')) {
                    $element->bindingAttrs[substr($name, 1)] = ['php' => $value, 'js' => $this->expressions->compile(trim($value)), 'state_vars' => $this->getStateVars($value)];
                } else {
                    if (str_starts_with($name, '::')) $name = substr($name, 1);
                    if (Re::match('/^@yield\s*\(\s*(.*?)\s*\)$/s', $value, $ym)) {
                        $parts = $this->splitPhpArray($ym[1]);
                        $yieldName = trim(trim($parts[0]), "'\"");
                        $defaultPhp = isset($parts[1]) ? trim($parts[1]) : null;
                        $defaultJs = $defaultPhp !== null ? $this->expressions->compileStatement($defaultPhp) : 'null';
                        if ($defaultPhp !== null && ! str_starts_with($defaultPhp, '$') && ! str_starts_with($defaultJs, "'") && ! str_starts_with($defaultJs, '"')) $defaultJs = "'{$defaultJs}'";
                        $element->bindingAttrs[$name] = ['php' => $value, 'js' => "this.yieldContent('{$yieldName}', {$defaultJs})", 'state_vars' => [], 'is_yield' => true, 'yield_name' => $yieldName];
                    } elseif (str_contains($value, '{{') || str_contains($value, '{!!')) {
                        [$js, $svars] = $this->convertAttrEchoValue($value);
                        $element->bindingAttrs[$name] = ['php' => $value, 'js' => $js, 'state_vars' => $svars];
                    } else $element->staticAttrs[$name] = $value;
                }
                $pos += strlen($m[0]);
                continue;
            }
            if (Re::match('/^([a-zA-Z_:][\w:.-]*)\b/', $remaining, $m)) {
                if (! str_starts_with($m[1], '@')) $element->staticAttrs[$m[1]] = true;
                $pos += strlen($m[0]);
                continue;
            }
            $pos++;
        }
    }

    private function parseTransition(string $content, HtmlElement $element): void
    {
        $name = trim(trim($content), "'\"");
        if (Re::match('/^[A-Za-z_][\w-]*$/', $name)) $element->transitionName = $name;
    }

    private function parseClassBinding(string $content, HtmlElement $element): void
    {
        $content = trim($content);
        $entries = ((str_starts_with($content, '[') && str_ends_with($content, ']')) || (str_starts_with($content, '{') && str_ends_with($content, '}')))
            ? $this->splitPhpArray(trim(substr($content, 1, -1))) : [$content];
        foreach ($entries as $entry) {
            $entry = trim($entry);
            if ($entry === '') continue;
            [$name, $condition] = $this->splitClassEntry($entry);
            if ($condition !== null) $element->bindingClasses[$name] = ['php' => $condition, 'js' => $this->expressions->compileStatement($condition), 'state_vars' => $this->getStateVars($condition)];
            else {
                $name = trim(trim($entry), "'\"");
                if ($name !== '') $element->staticClasses[] = $name;
            }
        }
    }

    /** @return array{string, ?string} */
    private function splitClassEntry(string $entry): array
    {
        if (Re::match('/^\s*([\'"])(.*?)\1\s*(?:=>|:(?!:))\s*(.+)$/s', $entry, $m)) return [trim($m[2]), trim($m[3])];
        if (Re::match('/^\s*([A-Za-z_][\w-]*)\s*(?:=>|:(?!:))\s*(.+)$/s', $entry, $m)) return [trim($m[1]), trim($m[2])];
        return [$entry, null];
    }

    private function parseAttrBinding(string $content, HtmlElement $element): void
    {
        $content = trim($content);
        if ((str_starts_with($content, '[') && str_ends_with($content, ']')) || (str_starts_with($content, '{') && str_ends_with($content, '}'))) $content = substr($content, 1, -1);
        foreach ($this->splitPhpArray($content) as $entry) {
            [$name, $value] = $this->splitClassEntry(trim($entry));
            if ($value !== null) $element->bindingAttrs[trim(trim($name), "'\"")] = ['php' => $value, 'js' => $this->expressions->compileStatement($value), 'state_vars' => $this->getStateVars($value)];
        }
    }

    private function parseStyleBinding(string $content, HtmlElement $element): void
    {
        $content = trim($content);
        if ((str_starts_with($content, '[') && str_ends_with($content, ']')) || (str_starts_with($content, '{') && str_ends_with($content, '}'))) $content = substr($content, 1, -1);
        foreach ($this->splitPhpArray($content) as $entry) {
            [$name, $value] = $this->splitClassEntry(trim($entry));
            if ($value !== null) $element->styles[trim(trim($name), "'\"")] = ['php' => $value, 'js' => $this->expressions->compileStatement($value), 'state_vars' => $this->getStateVars($value)];
        }
    }

    /** @return array{string, array<string, true>} */
    private function convertAttrEchoValue(string $value): array
    {
        $state = [];
        foreach (['/\{!!\s*(.*?)\s*!!\}/s', '/\{\{\s*(.*?)\s*\}\}/s'] as $pattern) {
            $value = Re::replaceCallback($pattern, function (array $m) use (&$state): string {
                $expr = trim($m[1]);
                $state += $this->getStateVars($expr);
                return '${' . $this->expressions->compile($expr) . '}';
            }, $value);
        }
        return [$value, $state];
    }

    /** @param list<array{Node, string, mixed}> $stack */
    private function addChild(array &$stack, Node $child): void
    {
        [$parent, $type, $extra] = $stack[array_key_last($stack)];
        if ($type === 'case' && $parent instanceof SwitchBlock) $parent->cases[$extra][1][] = $child;
        elseif ($parent instanceof IfBlock && $parent->branches !== []) $parent->branches[array_key_last($parent->branches)][2][] = $child;
        elseif (property_exists($parent, 'children')) $parent->children[] = $child;
    }

    /** @param list<array{Node, string, mixed}> $stack */
    private function popHtmlTag(array &$stack, string $tag): void
    {
        for ($i = count($stack) - 1; $i > 0; $i--) if ($stack[$i][1] === 'html' && $stack[$i][2] === $tag) { array_splice($stack, $i); return; }
    }

    /** @param list<array{Node, string, mixed}> $stack */
    private function popTo(array &$stack, string $type): void
    {
        while (count($stack) > 1) if (array_pop($stack)[1] === $type) return;
    }

    /** @param list<array{Node, string, mixed}> $stack */
    private function findOnStack(array $stack, string $type): ?Node
    {
        foreach (array_reverse($stack) as $entry) if ($entry[1] === $type) return $entry[0];
        return null;
    }

    /** @return array<string, true> */
    private function getStateVars(?string $expr): array
    {
        if ($expr === null || $expr === '') return [];
        $out = [];
        foreach (Re::matchAll('/\$?([a-zA-Z_][\p{L}\p{N}_]*)/u', $expr) as $m) if (isset($this->stateVariables[$m[1]])) $out[$m[1]] = true;
        return $out;
    }

    private function extractDirectiveParens(string $line, string $directive): ?string
    {
        if (! Re::match('/' . preg_quote($directive, '/') . '\s*\(/', $line, $m, PREG_OFFSET_CAPTURE)) return null;
        return $this->extractBalanced($line, $m[0][1] + strlen($m[0][0]) - 1);
    }

    private function findFirstComma(string $content): int
    {
        $depth = 0; $single = false; $double = false;
        for ($i = 0; $i < strlen($content); $i++) {
            $ch = $content[$i];
            if ($ch === "'" && ! $double) $single = ! $single;
            elseif ($ch === '"' && ! $single) $double = ! $double;
            elseif ($ch === '(' && ! $single && ! $double) $depth++;
            elseif ($ch === ')' && ! $single && ! $double) $depth--;
            elseif ($ch === ',' && $depth === 0 && ! $single && ! $double) return $i;
        }
        return -1;
    }

    private function extractBalanced(string $text, int $start): ?string
    {
        if (($text[$start] ?? null) !== '(') return null;
        $depth = 0; $quote = null;
        for ($i = $start; $i < strlen($text); $i++) {
            $ch = $text[$i];
            if ($quote !== null) {
                if ($ch === '\\' && $i + 1 < strlen($text)) { $i++; continue; }
                if ($ch === $quote) $quote = null;
            } elseif ($ch === "'" || $ch === '"') $quote = $ch;
            elseif ($ch === '(') $depth++;
            elseif ($ch === ')' && --$depth === 0) return substr($text, $start + 1, $i - $start - 1);
        }
        return null;
    }

    private function findCloseParen(string $text, int $start): int
    {
        if (($text[$start] ?? null) !== '(') return strlen($text) - 1;
        $depth = 0; $quote = null;
        for ($i = $start; $i < strlen($text); $i++) {
            $ch = $text[$i];
            if ($quote !== null) {
                if ($ch === '\\' && $i + 1 < strlen($text)) { $i++; continue; }
                if ($ch === $quote) $quote = null;
            } elseif ($ch === "'" || $ch === '"') $quote = $ch;
            elseif ($ch === '(') $depth++;
            elseif ($ch === ')' && --$depth === 0) return $i;
        }
        return strlen($text) - 1;
    }

    /** @return list<string> */
    private function splitPhpArray(string $content): array
    {
        $out = []; $current = ''; $depth = 0; $paren = 0; $quote = null;
        for ($i = 0; $i < strlen($content); $i++) {
            $ch = $content[$i];
            if ($quote === null && ($ch === "'" || $ch === '"')) $quote = $ch;
            elseif ($quote !== null && $ch === $quote) $quote = null;
            if ($quote === null) {
                if ($ch === '[' || $ch === '{') $depth++;
                elseif ($ch === ']' || $ch === '}') $depth--;
                elseif ($ch === '(') $paren++;
                elseif ($ch === ')') $paren--;
                elseif ($ch === ',' && $depth === 0 && $paren === 0) { $out[] = $current; $current = ''; continue; }
            }
            $current .= $ch;
        }
        if (trim($current) !== '') $out[] = $current;
        return $out;
    }

    /** @return array{string, ?string} */
    private function parseIncludeParams(string $expr): array
    {
        $parts = $this->splitPhpArray($expr);
        return count($parts) >= 2 ? [trim($parts[0]), trim($parts[1])] : [trim($expr), null];
    }

    private function convertPathToJs(string $path): string
    {
        $path = trim($path);
        if ((str_starts_with($path, "'") && str_ends_with($path, "'") && substr_count($path, "'") === 2) || (str_starts_with($path, '"') && str_ends_with($path, '"') && substr_count($path, '"') === 2)) return $path;
        $js = str_contains($path, '+') && ! str_contains($path, '$') && ! str_contains($path, '->') ? $path : $this->expressions->compileStatement($path);
        if (Re::match('/^[a-zA-Z_][\w.]*$/', $js) && str_contains($js, '.')) return "'{$js}'";
        if (Re::match('/^[a-zA-Z_]\w*$/', $js) && ! str_starts_with($js, '__')) return "'{$js}'";
        return $js;
    }

    /** @return array{string, string, list<array{string, string}>, array<string, true>} */
    private function parseImportIncludeParams(string $expr): array
    {
        $parts = $this->splitPhpArray($expr);
        if ($parts === []) return [trim($expr), "''", [], []];
        $path = trim(count($parts) === 1 ? $parts[0] : $parts[1]);
        $pairs = []; $state = [];
        if (isset($parts[2])) {
            $data = trim($parts[2]);
            if (str_starts_with($data, '[') && str_ends_with($data, ']')) $data = trim(substr($data, 1, -1));
            foreach ($this->splitPhpArray($data) as $entry) if (Re::match('/^[\'"]([^\'"]+)[\'"]\s*=>\s*(.+)$/s', trim($entry), $m)) {
                $value = trim($m[2]);
                $pairs[] = [$m[1], $this->expressions->compileStatement($value)];
                $state += $this->getStateVars($value);
            }
        }
        return [$path, $this->convertPathToJs($path), $pairs, $state];
    }

    private function extractWhileVar(string $expr): ?string
    {
        return Re::match('/^\s*\$(\w+)\s*[<>=!]/', $expr, $m) ? $m[1] : null;
    }

    private function extractWhileEnd(string $expr): ?string
    {
        return Re::match('/[<>]=?\s*(\d+)/', $expr, $m) ? $m[1] : null;
    }

    private static function pyStrip(string $value): string
    {
        return Re::replace('/^\s+|\s+$/u', '', $value);
    }
}
