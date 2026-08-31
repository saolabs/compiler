<?php

declare(strict_types=1);

namespace Saola\Compiler\Directive;

use Saola\Compiler\Expr\ExpressionCompiler;
use Saola\Compiler\Support\Balanced;
use Saola\Compiler\Template\ReactiveScopeManager;

/** Port của sao2js/conditional_handlers.py. */
final class ConditionalHandlers
{
    /** @var array<string, true> */
    private array $stateVariables = [];

    /** @param iterable<string> $stateVariables */
    public function __construct(
        iterable $stateVariables = [],
        private readonly ReactiveScopeManager $scopes = new ReactiveScopeManager(),
        private readonly bool $isTypescript = false,
        private readonly ExpressionCompiler $expressions = new ExpressionCompiler(),
    ) {
        foreach ($stateVariables as $name) {
            $this->stateVariables[$name] = true;
        }
    }

    /** @param list<array<int, mixed>> $stack
     *  @param list<string> $output
     */
    public function processIfDirective(string $line, array &$stack, array &$output, bool $attribute = false): bool
    {
        $open = strpos($line, '(');
        if ($open === false) return false;
        [$text] = Balanced::extractParensAt($line, $open);
        if ($text === null) return false;

        $condition = $this->expressions->compileStatement(trim($text));
        $watchKeys = $this->stateVariablesUsed($this->extractVariables($condition));
        [$loopType] = $this->findEnclosingLoop($stack);
        if ($loopType !== null) {
            $output[] = 'if(' . $condition . '){';
            $stack[] = ['if', count($output), $watchKeys, $attribute, true, 'direct'];
            return true;
        }

        if ($attribute || $watchKeys === []) {
            $output[] = '${this.__execute(() => { if(' . $condition . '){ return `';
            $stack[] = ['if', count($output), $watchKeys, $attribute, false, 'execute'];
            return true;
        }

        $childId = $this->scopes->generateChildId('if');
        $rcId = $this->scopes->makeRcId($childId);
        $rcParam = $this->isTypescript ? '(__rc__: any)' : '(__rc__)';
        $output[] = '${this.__reactive(\'if\', __rc__, ' . $rcId . ', ' . $this->formatPythonList($watchKeys)
            . ', ' . $rcParam . ' => { if(' . $condition . '){ return `';
        $this->scopes->pushReactiveScope($childId);
        $stack[] = ['if', count($output), $watchKeys, $attribute, false, 'reactive'];

        return true;
    }

    /** @param list<array<int, mixed>> $stack
     *  @param list<string> $output
     */
    public function processElseifDirective(string $line, array &$stack, array &$output): bool
    {
        $open = strpos($line, '(');
        if ($open === false) return false;
        [$text] = Balanced::extractParensAt($line, $open);
        if ($text === null) return false;
        $condition = $this->expressions->compileStatement(trim($text));

        $last = $stack === [] ? null : $stack[array_key_last($stack)];
        if (is_array($last) && ($last[0] ?? null) === 'if' && ($last[5] ?? null) === 'direct') {
            $output[] = '} else if(' . $condition . '){';
            return true;
        }

        if (is_array($last) && ($last[0] ?? null) === 'if') {
            $keys = array_fill_keys($last[2] ?? [], true);
            foreach ($this->stateVariablesUsed($this->extractVariables($condition)) as $key) $keys[$key] = true;
            // Bản Python cố ý làm mất các field mode/context tại nhánh này.
            $stack[array_key_last($stack)] = ['if', $last[1], array_keys($keys)];
        }
        $output[] = '`; } else if(' . $condition . '){ return `';
        return true;
    }

    /** @param list<array<int, mixed>> $stack
     *  @param list<string> $output
     */
    public function processElseDirective(string $line, array &$stack, array &$output): bool
    {
        $last = $stack === [] ? null : $stack[array_key_last($stack)];
        if (is_array($last) && ($last[0] ?? null) === 'if' && ($last[5] ?? null) === 'direct') {
            $output[] = '} else {';
            return true;
        }
        $output[] = '`; } else { return `';
        return true;
    }

    /** @param list<array<int, mixed>> $stack
     *  @param list<string> $output
     */
    public function processEndifDirective(array &$stack, array &$output): bool
    {
        $last = $stack === [] ? null : $stack[array_key_last($stack)];
        if (is_array($last) && ($last[0] ?? null) === 'if') {
            $mode = $last[5] ?? null;
            if ($mode === 'direct') {
                array_pop($stack);
                $output[] = '}';
                return true;
            }
            if ($mode === 'reactive') $this->scopes->popReactiveScope();
            array_pop($stack);
            $output[] = '`; }';
            $output[] = "return '';";
            $output[] = '})}';
        }
        return true;
    }

    /** @param list<array<int, mixed>> $stack
     *  @param list<string> $output
     */
    public function processSwitchDirective(string $line, array &$stack, array &$output, bool $attribute = false): bool
    {
        $open = strpos($line, '(');
        if ($open === false) return false;
        [$text] = Balanced::extractParensAt($line, $open);
        if ($text === null) return false;
        $condition = $this->expressions->compileStatement(trim($text));
        $watchKeys = $this->stateVariablesUsed($this->extractVariables($condition));
        $parentConcat = false;
        $parentType = null;
        if ($stack !== []) {
            $candidate = $stack[array_key_last($stack)][0] ?? null;
            if (in_array($candidate, ['for', 'foreach', 'while'], true)) {
                $parentConcat = true;
                $parentType = $candidate;
            }
        }
        $logic = "let __switchOutputContent__ = '';\nswitch(" . $condition . ') {';
        $childId = $this->scopes->generateChildId('switch');
        $rcId = $this->scopes->makeRcId($childId);
        $rcParam = $this->isTypescript ? '(__rc__: any)' : '(__rc__)';
        $call = ($attribute || $watchKeys === [])
            ? 'this.__execute(() => {'
            : 'this.__reactive(\'switch\', __rc__, ' . $rcId . ', ' . $this->formatPythonList($watchKeys) . ', ' . $rcParam . ' => {';
        $output[] = $parentConcat
            ? '__' . $parentType . 'OutputContent__ += ' . $call . "\n" . $logic
            : '${' . $call . "\n" . $logic;
        $this->scopes->pushReactiveScope($childId);
        $stack[] = ['switch', count($output), $watchKeys, $attribute, $parentConcat];
        return true;
    }

    /** @param list<array<int, mixed>> $stack
     *  @param list<string> $output
     */
    public function processCaseDirective(string $line, array &$stack, array &$output): bool
    {
        $open = strpos($line, '(');
        if ($open === false) return false;
        [$text] = Balanced::extractParensAt($line, $open);
        if ($text === null) return false;
        $output[] = "\ncase " . $this->expressions->compileStatement(trim($text)) . ":\n__switchOutputContent__ += `";
        return true;
    }

    /** @param list<array<int, mixed>> $stack
     *  @param list<string> $output
     */
    public function processDefaultDirective(string $line, array &$stack, array &$output): bool
    {
        $output[] = "\ndefault:\n__switchOutputContent__ += `";
        return true;
    }

    /** @param list<array<int, mixed>> $stack
     *  @param list<string> $output
     */
    public function processBreakDirective(string $line, array &$stack, array &$output): bool
    {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            $type = $stack[$i][0] ?? null;
            if ($type === 'switch') {
                $output[] = "`;\nbreak;";
                return true;
            }
            if ($type === 'for' || $type === 'while') {
                $output[] = 'break;';
                return true;
            }
        }
        $output[] = "`;\nbreak;";
        return true;
    }

    /** @param list<array<int, mixed>> $stack
     *  @param list<string> $output
     */
    public function processEndswitchDirective(array &$stack, array &$output): bool
    {
        $last = $stack === [] ? null : $stack[array_key_last($stack)];
        if (! is_array($last) || ($last[0] ?? null) !== 'switch') return false;
        $info = array_pop($stack);
        $this->scopes->popReactiveScope();
        $output[] = ($info[4] ?? false)
            ? "`;\n}\nreturn __switchOutputContent__;\n})"
            : "`;\n}\nreturn __switchOutputContent__;\n})}";
        return true;
    }

    /** @param list<array<int, mixed>> $stack
     *  @return array{0: string|null, 1: string|null}
     */
    private function findEnclosingLoop(array $stack): array
    {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            $type = $stack[$i][0] ?? null;
            if ($type === 'for' || $type === 'while') return [$type, '__' . $type . 'OutputContent__'];
            if ($type === 'foreach') return [null, null];
        }
        return [null, null];
    }

    /** @return list<string> */
    private function extractVariables(string $expression): array
    {
        $cleaned = preg_replace('/\.([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', '.METHODCALL(', $expression) ?? $expression;
        preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\b/', $cleaned, $matches);
        $excluded = array_fill_keys(['if','else','return','function','const','let','var','true','false','null','undefined','Array','Object','String','Number','METHODCALL','App','View','Helper'], true);
        $result = [];
        foreach ($matches[1] as $name) if (! isset($excluded[$name])) $result[$name] = true;
        return array_keys($result);
    }

    /** @param list<string> $variables
     *  @return list<string>
     */
    private function stateVariablesUsed(array $variables): array
    {
        $result = [];
        foreach ($variables as $name) if (isset($this->stateVariables[$name])) $result[] = $name;
        return $result;
    }

    /** @param list<string> $values */
    private function formatPythonList(array $values): string
    {
        return '[' . implode(', ', array_map(static fn (string $value): string => "'" . $value . "'", $values)) . ']';
    }
}
