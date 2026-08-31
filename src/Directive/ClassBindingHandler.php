<?php

declare(strict_types=1);

namespace Saola\Compiler\Directive;

use Saola\Compiler\Expr\ExpressionCompiler;

/** Port của sao2js/class_binding_handler.py. */
final class ClassBindingHandler
{
    /** @param iterable<string> $stateVariables */
    public function __construct(iterable $stateVariables = [], private readonly ExpressionCompiler $expressions = new ExpressionCompiler())
    {
        // Python hiện chủ động thu mọi biến trong condition (`or True`), nên
        // tham số này chỉ được giữ để tương thích API và cho lần siết scope sau.
    }

    public function processClassDirective(string $content): string
    {
        $result = $content;
        while (preg_match('/@class\s*\(/', $result, $match, PREG_OFFSET_CAPTURE) === 1) {
            $matchText = $match[0][0];
            $matchStart = $match[0][1];
            $open = $matchStart + strlen($matchText) - 1;
            $depth = 0;
            $closed = false;
            $length = strlen($result);

            for ($i = $open; $i < $length; $i++) {
                if ($result[$i] === '(') {
                    $depth++;
                } elseif ($result[$i] === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $expression = trim(substr($result, $open + 1, $i - $open - 1));
                        $replacement = $this->generateClassOutput($expression);
                        $result = substr($result, 0, $matchStart) . $replacement . substr($result, $i + 1);
                        $closed = true;
                        break;
                    }
                }
            }

            if (! $closed) {
                break;
            }
        }

        return $result;
    }

    /** @param list<array{type: string, value: string, states?: list<string>, checker?: string}> $bindings */
    private function generateOutputFromBindings(array $bindings): string
    {
        $dynamic = false;
        foreach ($bindings as $binding) {
            if ($binding['type'] === 'binding') {
                $dynamic = true;
                break;
            }
        }
        if (! $dynamic) {
            return 'class="' . implode(' ', array_column($bindings, 'value')) . '"';
        }

        $configs = [];
        foreach ($bindings as $binding) {
            if ($binding['type'] === 'static') {
                $configs[] = '{type: "static", value: "' . $binding['value'] . '"}';
                continue;
            }
            $states = implode(', ', array_map(static fn (string $state): string => '"' . $state . '"', $binding['states'] ?? []));
            $configs[] = '{type: "binding", value: "' . $binding['value'] . '", states: [' . $states . '], checker: () => ' . $binding['checker'] . '}';
        }

        return '${this.__classBinding([' . implode(', ', $configs) . '])}';
    }

    private function generateClassOutput(string $expression): string
    {
        return $this->generateOutputFromBindings($this->parseClassExpression($expression));
    }

    /** @return list<array{type: string, value: string, states?: list<string>, checker?: string}> */
    private function parseClassExpression(string $expression): array
    {
        $expression = trim($expression);
        if ($this->isSimpleString($expression)) {
            return [['type' => 'static', 'value' => $this->extractStringValue($expression)]];
        }

        if (str_contains($expression, ',') && ! str_starts_with($expression, '[') && ! str_starts_with($expression, '{')) {
            $parts = $this->splitArrayItems($expression);
            if (count($parts) === 2) {
                $condition = trim($parts[1]);
                return [[
                    'type' => 'binding',
                    'value' => $this->extractStringValue(trim($parts[0])),
                    'states' => $this->extractStateVariables($condition),
                    'checker' => $this->expressions->compile($condition),
                ]];
            }
        }

        if (
            (str_starts_with($expression, '[') && str_ends_with($expression, ']'))
            || (str_starts_with($expression, '{') && str_ends_with($expression, '}'))
        ) {
            return $this->parseArrayExpression($expression);
        }

        return [['type' => 'static', 'value' => $expression]];
    }

    /** @return list<array{type: string, value: string, states?: list<string>, checker?: string}> */
    private function parseArrayExpression(string $expression): array
    {
        $bindings = [];
        foreach ($this->splitArrayItems(trim(substr($expression, 1, -1))) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            [$className, $condition] = $this->splitEntry($item);
            if ($condition === null) {
                $bindings[] = ['type' => 'static', 'value' => $this->extractStringValue($item)];
            } elseif ($this->isSimpleString($condition)) {
                $bindings[] = ['type' => 'static', 'value' => $this->extractStringValue($condition)];
            } else {
                $bindings[] = [
                    'type' => 'binding',
                    'value' => $className,
                    'states' => $this->extractStateVariables($condition),
                    'checker' => $this->expressions->compile($condition),
                ];
            }
        }

        return $bindings;
    }

    /** @return array{0: string, 1: string|null} */
    private function splitEntry(string $item): array
    {
        if (preg_match('~^\s*([\'\"])(.*?)\1\s*(?:=>|:(?!:))\s*(.+)$~su', $item, $match) === 1) {
            return [trim($match[2]), trim($match[3])];
        }
        if (preg_match('~^\s*([A-Za-z_][\w-]*)\s*(?:=>|:(?!:))\s*(.+)$~su', $item, $match) === 1) {
            return [trim($match[1]), trim($match[2])];
        }
        if (str_contains($item, '=>')) {
            [$key, $condition] = explode('=>', $item, 2);
            return [$this->extractStringValue(trim($key)), trim($condition)];
        }

        return [$item, null];
    }

    /** @return list<string> */
    private function splitArrayItems(string $content): array
    {
        $items = [];
        $current = '';
        $depth = 0;
        $quote = null;
        $length = strlen($content);
        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];
            if (($char === '"' || $char === "'") && ($i === 0 || $content[$i - 1] !== '\\')) {
                if ($quote === null) {
                    $quote = $char;
                } elseif ($quote === $char) {
                    $quote = null;
                }
            }
            if ($quote === null) {
                if (str_contains('([{', $char)) {
                    $depth++;
                } elseif (str_contains(')]}', $char)) {
                    $depth--;
                } elseif ($char === ',' && $depth === 0) {
                    $items[] = $current;
                    $current = '';
                    continue;
                }
            }
            $current .= $char;
        }
        if ($current !== '') {
            $items[] = $current;
        }

        return $items;
    }

    private function isSimpleString(string $expression): bool
    {
        $expression = trim($expression);
        return (str_starts_with($expression, "'") && str_ends_with($expression, "'"))
            || (str_starts_with($expression, '"') && str_ends_with($expression, '"'));
    }

    private function extractStringValue(string $expression): string
    {
        $expression = trim($expression);
        return $this->isSimpleString($expression) ? substr($expression, 1, -1) : $expression;
    }

    /** @return list<string> */
    private function extractStateVariables(string $expression): array
    {
        preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', $expression, $matches);
        $variables = array_values(array_unique($matches[1]));
        sort($variables, SORT_STRING);

        return $variables;
    }
}
