<?php

declare(strict_types=1);

namespace Saola\Compiler\Directive;

use Saola\Compiler\Expr\ExpressionCompiler;
use Saola\Compiler\Support\Balanced;

/** Port của sao2js/directive_processors.py (file active hiện tại). */
final class DirectiveProcessor
{
    private const HELPER = 'App.Helper';

    public function __construct(private readonly ExpressionCompiler $expressions = new ExpressionCompiler())
    {
    }

    public function processAuthDirective(string $line): ?string
    {
        if (str_starts_with($line, '@auth')) return '${App.Helper.execute(() => {' . "\n    if(App.Helper.isAuth()){\n        return `";
        if (str_starts_with($line, '@guest')) return '${App.Helper.execute(() => {' . "\n    if(!App.Helper.isAuth()){\n        return `";
        return null;
    }

    public function processEndauthDirective(string $line): ?string
    {
        return str_starts_with($line, '@endauth') || str_starts_with($line, '@endguest') ? "`\n    }\n})}" : null;
    }

    public function processCanDirective(string $line): ?string
    {
        if (str_starts_with($line, '@cannot') && preg_match('/^@cannot\s*\(\s*[\'\"]([^\'\"]*)[\'\"]\s*\)/', $line, $m) === 1) {
            return '${App.Helper.execute(() => {' . "\nif(App.Helper.cannot('" . $m[1] . "')){\nreturn `";
        }
        if (str_starts_with($line, '@can') && preg_match('/^@can\s*\(\s*[\'\"]([^\'\"]*)[\'\"]\s*\)/', $line, $m) === 1) {
            return '${App.Helper.execute(() => {' . "\nif(App.Helper.can('" . $m[1] . "')){\nreturn `";
        }
        return null;
    }

    public function processEndcanDirective(string $line): ?string
    {
        return str_starts_with($line, '@endcan') || str_starts_with($line, '@endcannot') ? "`\n    }\n})}" : null;
    }

    public function processCsrfDirective(string $line): ?string
    {
        return str_starts_with($line, '@csrf') ? '<input type="hidden" name="_token" value="${App.Helper.getCsrfToken()}">' : null;
    }

    public function processMethodDirective(string $line): ?string
    {
        if (! str_starts_with($line, '@method') || preg_match('/^@method\s*\(\s*[\'\"]([^\'\"]*)[\'\"]\s*\)/', $line, $m) !== 1) return null;
        return '<input type="hidden" name="_method" value="' . strtoupper($m[1]) . '">';
    }

    public function processErrorDirective(string $line): ?string
    {
        if (! str_starts_with($line, '@error') || preg_match('/^@error\s*\(\s*[\'\"]([^\'\"]*)[\'\"]\s*\)/', $line, $m) !== 1) return null;
        return '${App.Helper.execute(() => {' . "\nif(App.Helper.hasError('" . $m[1] . "')){\nreturn `";
    }

    public function processEnderrorDirective(string $line): ?string
    {
        return str_starts_with($line, '@enderror') ? "`\n    }\n})}" : null;
    }

    public function processHassectionDirective(string $line): ?string
    {
        if (! str_starts_with($line, '@hasSection') || preg_match('/^@hasSection\s*\(\s*[\'\"]([^\'\"]*)[\'\"]\s*\)/', $line, $m) !== 1) return null;
        return '${App.Helper.execute(() => {' . "\nif(App.Helper.hasSection('" . $m[1] . "')){\nreturn `";
    }

    public function processEndhassectionDirective(string $line): ?string
    {
        return str_starts_with($line, '@endhassection') ? "`\n    }\n})}" : null;
    }

    /** @param list<array<int, mixed>> $stack @param list<string> $output */
    public function processEmptyDirective(string $line, array &$stack, array &$output): bool
    {
        if (! str_starts_with($line, '@empty') || preg_match('/^@empty\s*\(\s*\$?(\w+)\s*\)/', $line, $m) !== 1) return false;
        $output[] = '${App.Helper.execute(() => {' . "\nif(App.Helper.isEmpty(" . $m[1] . ")){\nreturn `";
        $stack[] = ['empty', count($output)];
        return true;
    }

    /** @param list<array<int, mixed>> $stack @param list<string> $output */
    public function processIssetDirective(string $line, array &$stack, array &$output): bool
    {
        if (! str_starts_with($line, '@isset') || preg_match('/^@isset\s*\(\s*\$?(\w+)\s*\)/', $line, $m) !== 1) return false;
        $output[] = '${App.Helper.execute(() => {' . "\nif(App.Helper.isSet(" . $m[1] . ")){\nreturn `";
        $stack[] = ['isset', count($output)];
        return true;
    }

    /** @param list<array<int, mixed>> $stack @param list<string> $output */
    public function processEndemptyDirective(array &$stack, array &$output): bool
    {
        return $this->closeStackDirective('empty', "`;\n    }\n})}", $stack, $output);
    }

    /** @param list<array<int, mixed>> $stack @param list<string> $output */
    public function processEndissetDirective(array &$stack, array &$output): bool
    {
        return $this->closeStackDirective('isset', "`;\n    }\n})}", $stack, $output);
    }

    public function processUnlessDirective(string $line): ?string
    {
        if (! str_starts_with($line, '@unless') || preg_match('/^@unless\s*\(\s*(.*?)\s*\)/', $line, $m) !== 1) return null;
        return '${App.Helper.execute(() => {' . "\nif(!(" . $this->expressions->compileStatement($m[1]) . ")){\nreturn `";
    }

    public function processEndunlessDirective(string $line): ?string
    {
        return str_starts_with($line, '@endunless') ? "`\n    }\n})}" : null;
    }

    /** @param list<array<int, mixed>> $stack @param list<string> $output */
    public function processPhpDirective(string $line, array &$stack, array &$output): bool
    {
        if (! str_starts_with($line, '@php')) return false;
        $output[] = '${App.Helper.execute(() => {';
        $stack[] = ['php', count($output)];
        return true;
    }

    /** @param list<array<int, mixed>> $stack @param list<string> $output */
    public function processEndphpDirective(array &$stack, array &$output): bool
    {
        return $this->closeStackDirective('php', '})}', $stack, $output);
    }

    public function processJsonDirective(string $line): ?string
    {
        $expr = $this->directiveExpression($line, '@json');
        if ($expr === null) return null;
        $variables = $this->scanPhpVariables($expr);
        $converted = $expr;
        $placeholders = [];
        foreach ($variables as $index => $name) {
            $placeholder = '__VAR_' . $index . '__';
            $placeholders[$placeholder] = $name;
            $converted = str_replace('$' . $name, '"' . $placeholder . '"', $converted);
        }
        $json = $this->convertPhpArrayToJson($converted);
        foreach ($placeholders as $placeholder => $name) $json = str_replace('"' . $placeholder . '"', $name, $json);
        $js = $this->expressions->compileStatement($json);
        if ($variables !== []) {
            return '${this.__output(' . $this->formatSubscribe($variables) . ', () => JSON.stringify(' . $js . '))}';
        }
        return '${JSON.stringify(' . $js . ')}';
    }

    public function processLangDirective(string $line): ?string
    {
        if (! str_starts_with($line, '@lang') || preg_match('/^@lang\s*\(\s*[\'\"]([^\'\"]*)[\'\"]\s*\)/', $line, $m) !== 1) return null;
        return '${App.Helper.lang(\'' . $m[1] . '\')}';
    }

    public function processChoiceDirective(string $line): ?string
    {
        if (! str_starts_with($line, '@choice') || preg_match('/^@choice\s*\(\s*[\'\"]([^\'\"]*)[\'\"]\s*,\s*(\d+)\s*\)/', $line, $m) !== 1) return null;
        return '${App.Helper.choice(\'' . $m[1] . '\', ' . $m[2] . ')}';
    }

    public function processExecDirective(string $line): ?string
    {
        $expr = $this->directiveExpression($line, '@exec');
        if ($expr === null) return null;
        $statements = Balanced::splitTopLevelStripped($expr, ',');
        $compiled = [];
        foreach ($statements as $statement) if (trim($statement) !== '') $compiled[] = $this->expressions->compileStatement($statement);
        return '${App.Helper.execute(() => {' . implode('; ', $compiled) . ';})}';
    }

    public function processOutDirective(string $line): ?string
    {
        $expr = $this->directiveExpression($line, '@out');
        if ($expr === null) return null;
        $variables = $this->scanPhpVariables($expr);
        return '${this.__output(' . $this->formatSubscribe($variables) . ', () => (' . $this->expressions->compileStatement($expr) . '))}';
    }

    /** @param list<array<int, mixed>> $stack @param list<string> $output */
    public function processWrapperDirective(string $line, array &$stack, array &$output): string|false
    {
        $lower = strtolower($line);
        if (! str_starts_with($lower, '@wrapper') && ! str_starts_with($lower, '@view')) return false;
        if (preg_match('/@(?:wrapper|view)\s*\((.*)\)/i', $line, $m) === 1) {
            $paramsString = trim($m[1]);
            if ($paramsString === '') {
                $arg1 = "'div'"; $arg2 = '{}';
            } else {
                $params = $this->parseDelimited($paramsString, true);
                $arg1 = $params[0];
                if (count($params) === 1) $arg2 = '{}';
                else {
                    $arg2 = $params[1];
                    if (str_contains($arg2, '=') && ! str_starts_with(trim($arg2), '[')) $arg2 = trim(explode('=', $arg2, 2)[1]);
                }
            }
        } else {
            $arg1 = "'div'"; $arg2 = '{}';
        }
        $stack[] = ['wrapper', count($output)];
        return '${App.Helper.startWrapper(' . $this->expressions->compileStatement($arg1) . ', ' . $this->expressions->compileStatement($arg2) . ', __VIEW_ID__)}';
    }

    /** @param list<array<int, mixed>> $stack @param list<string> $output */
    public function processEndwrapperDirective(array &$stack, array &$output): string|false
    {
        $last = $stack === [] ? null : $stack[array_key_last($stack)];
        if (! is_array($last) || ($last[0] ?? null) !== 'wrapper') return false;
        array_pop($stack);
        return '${App.Helper.endWrapper(__VIEW_ID__)}';
    }

    /** @param list<array<int, mixed>> $stack @param list<string> $output */
    public function processLetDirective(string $line, array &$stack, array &$output): bool
    {
        return $this->processDeclarationDirective($line, '@let', $output);
    }

    /** @param list<array<int, mixed>> $stack @param list<string> $output */
    public function processConstDirective(string $line, array &$stack, array &$output): bool
    {
        return $this->processDeclarationDirective($line, '@const', $output);
    }

    /** @param list<array<int, mixed>> $stack @param list<string> $output */
    public function processUsestateDirective(string $line, array &$stack, array &$output): bool
    {
        if (! str_starts_with($line, '@useState') || preg_match('/@useState\s*\((.*)\)/', $line, $m) !== 1) return false;
        $params = $this->parseDelimited(trim($m[1]), false);
        if (count($params) !== 3) return false;
        $compiled = array_map(fn (string $value): string => $this->expressions->compileStatement($value), $params);
        $output[] = '${App.Helper.execute(() => {';
        $output[] = '    const [' . $compiled[1] . ', ' . $compiled[2] . '] = useState(' . $compiled[0] . ');';
        $output[] = '})}';
        return true;
    }

    /** @param list<string> $output */
    private function processDeclarationDirective(string $line, string $directive, array &$output): bool
    {
        if (! str_starts_with($line, $directive) || preg_match('/' . preg_quote($directive, '/') . '\s*\((.*)\)/', $line, $m) !== 1) return false;
        $js = $this->expressions->compileStatement(trim($m[1]));
        $output[] = '${App.Helper.execute(() => {';
        foreach (explode(';', $js) as $code) if (trim($code) !== '') $output[] = '    ' . trim($code) . ';';
        $output[] = '})}';
        return true;
    }

    /** @param list<array<int, mixed>> $stack @param list<string> $output */
    private function closeStackDirective(string $type, string $closing, array &$stack, array &$output): bool
    {
        $last = $stack === [] ? null : $stack[array_key_last($stack)];
        if (! is_array($last) || ($last[0] ?? null) !== $type) return false;
        array_pop($stack); $output[] = $closing; return true;
    }

    private function directiveExpression(string $line, string $directive): ?string
    {
        if (! str_starts_with($line, $directive) || preg_match('/^' . preg_quote($directive, '/') . '\s*\(/', $line, $m, PREG_OFFSET_CAPTURE) !== 1) return null;
        $open = $m[0][1] + strlen($m[0][0]) - 1;
        [$content] = Balanced::extractParensAt($line, $open);
        return $content === null ? null : trim($content);
    }

    /** @return list<string> */
    private function scanPhpVariables(string $expr): array
    {
        $variables = []; $single = false; $double = false; $escape = false; $length = strlen($expr);
        for ($i = 0; $i < $length;) {
            $char = $expr[$i];
            if ($escape) { $escape = false; $i++; continue; }
            if ($char === '\\') { $escape = true; $i++; continue; }
            if ($single) { if ($char === "'") $single = false; $i++; continue; }
            if ($double) {
                if ($char === '"') { $double = false; $i++; continue; }
                if ($char !== '$') { $i++; continue; }
            } else {
                if ($char === "'") { $single = true; $i++; continue; }
                if ($char === '"') { $double = true; $i++; continue; }
                if ($char !== '$') { $i++; continue; }
            }
            $j = $i + 1;
            if ($j < $length && preg_match('/[a-zA-Z_]/', $expr[$j]) === 1) {
                $start = $j++;
                while ($j < $length && preg_match('/[a-zA-Z0-9_]/', $expr[$j]) === 1) $j++;
                $name = substr($expr, $start, $j - $start);
                if (! in_array($name, $variables, true)) $variables[] = $name;
                $i = $j; continue;
            }
            $i++;
        }
        return $variables;
    }

    /** @param list<string> $variables */
    private function formatSubscribe(array $variables): string
    {
        return '[' . implode(',', array_map(static fn (string $name): string => "'" . $name . "'", $variables)) . ']';
    }

    private function convertPhpArrayToJson(string $expr): string
    {
        $compiled = $this->expressions->compileStatement($expr);
        $decoded = json_decode($compiled, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        return preg_replace("/'([^']*)'/", '"$1"', $expr) ?? $expr;
    }

    /** @return list<string> */
    private function parseDelimited(string $text, bool $trackBrackets): array
    {
        $parts = []; $current = ''; $brackets = 0; $parens = 0; $quote = null; $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ($quote === null) {
                if ($char === '"' || $char === "'") $quote = $char;
                elseif ($trackBrackets && $char === '[') $brackets++;
                elseif ($trackBrackets && $char === ']') $brackets--;
                elseif ($char === '(') $parens++;
                elseif ($char === ')') $parens--;
                elseif ($char === ',' && $brackets === 0 && $parens === 0) { $parts[] = trim($current); $current = ''; continue; }
            } elseif ($char === $quote) {
                $slashes = 0;
                for ($j = $i - 1; $j >= 0 && $text[$j] === '\\'; $j--) $slashes++;
                if ($slashes % 2 === 0) $quote = null;
            }
            $current .= $char;
        }
        if (trim($current) !== '') $parts[] = trim($current);
        return $parts;
    }
}
