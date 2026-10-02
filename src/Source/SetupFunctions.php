<?php

declare(strict_types=1);

namespace Saola\Compiler\Source;

/** Find function declarations that belong to the top level of script setup. */
final class SetupFunctions
{
    /** @return list<string> */
    public function names(string $source): array
    {
        $masked = $this->maskNonCode($source);
        preg_match_all(
            '/\b(?:async\s+)?function\s*\*?\s*([A-Za-z_$][\w$]*)\b/',
            $masked,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        $names = [];
        $depth = 0;
        $cursor = 0;

        foreach ($matches as $match) {
            $offset = $match[0][1];
            for (; $cursor < $offset; $cursor++) {
                if ($masked[$cursor] === '{') $depth++;
                elseif ($masked[$cursor] === '}') $depth = max(0, $depth - 1);
            }

            if ($depth !== 0 || ! $this->startsStatement($masked, $offset)) {
                continue;
            }

            $names[$match[1][0]] = true;
        }

        return array_keys($names);
    }

    private function startsStatement(string $source, int $offset): bool
    {
        $i = $offset - 1;
        $sawNewline = false;
        while ($i >= 0 && ctype_space($source[$i])) {
            if ($source[$i] === "\n" || $source[$i] === "\r") $sawNewline = true;
            $i--;
        }

        if ($i < 0 || $source[$i] === ';' || $source[$i] === '}') return true;
        if (! $sawNewline || str_contains('=([,.?:+-*/%&|!<>', $source[$i])) return false;

        $end = $i + 1;
        while ($i >= 0 && preg_match('/[A-Za-z0-9_$]/', $source[$i]) === 1) $i--;
        $previous = substr($source, $i + 1, $end - $i - 1);

        return ! in_array($previous, ['default', 'declare', 'return', 'throw', 'yield', 'await'], true);
    }

    /** Blank comments and literals while preserving byte offsets and newlines. */
    private function maskNonCode(string $source): string
    {
        $masked = $source;
        $length = strlen($source);
        $canRegex = true;

        for ($i = 0; $i < $length;) {
            $start = $i;
            $pair = substr($source, $i, 2);

            if ($pair === '//' || $pair === '/*') {
                $end = strpos($source, $pair === '//' ? "\n" : '*/', $i + 2);
                $i = $end === false ? $length : $end + ($pair === '//' ? 0 : 2);
                $this->blankRange($masked, $start, $i);
                continue;
            }

            $char = $source[$i];
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                for ($i++; $i < $length; $i++) {
                    if ($source[$i] === '\\') {
                        $i++;
                        continue;
                    }
                    if ($source[$i] === $quote) {
                        $i++;
                        break;
                    }
                }
                $this->blankRange($masked, $start, $i);
                $canRegex = false;
                continue;
            }

            if ($char === '/' && $canRegex) {
                $inClass = false;
                $closed = false;
                for ($i++; $i < $length && $source[$i] !== "\n"; $i++) {
                    if ($source[$i] === '\\') {
                        $i++;
                        continue;
                    }
                    if ($source[$i] === '[') $inClass = true;
                    elseif ($source[$i] === ']') $inClass = false;
                    elseif ($source[$i] === '/' && ! $inClass) {
                        $i++;
                        while ($i < $length && ctype_alpha($source[$i])) $i++;
                        $closed = true;
                        break;
                    }
                }
                if ($closed) {
                    $this->blankRange($masked, $start, $i);
                    $canRegex = false;
                    continue;
                }
                $i = $start;
            }

            if (preg_match('/\G[A-Za-z_$][\w$]*/', $source, $token, 0, $i) === 1) {
                $canRegex = in_array($token[0], ['return', 'throw', 'case', 'delete', 'void', 'typeof', 'yield', 'await', 'in', 'of'], true);
                $i += strlen($token[0]);
                continue;
            }

            if (! ctype_space($char)) $canRegex = str_contains('=(:,[!&|?;{+-*%~<>', $char);
            $i++;
        }

        return $masked;
    }

    private function blankRange(string &$source, int $start, int $end): void
    {
        for ($i = $start; $i < $end; $i++) {
            if ($source[$i] !== "\n" && $source[$i] !== "\r") $source[$i] = ' ';
        }
    }
}
