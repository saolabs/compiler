<?php

declare(strict_types=1);

namespace Saola\Compiler\Directive;

use Saola\Compiler\Expr\ExpressionCompiler;

/** Port của sao2js/show_directive_handler.py. */
final class ShowDirectiveHandler
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

    public function processShowDirective(string $content): string
    {
        $result = $content;

        while (preg_match('/@show\s*\(/', $result, $match, PREG_OFFSET_CAPTURE) === 1) {
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
                        $replacement = $this->generateShowOutput($expression);
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

    private function generateShowOutput(string $expression): string
    {
        $jsExpression = $this->expressions->compile($expression);
        $watchKeys = $this->formatPythonList($this->extractStateVariables($expression));

        return 'style="${this.__showBinding(' . $watchKeys . ', ' . $jsExpression . ')}"';
    }

    /** @return list<string> */
    private function extractStateVariables(string $expression): array
    {
        preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', $expression, $matches);
        $found = [];
        foreach ($matches[1] as $name) {
            if (isset($this->stateVariables[$name])) {
                $found[$name] = true;
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
