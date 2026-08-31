<?php

declare(strict_types=1);

namespace Saola\Compiler\Emit;

use Saola\Compiler\Declaration\DeclarationTracker;
use Saola\Compiler\Hydration\BladeHydrateProcessor;
use Saola\Compiler\Hydration\IdMode;
use Saola\Compiler\Style\ScopedStyle;
use Saola\Compiler\Support\Balanced;
use Saola\Compiler\Support\PyStr;
use Saola\Compiler\Support\Html;
use Saola\Compiler\Support\Re;
use Saola\Compiler\Template\ChildrenSlot;
use Saola\Compiler\Template\ImportParser;
use Saola\Compiler\Template\ImportTagResolver;
use Saola\Compiler\Template\TemplateStructure;

/** Biên dịch phần Blade SSR của một source `.sao`. */
final class BladeEmitter
{
    private const VIEW_TEMPLATE = <<<'BLADE'
@exec($__ONE_COMPONENT_REGISTRY__ = [ONE_COMPONENT_REGISTRY]) {{-- Khai báo để sử dụng các component đã đăng ký trong $__ONE_COMPONENT_REGISTRY__ --}}

[BLADE_DECLARATIONS]
[BLADE_SSR_CONTENT]
[BLADE_TEMPLATE_CONTENT]
BLADE;

    public function __construct(
        private readonly DeclarationTracker $declarations = new DeclarationTracker(),
        private readonly IdMode $idMode = IdMode::Terse,
    ) {
    }

    /**
     * @param list<string>|null $declarations Danh sách đã tách ở tầng Node, nếu có
     */
    public function compile(
        string $source,
        ?array $declarations = null,
        ?string $ssrContent = null,
        ?string $templateContent = null,
    ): string {
        if ($templateContent !== null) {
            $bladeContent = $templateContent;
            $declarationList = $declarations ?? [];
        } else {
            [$declarationList, $bladeContent, $ssrContent] = $this->parseSource($source);
        }

        $importParser = new ImportParser();
        $componentImports = [];
        foreach ($importParser->parseImports($source) as $tag => $path) {
            $componentImports[$tag] = self::convertPathToPhp($path);
        }

        TemplateStructure::validate($bladeContent, $componentImports);
        $bladeContent = $importParser->removeImports($bladeContent);
        $declarationList = array_values(array_filter(
            $declarationList,
            static fn (string $declaration): bool => ! str_starts_with(trim($declaration), '@import'),
        ));

        ChildrenSlot::validate($bladeContent);
        if (ChildrenSlot::has($bladeContent)) {
            $hasChildrenVariable = false;
            foreach ($declarationList as $declaration) {
                if (str_contains($declaration, ChildrenSlot::DATA_NAME)) {
                    $hasChildrenVariable = true;
                    break;
                }
            }

            if (! $hasChildrenVariable) {
                $merged = false;
                foreach ($declarationList as $index => $declaration) {
                    $stripped = trim($declaration);
                    if (str_starts_with($stripped, '@vars(')) {
                        $inner = substr($stripped, 6, -1);
                        $declarationList[$index] = "@vars($" . ChildrenSlot::DATA_NAME . " = '', {$inner})";
                        $merged = true;
                        break;
                    }
                }
                if (! $merged) {
                    array_unshift($declarationList, "@vars($" . ChildrenSlot::DATA_NAME . " = '')");
                }
            }

            $bladeContent = ChildrenSlot::replaceForBlade($bladeContent);
        }

        if ($componentImports !== []) {
            $bladeContent = (new ImportTagResolver($componentImports, 'blade'))->resolveTags($bladeContent);
        }

        $stateVariables = $this->extractStateVariables($source);
        $hasExtends = Re::match('/@extends\s*\(/', $source);
        $scopeClass = ScopedStyle::classFor(ScopedStyle::extract($source));
        $processed = (new BladeHydrateProcessor($stateVariables, $scopeClass, $this->idMode))
            ->process($bladeContent, $hasExtends);

        if ($componentImports !== []) {
            $counter = 0;
            $processed = $this->resolveImportIncludes($processed, $counter);
        }

        return $this->assemble(
            $declarationList,
            $ssrContent ?? '',
            $processed,
            $componentImports,
            $hasExtends,
        );
    }

    /**
     * Parser fallback của sao2blade khi caller không truyền sẵn parts.
     *
     * @return array{list<string>, string, string}
     */
    private function parseSource(string $source): array
    {
        $contentWithoutSsr = Re::replace(
            '/@(?:serverside|serverSide|ssr|SSR|useSSR|useSsr)\b[\s\S]*?'
            . '@end(?:serverside|serverSide|ServerSide|SSR|Ssr|ssr|useSSR|useSsr)\b/i',
            '',
            $source,
        );
        $wrappers = self::findWrapperSpans($contentWithoutSsr);
        $found = [];

        foreach (['useState', 'states', 'const', 'let', 'var', 'vars', 'props'] as $type) {
            $offset = 0;
            $length = strlen($contentWithoutSsr);
            while ($offset < $length) {
                if (! Re::match('/@' . $type . '\s*\(/i', substr($contentWithoutSsr, $offset), $match, PREG_OFFSET_CAPTURE)) {
                    break;
                }
                $start = $offset + $match[0][1];
                $parenStart = $start + strlen($match[0][0]) - 1;
                [$inner, $end] = Balanced::extractParensAt($contentWithoutSsr, $parenStart);
                $offset = $start + strlen($match[0][0]);
                if ($inner === null) {
                    continue;
                }

                $inside = false;
                foreach ($wrappers as [$wrapperStart, $wrapperEnd]) {
                    if ($wrapperStart <= $start && $start < $wrapperEnd) {
                        $inside = true;
                        break;
                    }
                }
                if (! $inside) {
                    $found[] = ['index' => $start, 'text' => substr($contentWithoutSsr, $start, $end - $start)];
                }
            }
        }

        usort($found, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);
        $declarations = array_map(static fn (array $item): string => $item['text'], $found);

        return [$declarations, $this->extractTemplate($source, $declarations), ''];
    }

    /** @return list<array{int, int}> */
    private static function findWrapperSpans(string $source): array
    {
        $spans = [];
        foreach (['template', 'blade', 'sao:blade'] as $tag) {
            $openTag = "<{$tag}>";
            $closeTag = "</{$tag}>";
            $pos = 0;
            while (true) {
                $open = strpos($source, $openTag, $pos);
                if ($open === false) {
                    break;
                }
                $depth = 1;
                $search = $open + strlen($openTag);
                $close = -1;
                while ($search < strlen($source) && $depth > 0) {
                    $nextOpen = strpos($source, $openTag, $search);
                    $nextClose = strpos($source, $closeTag, $search);
                    if ($nextClose === false) {
                        break;
                    }
                    if ($nextOpen !== false && $nextOpen < $nextClose) {
                        $depth++;
                        $search = $nextOpen + strlen($openTag);
                    } else {
                        $depth--;
                        if ($depth === 0) {
                            $close = $nextClose;
                        }
                        $search = $nextClose + strlen($closeTag);
                    }
                }
                if ($close !== -1) {
                    $spans[] = [$open, $close + strlen($closeTag)];
                    $pos = $close + strlen($closeTag);
                } else {
                    $pos = $open + strlen($openTag);
                }
            }
        }

        return $spans;
    }

    /** @param list<string> $declarations */
    private function extractTemplate(string $source, array $declarations): string
    {
        // Khớp thẻ đóng theo ĐỘ SÂU: `<template>` lồng nhau là HTML hợp lệ,
        // regex non-greedy sẽ cắt ở thẻ đóng TRONG và làm rơi thẻ đóng ngoài.
        foreach (['blade', 'template'] as $wrapperTag) {
            $inner = Html::innerOfFirstTag($source, $wrapperTag);

            if ($inner !== null) {
                return self::pyStrip($inner);
            }
        }

        $template = Re::replace('/<script[\s\S]*?<\/script>/i', '', $source);
        $template = Re::replace('/<style[\s\S]*?<\/style>/i', '', $template);
        foreach ($declarations as $declaration) {
            $template = str_replace($declaration, '', $template);
        }

        return self::pyStrip($template);
    }

    /** @return list<string> */
    private function extractStateVariables(string $source): array
    {
        $states = [];

        foreach ($this->declarations->parseAll($source) as $declaration) {
            if (in_array($declaration->type, ['computed', 'vars', 'props'], true)) {
                foreach ($declaration->variables as $variable) {
                    $name = is_string($variable) ? $variable : ($variable['name'] ?? null);
                    if (is_string($name) && $name !== '') {
                        $states[$name] = true;
                    }
                }
                continue;
            }

            if (in_array($declaration->type, ['useState', 'states', 'const', 'let'], true)) {
                foreach ($declaration->variables as $variable) {
                    if (($variable['isUseState'] ?? false) === true) {
                        foreach (($variable['names'] ?? []) as $name) {
                            if (is_string($name)
                                && $name !== ''
                                && PyStr::isAlnum(str_replace('_', '', $name))) {
                                $states[$name] = true;
                            }
                        }
                    } elseif (in_array($declaration->type, ['useState', 'states'], true)
                        && is_string($variable['name'] ?? null)
                        && $variable['name'] !== '') {
                        $states[$variable['name']] = true;
                    }
                }
            }
        }

        return array_keys($states);
    }

    /**
     * @param list<string> $declarations
     * @param array<string, string> $componentImports
     */
    private function assemble(
        array $declarations,
        string $ssrContent,
        string $template,
        array $componentImports,
        bool $hasExtends,
    ): string {
        $registry = self::buildComponentRegistry($componentImports);
        $declarations = $this->convertDeclarations($declarations);

        if (! $hasExtends) {
            $hasWrapper = Re::match('/@wrapper\b/i', $template);
            if (! $hasWrapper) {
                $template = $this->insertWrapper($template);
            } else {
                $pageStart = self::lineMatch('/^(\s*@pageStart\b.*)/im', $template);
                $pageEnd = self::lineMatch('/^(\s*@pageEnd\b.*)/im', $template);
                $wrapper = self::lineMatch('/^(\s*@wrapper\b)/im', $template);
                $endWrapper = self::lineMatch('/^(\s*@endWrapper\b)/im', $template);
                if ($pageStart !== null && $pageEnd !== null && $wrapper !== null && $endWrapper !== null
                    && $wrapper['start'] < $pageStart['start']) {
                    $template = Re::replace('/^\s*@wrapper\b\s*\n?/im', '', $template, 1);
                    $template = Re::replace('/^\s*@endWrapper\b\s*\n?/im', '', $template, 1);
                    $template = $this->insertWrapper($template);
                }
            }
        }

        // File template Python có newline cuối file; nó là một byte thuộc hợp
        // đồng output nên giữ tường minh thay vì phụ thuộc cú pháp nowdoc.
        $output = str_replace('[ONE_COMPONENT_REGISTRY]', $registry, self::VIEW_TEMPLATE . "\n");
        $declarationBlock = $declarations === [] ? '' : implode("\n", $declarations) . "\n";
        $output = str_replace("[BLADE_DECLARATIONS]\n", $declarationBlock, $output);
        $output = str_replace("[BLADE_SSR_CONTENT]\n", '', $output);

        return str_replace('[BLADE_TEMPLATE_CONTENT]', $template, $output);
    }

    private function insertWrapper(string $template): string
    {
        $pageStart = self::lineMatch('/^(\s*@pageStart\b.*)/im', $template);
        $pageEnd = self::lineMatch('/^(\s*@pageEnd\b.*)/im', $template);
        if ($pageStart === null || $pageEnd === null) {
            return "@wrapper\n{$template}\n@endWrapper";
        }

        return substr($template, 0, $pageStart['end']) . "\n@wrapper"
            . self::pyRStrip(substr($template, $pageStart['end'], $pageEnd['start'] - $pageStart['end']))
            . "\n@endWrapper\n" . substr($template, $pageEnd['start']);
    }

    /** @return array{start: int, end: int}|null */
    private static function lineMatch(string $pattern, string $subject): ?array
    {
        if (! Re::match($pattern, $subject, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        if(!is_array($match)){
            return null;
        }

        return ['start' => $match[0][1], 'end' => $match[0][1] + strlen($match[0][0])];
    }

    /** @param list<string> $declarations @return list<string> */
    private function convertDeclarations(array $declarations): array
    {
        $result = [];
        foreach ($declarations as $declaration) {
            $stripped = trim($declaration);
            if (str_starts_with($stripped, '@props(') && str_ends_with($stripped, ')')) {
                $php = $this->propsToBladePhp($stripped);
                if ($php !== '') {
                    $result[] = $php;
                }
            } elseif (str_starts_with($stripped, '@states(')) {
                array_push($result, ...$this->statesToUseState($stripped));
            } else {
                $result[] = $declaration;
            }
        }

        return $result;
    }

    /** @return list<string> */
    private function statesToUseState(string $declaration): array
    {
        $paren = strpos($declaration, '(');
        $inner = trim(substr($declaration, $paren === false ? 0 : $paren + 1, -1));
        $pairs = str_starts_with($inner, '[') && str_ends_with($inner, ']')
            ? $this->parsePropsArray(trim(substr($inner, 1, -1)))
            : $this->parsePropsStandard($inner);

        return array_map(
            static fn (array $pair): string => '@useState($' . $pair[0] . ', ' . ($pair[1] ?? 'null') . ')',
            $pairs,
        );
    }

    private function propsToBladePhp(string $declaration): string
    {
        $inner = trim(substr($declaration, 7, -1));
        $pairs = str_starts_with($inner, '[') && str_ends_with($inner, ']')
            ? $this->parsePropsArray(trim(substr($inner, 1, -1)))
            : $this->parsePropsStandard($inner);
        $statements = [];
        foreach ($pairs as [$name, $default]) {
            if ($default !== null) {
                $variable = '$' . $name;
                $statements[] = "if(!isset({$variable}) || (!{$variable} && {$variable} !== false)) "
                    . "{$variable} = {$default};";
            }
        }

        return $statements === [] ? '' : '<?php ' . implode(' ', $statements) . ' ?>';
    }

    /** @return list<array{string, ?string}> */
    private function parsePropsArray(string $inner): array
    {
        $pairs = [];
        foreach (self::splitByComma($inner) as $part) {
            $part = trim($part);
            $arrow = strpos($part, '=>');
            if ($arrow !== false) {
                $key = ltrim(trim(trim(substr($part, 0, $arrow)), "'\""), '$');
                $pairs[] = [$key, trim(substr($part, $arrow + 2))];
            } else {
                $name = ltrim(trim(trim($part), "'\""), '$');
                if ($name !== '') {
                    $pairs[] = [$name, null];
                }
            }
        }

        return $pairs;
    }

    /** @return list<array{string, ?string}> */
    private function parsePropsStandard(string $inner): array
    {
        $pairs = [];
        foreach (self::splitByComma($inner) as $part) {
            $part = trim($part);
            $equals = str_contains($part, '=') ? self::findFirstEquals($part) : -1;
            if ($equals !== -1) {
                $pairs[] = [ltrim(trim(substr($part, 0, $equals)), '$'), trim(substr($part, $equals + 1))];
            } else {
                $name = ltrim(trim($part), '$');
                if ($name !== '') {
                    $pairs[] = [$name, null];
                }
            }
        }

        return $pairs;
    }

    /** @return list<string> */
    private static function splitByComma(string $text): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $quote = null;
        for ($index = 0, $length = strlen($text); $index < $length; $index++) {
            $char = $text[$index];
            if ($char === '"' || $char === "'") {
                if ($quote === $char) {
                    $quote = null;
                } elseif ($quote === null) {
                    $quote = $char;
                }
            } elseif ($quote === null) {
                if (str_contains('([{', $char)) {
                    $depth++;
                } elseif (str_contains(')]}', $char)) {
                    $depth--;
                } elseif ($char === ',' && $depth === 0) {
                    $parts[] = trim($current);
                    $current = '';
                    continue;
                }
            }
            $current .= $char;
        }
        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }

    private static function findFirstEquals(string $text): int
    {
        $depth = 0;
        $quote = null;
        for ($index = 0, $length = strlen($text); $index < $length; $index++) {
            $char = $text[$index];
            if ($char === '"' || $char === "'") {
                if ($quote === $char) {
                    $quote = null;
                } elseif ($quote === null) {
                    $quote = $char;
                }
            } elseif ($quote === null) {
                if (str_contains('([{', $char)) {
                    $depth++;
                } elseif (str_contains(')]}', $char)) {
                    $depth--;
                } elseif ($char === '=' && $depth === 0) {
                    if ($index + 1 < $length && str_contains('=>', $text[$index + 1])) {
                        continue;
                    }
                    return $index;
                }
            }
        }

        return -1;
    }

    private static function convertPathToPhp(string $path): string
    {
        $path = trim($path);
        if (str_contains($path, '$') || ! str_contains($path, '+')) {
            return $path;
        }

        $parts = Re::split('/(\'[^\']*\'|"[^"]*")/', $path, -1, PREG_SPLIT_DELIM_CAPTURE);
        for ($index = 0; $index < count($parts); $index += 2) {
            $parts[$index] = str_replace('+', '.', Re::replace(
                '/(?<![\w$])(__\w+__)/',
                '$$$1',
                $parts[$index],
            ));
        }

        return implode('', $parts);
    }

    /** @param array<string, string> $imports */
    private static function buildComponentRegistry(array $imports): string
    {
        if ($imports === []) {
            return '[]';
        }
        $entries = [];
        foreach ($imports as $tag => $path) {
            $entries[] = "'{$tag}' => {$path}";
        }

        return '[' . implode(', ', $entries) . ']';
    }

    private function resolveImportIncludes(string $content, int &$counter): string
    {
        for ($iteration = 0; $iteration < 100; $iteration++) {
            if (! Re::match('/@importInclude\s*\(/', $content, $match, PREG_OFFSET_CAPTURE)) {
                break;
            }
            $matchStart = $match[0][1];
            $parenStart = $matchStart + strlen($match[0][0]) - 1;
            [$argsContent, $argsEnd] = Balanced::extractParensAt($content, $parenStart);
            if ($argsContent === null) {
                break;
            }

            $rest = substr($content, $argsEnd);
            $depth = 1;
            $pos = 0;
            $childrenEnd = -1;
            while ($pos < strlen($rest)) {
                $hasOpen = Re::match('/@importInclude\s*\(/', substr($rest, $pos), $open, PREG_OFFSET_CAPTURE);
                if (! Re::match('/@endImportInclude/', substr($rest, $pos), $close, PREG_OFFSET_CAPTURE)) {
                    break;
                }
                $openPos = $hasOpen ? $pos + $open[0][1] : strlen($rest);
                $closePos = $pos + $close[0][1];
                if ($openPos < $closePos) {
                    $depth++;
                    $pos = $openPos + 1;
                } else {
                    $depth--;
                    if ($depth === 0) {
                        $childrenEnd = $closePos;
                        break;
                    }
                    $pos = $closePos + strlen('@endImportInclude');
                }
            }
            if ($childrenEnd === -1) {
                break;
            }

            $children = self::pyStrip(substr($rest, 0, $childrenEnd));
            $totalEnd = $argsEnd + $childrenEnd + strlen('@endImportInclude');
            $children = $this->resolveImportIncludes($children, $counter);
            [$tagName, $path, $data] = self::parseImportIncludeArgs(self::pyStrip($argsContent));
            $number = $counter++;
            $section = '$__ONE_COMPONENT_REGISTRY__' . "['{$tagName}'].'_{$number}'";
            $safeTag = Re::replace('/\W/u', '_', $tagName);
            $variable = '$__' . $safeTag . "__{$number}_content";
            $parts = [
                '@exec($__env->startSection(' . $section . '))',
                $children,
                '@exec($__env->stopSection())',
                '@exec(' . $variable . ' = $__env->yieldContent(' . $section . '))',
            ];
            if ($data !== null && $data !== '') {
                $trimmedData = self::pyRStrip($data);
                if (str_ends_with($trimmedData, ']')) {
                    $data = substr($trimmedData, 0, -1) . ", '__ONE_CHILDREN_CONTENT__' => {$variable}]";
                }
                $parts[] = "@include({$path}, {$data})";
            } else {
                $parts[] = "@include({$path}, ['__ONE_CHILDREN_CONTENT__' => {$variable}])";
            }

            $content = substr($content, 0, $matchStart)
                . implode("\n", $parts)
                . substr($content, $totalEnd);
        }

        return $content;
    }

    /** @return array{string, string, ?string} */
    private static function parseImportIncludeArgs(string $args): array
    {
        $first = self::findLevelZeroComma($args);
        if ($first < 0) {
            return ['unknown', $args, null];
        }
        $tag = trim(substr($args, 0, $first));
        $remaining = trim(substr($args, $first + 1));
        $second = self::findLevelZeroComma($remaining);

        return $second >= 0
            ? [$tag, trim(substr($remaining, 0, $second)), trim(substr($remaining, $second + 1))]
            : [$tag, $remaining, null];
    }

    private static function findLevelZeroComma(string $text): int
    {
        $paren = 0;
        $bracket = 0;
        $quote = null;
        for ($index = 0, $length = strlen($text); $index < $length; $index++) {
            $char = $text[$index];
            if ($quote !== null) {
                if ($char === $quote && self::precedingBackslashes($text, $index) % 2 === 0) {
                    $quote = null;
                }
                continue;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '(') {
                $paren++;
            } elseif ($char === ')') {
                $paren--;
            } elseif ($char === '[') {
                $bracket++;
            } elseif ($char === ']') {
                $bracket--;
            } elseif ($char === ',' && $paren === 0 && $bracket === 0) {
                return $index;
            }
        }

        return -1;
    }

    private static function precedingBackslashes(string $text, int $position): int
    {
        $count = 0;
        for ($index = $position - 1; $index >= 0 && $text[$index] === '\\'; $index--) {
            $count++;
        }

        return $count;
    }

    private static function pyStrip(string $value): string
    {
        return Re::replace(
            '/^[\p{Z}\x{0009}-\x{000D}\x{001C}-\x{001F}\x{0085}]+|[\p{Z}\x{0009}-\x{000D}\x{001C}-\x{001F}\x{0085}]+$/u',
            '',
            $value,
        );
    }

    private static function pyRStrip(string $value): string
    {
        return Re::replace('/[\p{Z}\x{0009}-\x{000D}\x{001C}-\x{001F}\x{0085}]+$/u', '', $value);
    }
}
