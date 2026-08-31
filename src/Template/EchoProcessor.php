<?php

declare(strict_types=1);

namespace Saola\Compiler\Template;

use Saola\Compiler\Expr\ExpressionCompiler;
use Saola\Compiler\Support\Balanced;

/** Port byte-oriented của sao2js/echo_processor.py. */
final class EchoProcessor
{
    private const APP_HELPER_NAMESPACE = 'App.Helper';

    /** @var array<string, true> */
    private array $stateVariables = [];

    private int $reactiveCounter = 0;

    /** @param iterable<string> $stateVariables */
    public function __construct(
        iterable $stateVariables = [],
        private readonly bool $isTypescript = false,
        private readonly ?object $processor = null,
        private readonly ExpressionCompiler $expressions = new ExpressionCompiler(),
    ) {
        foreach ($stateVariables as $name) {
            $this->stateVariables[$name] = true;
        }
    }

    public function processEchoExpressions(string $templateContent): string
    {
        return $this->processEchoInContent($this->processEchoInAttributes($templateContent));
    }

    private function processEchoInAttributes(string $content): string
    {
        $result = '';
        $position = 0;
        $length = strlen($content);

        while ($position < $length) {
            $lessThan = strpos($content, '<', $position);
            if ($lessThan === false) {
                $result .= substr($content, $position);
                break;
            }
            $result .= substr($content, $position, $lessThan - $position);
            if ($lessThan + 1 >= $length) {
                $result .= substr($content, $lessThan);
                break;
            }

            $tagStart = $lessThan + 1;
            if (preg_match('/^([a-zA-Z][a-zA-Z0-9]*)/', substr($content, $tagStart), $tagMatch) !== 1) {
                $result .= '<';
                $position = $lessThan + 1;
                continue;
            }

            $tagName = $tagMatch[1];
            $attributesStart = $tagStart + strlen($tagName);
            $greaterThan = $this->findTagEnd($content, $attributesStart);
            if ($greaterThan === -1) {
                $result .= substr($content, $lessThan);
                break;
            }

            $selfClosing = '';
            $actualEnd = $greaterThan;
            if ($greaterThan > 0 && $content[$greaterThan - 1] === '/') {
                $selfClosing = '/';
                $actualEnd--;
            }
            $attributes = substr($content, $attributesStart, $actualEnd - $attributesStart);
            $result .= $this->processSingleTag($tagName, $attributes, $selfClosing);
            $position = $greaterThan + 1;
        }

        return $result;
    }

    private function findTagEnd(string $content, int $start): int
    {
        $quote = null;
        $parenDepth = 0;
        $bracketDepth = 0;
        $length = strlen($content);
        for ($i = $start; $i < $length; $i++) {
            $char = $content[$i];
            if (($char === '"' || $char === "'") && ($i === 0 || $content[$i - 1] !== '\\')) {
                if ($quote === null) {
                    $quote = $char;
                } elseif ($quote === $char) {
                    $quote = null;
                }
            } elseif ($quote === null) {
                if ($char === '(') {
                    $parenDepth++;
                } elseif ($char === ')') {
                    $parenDepth--;
                } elseif ($char === '[') {
                    $bracketDepth++;
                } elseif ($char === ']') {
                    $bracketDepth--;
                } elseif ($char === '>' && $parenDepth === 0 && $bracketDepth === 0) {
                    return $i;
                }
            }
        }

        return -1;
    }

    private function processSingleTag(string $tagName, string $attributes, string $selfClosing): string
    {
        /** @var array<string, array{expressions: list<array{type: string, php: string, js: string, vars: list<string>}>, state_vars: list<string>, original_value: string}> $booleanAttrs */
        $booleanAttrs = [];
        foreach (['checked', 'selected'] as $directive) {
            $attributes = preg_replace_callback(
                '/@' . $directive . '\s*\(\s*(.*?)\s*\)/s',
                function (array $match) use ($directive, &$booleanAttrs): string {
                    $expression = trim($match[1]);
                    $js = $this->expressions->compile($expression);
                    $variables = $this->extractVariables($expression);
                    $stateVars = $this->intersectStateVariables($variables);
                    if ($stateVars !== []) {
                        $booleanAttrs[$directive] = [
                            'expressions' => [['type' => $directive, 'php' => $expression, 'js' => $js, 'vars' => $variables]],
                            'state_vars' => $stateVars,
                            'original_value' => $expression,
                        ];
                        return '';
                    }

                    return '${(' . $js . ') ? " ' . $directive . '" : ""}';
                },
                $attributes,
            ) ?? $attributes;
        }

        $echoAttrs = [];
        $hasEcho = $booleanAttrs !== [];
        $pattern = '/([a-zA-Z][a-zA-Z0-9_-]*)\s*=\s*["\']([^"\']*(?:\{\{[^}]*\}\}|\{!![^!]*!!\})[^"\']*)["\']/';
        $newAttributes = preg_replace_callback(
            $pattern,
            function (array $match) use (&$echoAttrs, &$hasEcho): string {
                $name = $match[1];
                $value = $match[2];
                if (! str_contains($value, '{{') && ! str_contains($value, '{!!')) {
                    return $match[0];
                }
                $hasEcho = true;
                $expressions = [];
                $used = [];

                preg_match_all('/\{\{([^}]+)\}\}/', $value, $escaped, PREG_SET_ORDER);
                foreach ($escaped as $echo) {
                    $php = trim($echo[1]);
                    $vars = $this->extractVariables($php);
                    foreach ($vars as $variable) {
                        $used[$variable] = true;
                    }
                    $expressions[] = ['type' => 'escaped', 'php' => $php, 'js' => $this->expressions->compile($php), 'vars' => $vars];
                }
                preg_match_all('/\{!!([^!]+)!!\}/', $value, $raw, PREG_SET_ORDER);
                foreach ($raw as $echo) {
                    $php = trim($echo[1]);
                    $vars = $this->extractVariables($php);
                    foreach ($vars as $variable) {
                        $used[$variable] = true;
                    }
                    $expressions[] = ['type' => 'unescaped', 'php' => $php, 'js' => $this->expressions->compile($php), 'vars' => $vars];
                }

                $stateVars = $this->intersectStateVariables(array_keys($used));
                if ($stateVars !== []) {
                    $echoAttrs[$name] = ['expressions' => $expressions, 'state_vars' => $stateVars, 'original_value' => $value];
                    return '';
                }

                $processed = $value;
                foreach ($expressions as $info) {
                    $replacement = $info['type'] === 'escaped'
                        ? '${' . self::APP_HELPER_NAMESPACE . '.escString(' . $info['js'] . ')}'
                        : '${' . $info['js'] . '}';
                    $needle = $info['type'] === 'escaped'
                        ? '{{' . $info['php'] . '}}'
                        : '{!!' . $info['php'] . '!!}';
                    $processed = str_replace($needle, $replacement, $processed);
                }

                return $name . '="' . $processed . '"';
            },
            $attributes,
        ) ?? $attributes;

        foreach ($booleanAttrs as $name => $info) {
            $echoAttrs[$name] = $info;
        }

        if ($hasEcho && $echoAttrs !== []) {
            if (preg_match('/@attr\s*\(/', $newAttributes, $attrMatch, PREG_OFFSET_CAPTURE) === 1) {
                $matchText = $attrMatch[0][0];
                $matchStart = $attrMatch[0][1];
                $open = $matchStart + strlen($matchText) - 1;
                [$params, $end] = Balanced::extractParensAt($newAttributes, $open);
                if ($params !== null) {
                    $merged = $this->mergeAttrDirectives($params, $echoAttrs);
                    $newAttributes = substr($newAttributes, 0, $matchStart)
                        . '${this.__attr(' . $merged . ')}'
                        . substr($newAttributes, $end);
                }
            } else {
                $newAttributes .= ' ' . $this->generateAttrDirective($echoAttrs);
            }
        }

        $newAttributes = trim(preg_replace('/\s+/', ' ', $newAttributes) ?? $newAttributes);
        if ($newAttributes !== '') {
            $newAttributes = ' ' . $newAttributes;
        }

        return '<' . $tagName . $newAttributes . $selfClosing . '>';
    }

    private function processEchoInContent(string $content): string
    {
        $content = $this->replaceContentEcho($content, '/\{!!([^!]+)!!\}/', false);

        return $this->replaceContentEcho($content, '/\{\{([^}]+)\}\}/', true);
    }

    private function replaceContentEcho(string $content, string $pattern, bool $escaped): string
    {
        $result = '';
        $offset = 0;
        $length = strlen($content);
        while ($offset < $length && preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $full = $match[0][0];
            $start = $match[0][1];
            $expression = trim($match[1][0]);
            $js = $this->expressions->compile($expression);
            $stateVars = $this->intersectStateVariables($this->extractVariables($expression));
            $result .= substr($content, $offset, $start - $offset);

            if ($this->isStandaloneTagAttribute($content, $start)) {
                $replacement = $escaped
                    ? '${' . self::APP_HELPER_NAMESPACE . '.escString(' . $js . ')}'
                    : '${' . $js . '}';
            } elseif ($stateVars !== []) {
                $rcParam = $this->isTypescript ? '(__rc__: any)' : '(__rc__)';
                $rcId = '__RC_OUTPUT_PH_' . $this->nextReactiveId() . '__';
                $replacement = '${this.__reactive(\'output\', __rc__, ' . $rcId . ', '
                    . $this->formatPythonList($stateVars) . ', ' . $rcParam . ' => ' . $js
                    . ", {type: 'output', escapeHTML: " . ($escaped ? 'true' : 'false') . '})}';
            } else {
                $replacement = $escaped
                    ? '${' . self::APP_HELPER_NAMESPACE . '.escString(' . $js . ')}'
                    : '${' . $js . '}';
            }

            $result .= $replacement;
            $offset = $start + strlen($full);
        }

        return $result . substr($content, $offset);
    }

    private function isStandaloneTagAttribute(string $content, int $position): bool
    {
        $tagStart = strrpos(substr($content, 0, $position), '<');
        if ($tagStart === false || strpos($content, '>', $position) === false) {
            return false;
        }
        $intermediate = strrpos(substr($content, $tagStart, $position - $tagStart), '>');
        if ($intermediate !== false) {
            return false;
        }
        $tagContent = substr($content, $tagStart, $position - $tagStart);

        return substr_count($tagContent, '"') % 2 === 0 && substr_count($tagContent, "'") % 2 === 0;
    }

    /** @return list<string> */
    private function extractVariables(string $expression): array
    {
        preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', $expression, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @param list<string> $variables
     *  @return list<string>
     */
    private function intersectStateVariables(array $variables): array
    {
        $result = [];
        foreach ($variables as $variable) {
            if (isset($this->stateVariables[$variable])) {
                $result[$variable] = true;
            }
        }

        return array_keys($result);
    }

    /**
     * @param array<string, array{expressions: list<array{type: string, php: string, js: string, vars: list<string>}>, state_vars: list<string>, original_value: string}> $echoAttrs
     */
    private function generateAttrDirective(array $echoAttrs): string
    {
        return '${this.__attr(' . $this->buildAttrObject($echoAttrs) . ')}';
    }

    /**
     * @param array<string, array{expressions: list<array{type: string, php: string, js: string, vars: list<string>}>, state_vars: list<string>, original_value: string}> $echoAttrs
     */
    private function mergeAttrDirectives(string $existingParams, array $echoAttrs): string
    {
        /** @var array<string, array{states: list<string>, render: string}> $merged */
        $merged = [];
        $existingParams = trim($existingParams);
        if (str_starts_with($existingParams, '[') && str_ends_with($existingParams, ']')) {
            foreach ($this->splitAttrPairs(trim(substr($existingParams, 1, -1))) as $pair) {
                if (! str_contains($pair, '=>')) {
                    continue;
                }
                [$name, $value] = explode('=>', $pair, 2);
                $name = trim(trim($name), "'\"");
                $value = trim($value);
                $states = $this->intersectStateVariables($this->extractVariables($value));
                if ($states !== []) {
                    $merged[$name] = ['states' => $states, 'render' => '() => ' . $this->expressions->compile($value)];
                }
            }
        } else {
            $parts = $this->splitAttrPairs($existingParams);
            if (count($parts) >= 2) {
                $name = trim(trim($parts[0]), "'\"");
                $value = trim(implode(',', array_slice($parts, 1)));
                $states = $this->intersectStateVariables($this->extractVariables($value));
                if ($states !== []) {
                    $merged[$name] = ['states' => $states, 'render' => '() => ' . $this->expressions->compile($value)];
                }
            }
        }

        foreach ($this->attrConfigs($echoAttrs) as $name => $config) {
            $merged[$name] = $config;
        }

        return $this->formatAttrObject($merged);
    }

    /**
     * @param array<string, array{expressions: list<array{type: string, php: string, js: string, vars: list<string>}>, state_vars: list<string>, original_value: string}> $echoAttrs
     */
    private function buildAttrObject(array $echoAttrs): string
    {
        return $this->formatAttrObject($this->attrConfigs($echoAttrs));
    }

    /**
     * @param array<string, array{expressions: list<array{type: string, php: string, js: string, vars: list<string>}>, state_vars: list<string>, original_value: string}> $echoAttrs
     * @return array<string, array{states: list<string>, render: string}>
     */
    private function attrConfigs(array $echoAttrs): array
    {
        $configs = [];
        foreach ($echoAttrs as $name => $info) {
            $first = $info['expressions'][0] ?? null;
            $boolean = count($info['expressions']) === 1
                && $first !== null
                && in_array($first['type'], ['checked', 'selected'], true);
            if ($boolean) {
                $render = '() => (' . $first['js'] . ') ? true : false';
            } else {
                $js = $info['original_value'];
                foreach ($info['expressions'] as $expression) {
                    $phpPattern = preg_quote($expression['php'], '~');
                    $pattern = $expression['type'] === 'escaped'
                        ? '~\{\{\s*' . $phpPattern . '\s*\}\}~'
                        : '~\{!!\s*' . $phpPattern . '\s*!!\}~';
                    $js = preg_replace($pattern, '${(' . $expression['js'] . ')}', $js) ?? $js;
                }
                if (preg_match('/^\$\{\(([^)]+)\)\}$/', $js, $single) === 1) {
                    $render = '() => ' . $single[1];
                } else {
                    $render = '() => `' . $js . '`';
                }
            }
            $configs[$name] = ['states' => $info['state_vars'], 'render' => $render];
        }

        return $configs;
    }

    /** @param array<string, array{states: list<string>, render: string}> $configs */
    private function formatAttrObject(array $configs): string
    {
        $parts = [];
        foreach ($configs as $name => $config) {
            $parts[] = '"' . $name . '": {states: ' . $this->formatDoubleList($config['states']) . ', render: ' . $config['render'] . '}';
        }

        return '{' . implode(', ', $parts) . '}';
    }

    /** @return list<string> */
    private function splitAttrPairs(string $content): array
    {
        $pairs = [];
        $current = '';
        $parenDepth = 0;
        $bracketDepth = 0;
        $quote = null;
        $length = strlen($content);
        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];
            if ($quote === null) {
                if ($char === '"' || $char === "'") {
                    $quote = $char;
                    $current .= $char;
                } elseif ($char === '(') {
                    $parenDepth++;
                    $current .= $char;
                } elseif ($char === ')') {
                    $parenDepth--;
                    $current .= $char;
                } elseif ($char === '[') {
                    $bracketDepth++;
                    $current .= $char;
                } elseif ($char === ']') {
                    $bracketDepth--;
                    $current .= $char;
                } elseif ($char === ',' && $parenDepth === 0 && $bracketDepth === 0) {
                    if (trim($current) !== '') {
                        $pairs[] = trim($current);
                    }
                    $current = '';
                } else {
                    $current .= $char;
                }
            } else {
                $current .= $char;
                if ($char === $quote && ($i === 0 || $content[$i - 1] !== '\\')) {
                    $quote = null;
                }
            }
        }
        if (trim($current) !== '') {
            $pairs[] = trim($current);
        }

        return $pairs;
    }

    /** @param list<string> $values */
    private function formatPythonList(array $values): string
    {
        return '[' . implode(', ', array_map(static fn (string $value): string => "'" . $value . "'", $values)) . ']';
    }

    /** @param list<string> $values */
    private function formatDoubleList(array $values): string
    {
        return '[' . implode(', ', array_map(static fn (string $value): string => '"' . $value . '"', $values)) . ']';
    }

    private function nextReactiveId(): int
    {
        if ($this->processor !== null && property_exists($this->processor, 'reactiveCounter')) {
            $this->processor->reactiveCounter++;
            return $this->processor->reactiveCounter;
        }

        return ++$this->reactiveCounter;
    }
}
