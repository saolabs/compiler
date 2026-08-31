<?php

declare(strict_types=1);

namespace Saola\Compiler\Directive;

use Saola\Compiler\Expr\ExpressionCompiler;
use Saola\Compiler\Support\Balanced;
use Saola\Compiler\Template\ReactiveScopeManager;

/** Port của sao2js/loop_handlers.py. */
final class LoopHandlers
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
        foreach ($stateVariables as $name) $this->stateVariables[$name] = true;
    }

    /** @param list<array<int, mixed>> $stack
     *  @param list<string> $output
     */
    public function processForeachDirective(string $line, array &$stack, array &$output, bool $attribute = false): bool
    {
        $content = $this->directiveContent($line);
        if ($content === null || preg_match('/^\s*(.*?)\s+as\s+\$?(\w+)(\s*=>\s*\$?(\w+))?\s*$/s', $content, $match) !== 1) return false;
        $array = $this->expressions->compileStatement($match[1]);
        $first = $match[2];
        if (($match[3] ?? '') !== '') {
            $key = $first; $value = $match[4];
            $callback = $this->isTypescript
                ? "($value: any, $key: any, __loopIndex: any, __loop: any) => `"
                : "($value, $key, __loopIndex, __loop) => `";
        } else {
            $value = $first;
            $callback = $this->isTypescript
                ? "($value: any, __loopKey: any, __loopIndex: any, __loop: any) => `"
                : "($value, __loopKey, __loopIndex, __loop) => `";
        }
        $watch = $this->stateVariablesUsed($this->extractVariables($array));
        $call = 'this.__foreach(' . $array . ', ' . $callback;
        [$parentType, $concat] = $this->findEnclosingLoop($stack);
        $inConcat = $parentType !== null;
        $childId = $this->scopes->generateChildId('foreach');
        if ($attribute) {
            $result = '${' . $call;
        } else {
            $rc = $this->scopes->makeRcId($childId);
            $param = $this->isTypescript ? '(__rc__: any)' : '(__rc__)';
            $prefix = "this.__reactive('foreach', __rc__, $rc, " . $this->formatPythonList($watch) . ", $param => $call";
            $result = $inConcat ? $concat . ' += ' . $prefix : '${' . $prefix;
        }
        $this->scopes->pushReactiveScope($childId, '__loopIndex');
        $output[] = $result;
        $stack[] = ['foreach', count($output), $attribute, $inConcat];
        return true;
    }

    /** @param list<array<int, mixed>> $stack @param list<string> $output */
    public function processEndforeachDirective(array &$stack, array &$output): bool
    {
        $last = $this->last($stack);
        if (is_array($last) && ($last[0] ?? null) === 'foreach') {
            $this->scopes->popReactiveScope(); array_pop($stack);
            $output[] = ($last[2] ?? false) ? '`)}' : (($last[3] ?? false) ? '`));' : '`))}');
        }
        return true;
    }

    /** @param list<array<int, mixed>> $stack @param list<string> $output */
    public function processForDirective(string $line, array &$stack, array &$output, bool $attribute = false): bool
    {
        $content = $this->directiveContent($line);
        if ($content === null || preg_match('/^\s*\$?(\w+)\s*=\s*(.*?);\s*\$?\1\s*([<>=!]+)\s*(.*?);\s*\$?\1\s*\+\+\s*$/s', $content, $match) !== 1) return false;
        $name = $match[1];
        $start = $this->expressions->compileStatement($match[2]);
        $operator = $match[3];
        $end = $this->expressions->compileStatement($match[4]);
        $variables = array_values(array_unique([...$this->extractVariables($start), ...$this->extractVariables($end)]));
        $watch = $this->stateVariablesUsed($variables);
        $logic = "let __forOutputContent__ = ``;\nfor (let $name = $start; $name $operator $end; $name++) {__loop.setCurrentTimes($name);";
        $loop = $this->isTypescript ? '(__loop: any)' : '(__loop)';
        $call = "this.__for('increment', $start, $end, $loop => {\n$logic";
        $hasWatch = ! $attribute && $watch !== [];
        [$parentType, $concat] = $this->findEnclosingLoop($stack);
        $inConcat = $parentType !== null;
        $childId = $this->scopes->generateChildId('for');
        if ($attribute) {
            $result = '${' . $call;
        } elseif ($inConcat) {
            if ($hasWatch) {
                $result = $concat . " += this.__reactive('for', __rc__, " . $this->scopes->makeRcId($childId) . ', '
                    . $this->formatPythonList($watch) . ', ' . ($this->isTypescript ? '(__rc__: any)' : '(__rc__)') . ' => { return ' . $call;
            } else $result = $concat . ' += ' . $call;
        } elseif ($watch === []) {
            $result = '${' . $call;
        } else {
            $result = '${' . "this.__reactive('for', __rc__, " . $this->scopes->makeRcId($childId) . ', '
                . $this->formatPythonList($watch) . ', ' . ($this->isTypescript ? '(__rc__: any)' : '(__rc__)') . ' => ' . $call;
        }
        $this->scopes->pushReactiveScope($childId, $name);
        $output[] = $result;
        $stack[] = ['for', count($output), $attribute, $hasWatch, $inConcat];
        return true;
    }

    /** @param list<array<int, mixed>> $stack @param list<string> $output */
    public function processEndforDirective(array &$stack, array &$output): bool
    {
        $last = $this->last($stack);
        if (is_array($last) && ($last[0] ?? null) === 'for') {
            $this->scopes->popReactiveScope(); array_pop($stack);
            $attribute = $last[2] ?? false; $watch = $last[3] ?? false; $concat = $last[4] ?? false;
            if ($attribute) $result = "\n}\nreturn __forOutputContent__;\n})\n}";
            elseif ($concat && $watch) $result = "\n}\nreturn __forOutputContent__;\n})\n});";
            elseif ($concat) $result = "\n}\nreturn __forOutputContent__;\n});";
            elseif ($watch) $result = "\n}\nreturn __forOutputContent__;\n})\n)}";
            else $result = "\n}\nreturn __forOutputContent__;\n})\n}";
            $output[] = $result;
        }
        return true;
    }

    /** @param list<array<int, mixed>> $stack @param list<string> $output */
    public function processWhileDirective(string $line, array &$stack, array &$output, bool $attribute = false): bool
    {
        $content = $this->directiveContent($line);
        if ($content === null) return false;
        $condition = $this->expressions->compileStatement($content);
        $watch = $this->stateVariablesUsed($this->extractVariables($condition));
        $loopParam = $this->isTypescript ? '(__loop: any)' : '(__loop)';
        $logic = "let __whileOutputContent__ = ``;\nlet __whileIterations__ = 0;\nwhile($condition && __whileIterations__ < 10000) {\n__loop.next();";
        $call = "this.__while($loopParam => {\n$logic";
        $childId = $this->scopes->generateChildId('while');
        [$parentType, $concat] = $this->findEnclosingLoop($stack);
        $inConcat = $parentType !== null;
        $hasWatch = ! $attribute && $watch !== [];
        if ($attribute) {
            $result = '${' . $call;
        } elseif ($inConcat) {
            if ($hasWatch) {
                $result = $concat . " += this.__reactive('while', __rc__, " . $this->scopes->makeRcId($childId) . ', '
                    . $this->formatPythonList($watch) . ', ' . ($this->isTypescript ? '(__rc__: any)' : '(__rc__)') . ' => { return ' . $call;
            } else $result = $concat . ' += ' . $call;
        } elseif ($watch === []) {
            $result = '${' . $call;
        } else {
            $result = '${' . "this.__reactive('while', __rc__, " . $this->scopes->makeRcId($childId) . ', '
                . $this->formatPythonList($watch) . ', ' . ($this->isTypescript ? '(__rc__: any)' : '(__rc__)') . ' => ' . $call;
        }
        $this->scopes->pushReactiveScope($childId);
        $output[] = $result;
        $stack[] = ['while', count($output), $attribute, $hasWatch, $inConcat];
        return true;
    }

    /** @param list<array<int, mixed>> $stack @param list<string> $output */
    public function processEndwhileDirective(array &$stack, array &$output): bool
    {
        $last = $this->last($stack);
        if (is_array($last) && ($last[0] ?? null) === 'while') {
            $this->scopes->popReactiveScope(); array_pop($stack);
            $attribute = $last[2] ?? false; $watch = $last[3] ?? false; $concat = $last[4] ?? false;
            $head = "\n__whileIterations__++;\n}\nreturn __whileOutputContent__;\n})";
            if ($attribute) $result = $head . "\n}";
            elseif ($concat && $watch) $result = $head . "\n});";
            elseif ($concat) $result = $head . ';';
            elseif ($watch) $result = $head . "\n)}";
            else $result = $head . "\n}";
            $output[] = $result;
        }
        return true;
    }

    private function directiveContent(string $line): ?string
    {
        $open = strpos($line, '(');
        if ($open === false) return null;
        [$content] = Balanced::extractParensAt($line, $open);
        return $content;
    }

    /** @param list<array<int, mixed>> $stack @return array{0: string|null, 1: string|null} */
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

    /** @param list<string> $variables @return list<string> */
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

    /** @param list<array<int, mixed>> $stack */
    private function last(array $stack): ?array
    {
        return $stack === [] ? null : $stack[array_key_last($stack)];
    }
}
