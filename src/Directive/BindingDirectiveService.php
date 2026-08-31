<?php

declare(strict_types=1);

namespace Saola\Compiler\Directive;

/** Port byte-oriented của sao2js/binding_directive_service.py. */
final class BindingDirectiveService
{
    public function processBindingDirective(string $content, string $directivePattern = 'val|bind'): string
    {
        $result = $content;

        while (preg_match('/@(' . $directivePattern . ')\s*\(/', $result, $match, PREG_OFFSET_CAPTURE) === 1) {
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
                        $binding = $this->convertPhpToBinding($expression);
                        $replacement = 'data-binding="' . $binding . '" data-view-id="${__VIEW_ID__}"';
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

    public function processValDirective(string $content): string
    {
        return $this->processBindingDirective($content, 'val');
    }

    public function processBindDirective(string $content): string
    {
        return $this->processBindingDirective($content, 'bind');
    }

    public function processAllBindingDirectives(string $content): string
    {
        return $this->processBindingDirective($content);
    }

    private function convertPhpToBinding(string $expression): string
    {
        $result = preg_replace('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', '$1', trim($expression)) ?? trim($expression);
        $result = str_replace(['::', '->'], '.', $result);
        $result = preg_replace("/\['([^']+)'\]/", '.$1', $result) ?? $result;
        $result = preg_replace('/\["([^"]+)"\]/', '.$1', $result) ?? $result;

        return preg_replace('/\[(\d+)\]/', '.$1', $result) ?? $result;
    }
}
