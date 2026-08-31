<?php

declare(strict_types=1);

namespace Saola\Compiler\Directive;

use Saola\Compiler\Expr\ExpressionCompiler;
use Saola\Compiler\Support\Balanced;

/** Port của sao2js/section_handlers.py. */
final class SectionHandlers
{
    private const JS_FUNCTION_PREFIX = 'App.Helper';

    public function __construct(private readonly ExpressionCompiler $expressions = new ExpressionCompiler())
    {
    }

    /**
     * @param list<array<int, mixed>> $stack
     * @param list<mixed> $output
     * @param list<string> $sections
     */
    public function processSectionDirective(string $line, array &$stack, array &$output, array &$sections): bool
    {
        $open = strpos($line, '(');
        if ($open === false) {
            return false;
        }
        [$content] = Balanced::extractParensAt($line, $open);
        if ($content === null) {
            return false;
        }

        $comma = $this->findFirstComma($content);
        if ($comma !== -1) {
            $nameRaw = trim(substr($content, 0, $comma));
            $valueRaw = trim(substr($content, $comma + 1));
            if (preg_match('/^[\'\"]([^\'\"]*)[\'\"]/', $nameRaw, $match) !== 1) {
                return false;
            }
            $simple = (
                (str_starts_with($valueRaw, "'") && str_ends_with($valueRaw, "'"))
                || (str_starts_with($valueRaw, '"') && str_ends_with($valueRaw, '"'))
            ) && ! str_contains($valueRaw, ' .') && ! str_contains($valueRaw, '. ') && ! str_contains($valueRaw, '$');
            $value = $simple
                ? $this->ensureProperEscaping($valueRaw)
                : ($valueRaw !== '' ? $this->expressions->compileStatement($valueRaw) : "''");
            $result = '${' . self::JS_FUNCTION_PREFIX . '.section(\'' . $match[1] . '\', ' . $value . ", 'string')}";
            $output[] = $result;
            $sections[] = $result;

            return true;
        }

        if (preg_match('/^[\'\"]([^\'\"]*)[\'\"]/', trim($content), $match) === 1) {
            $stack[] = ['section', $match[1], count($output)];
            return true;
        }

        return false;
    }

    /** @param list<array<int, mixed>> $stack
     *  @param list<mixed> $output
     *  @param list<string> $sections
     */
    public function processEndsectionDirective(array &$stack, array &$output, array &$sections): bool
    {
        $last = $stack === [] ? null : $stack[array_key_last($stack)];
        if (is_array($last) && ($last[0] ?? null) === 'section') {
            [, $name, $start] = array_pop($stack);
            $strings = array_values(array_filter(array_slice($output, $start), 'is_string'));
            $content = implode("\n", $strings);
            $output = array_slice($output, 0, $start);
            $type = preg_match('/<[a-zA-Z][^>]*>/', $content) === 1 ? 'html' : 'string';
            $line = '${' . self::JS_FUNCTION_PREFIX . '.section(\'' . $name . '\', `' . $content . '`, \'' . $type . '\')}';
            $output[] = $line;
            $sections[] = $line;
        }

        return true;
    }

    /** @param list<array<int, mixed>> $stack
     *  @param list<mixed> $output
     *  @param list<string> $sections
     */
    public function processBlockDirective(string $line, array &$stack, array &$output, array &$sections): bool
    {
        if (preg_match('/^@block\s*\(\s*[\'\"]([^\'\"]*)[\'\"]\s*,\s*(.*?)\s*\)/', $line, $match) === 1 && str_contains($line, ',')) {
            $attributes = $match[2] !== '' ? $this->expressions->compileStatement($match[2]) : '{}';
            $stack[] = ['block', $match[1], count($output), $attributes];
            return true;
        }

        if (preg_match('/^@block\s*\(\s*[\'\"]([^\'\"]*)[\'\"]|([^)]*)\s*\)/', $line, $match) === 1) {
            $name = ($match[1] ?? '') !== '' ? $match[1] : $this->expressions->compileStatement($match[2] ?? '');
            $stack[] = ['block', $name, count($output), '{}'];
            return true;
        }

        return false;
    }

    /** @param list<array<int, mixed>> $stack
     *  @param list<mixed> $output
     *  @param list<string> $sections
     */
    public function processEndblockDirective(array &$stack, array &$output, array &$sections): bool
    {
        $last = $stack === [] ? null : $stack[array_key_last($stack)];
        if (is_array($last) && ($last[0] ?? null) === 'block') {
            [, $name, $start, $attributes] = array_pop($stack);
            $strings = array_values(array_filter(array_slice($output, $start), 'is_string'));
            $content = implode("\n", $strings);
            $output = array_slice($output, 0, $start);
            $line = '${this.__block(\'' . $name . '\', ' . $attributes . ', `' . $content . '`)}';
            $output[] = $line;
            $sections[] = $line;
        }

        return true;
    }

    private function findFirstComma(string $content): int
    {
        $depth = 0;
        $single = false;
        $double = false;
        $length = strlen($content);
        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];
            if ($char === "'" && ! $double) {
                $single = ! $single;
            } elseif ($char === '"' && ! $single) {
                $double = ! $double;
            } elseif ($char === '(' && ! $single && ! $double) {
                $depth++;
            } elseif ($char === ')' && ! $single && ! $double) {
                $depth--;
            } elseif ($char === ',' && $depth === 0 && ! $single && ! $double) {
                return $i;
            }
        }

        return -1;
    }

    private function ensureProperEscaping(string $value): string
    {
        if (! ((str_starts_with($value, "'") && str_ends_with($value, "'")) || (str_starts_with($value, '"') && str_ends_with($value, '"')))) {
            return $value;
        }
        $quote = $value[0];
        $content = substr($value, 1, -1);
        if ($quote === "'") {
            $content = str_replace("\\'", "'", $content);
            $content = str_replace("'", "\\'", $content);
        } else {
            $content = str_replace('\\"', '"', $content);
            $content = str_replace('"', '\\"', $content);
        }

        return $quote . $content . $quote;
    }
}
