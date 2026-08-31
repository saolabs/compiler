<?php

declare(strict_types=1);

namespace Saola\Compiler\Directive;

use Saola\Compiler\Expr\ExpressionCompiler;

/** Port của sao2js/style_directive_handler.py. */
final class StyleDirectiveHandler
{
    /** @var array<string, true> */
    private array $stateVariables = [];

    /** @param iterable<string> $stateVariables */
    public function __construct(iterable $stateVariables = [], private readonly ExpressionCompiler $expressions = new ExpressionCompiler())
    {
        foreach ($stateVariables as $name) {
            $this->stateVariables[$name] = true;
        }
    }

    public function processStyleDirective(string $content): string
    {
        $result = $content;

        while (preg_match('/@style\s*\(/', $result, $match, PREG_OFFSET_CAPTURE) === 1) {
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
                        $replacement = $this->generateStyleOutput($expression);
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

    private function generateStyleOutput(string $expression): string
    {
        $styles = $this->parseStyleExpression($expression);
        if ($styles === []) {
            return '';
        }

        $bindings = [];
        foreach ($styles as [$property, $value]) {
            $bindings[] = "['" . $property . "', " . $this->expressions->compile($value) . ']';
        }

        $watchKeys = $this->formatPythonList($this->extractStateVariables($styles));

        return '${this.__styleBinding(' . $watchKeys . ', [' . implode(', ', $bindings) . '])}';
    }

    /** @return list<array{0: string, 1: string}> */
    private function parseStyleExpression(string $expression): array
    {
        $expression = trim($expression);
        if (str_starts_with($expression, '[') && str_ends_with($expression, ']')) {
            $expression = trim(substr($expression, 1, -1));
        }

        $styles = [];
        foreach ($this->smartSplit($expression, ',') as $part) {
            $part = trim($part);
            if ($part === '' || ! str_contains($part, '=>')) {
                continue;
            }
            [$key, $value] = explode('=>', $part, 2);
            $styles[] = [trim(trim($key), "'\""), trim($value)];
        }

        return $styles;
    }

    /** @return list<string> */
    private function smartSplit(string $text, string $delimiter): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $quote = null;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if (($char === '"' || $char === "'") && ($i === 0 || $text[$i - 1] !== '\\')) {
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
                } elseif ($char === $delimiter && $depth === 0) {
                    $parts[] = $current;
                    $current = '';
                    continue;
                }
            }
            $current .= $char;
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    /** @param list<array{0: string, 1: string}> $styles
     *  @return list<string>
     */
    private function extractStateVariables(array $styles): array
    {
        $found = [];
        foreach ($styles as [, $value]) {
            preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', $value, $matches);
            foreach ($matches[1] as $name) {
                if (isset($this->stateVariables[$name])) {
                    $found[$name] = true;
                }
            }
        }

        return array_keys($found);
    }

    /** @param list<string> $values */
    private function formatPythonList(array $values): string
    {
        return '[' . implode(', ', array_map(static fn (string $value): string => "'" . $value . "'", $values)) . ']';
    }
}
