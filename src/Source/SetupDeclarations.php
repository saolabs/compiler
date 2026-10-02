<?php

declare(strict_types=1);

namespace Saola\Compiler\Source;

use Saola\Compiler\CompileException;
use Saola\Compiler\Support\BladeComment;

/** Extract shared declarations without treating JavaScript text as template syntax. */
final class SetupDeclarations
{
    private const NAMES = ['vars', 'props', 'state', 'states', 'let', 'const', 'computed', 'asset', 'assets', 'useState', 'importView', 'import'];

    /** @return array{string, list<array{index:int,text:string}>} */
    public function extract(string $source): array
    {
        $scan = BladeComment::blank($source);
        $scan = preg_replace_callback('/@verbatim\b[\s\S]*?@endverbatim\b/', static fn ($m) => self::blank($m[0]), $scan) ?? $scan;
        $wrappers = (new WrapperScanner())->scan($scan);
        preg_match_all('/<script\b([^>]*)>([\s\S]*?)<\/script>/i', $scan, $scripts, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $found = [];
        foreach ($scripts as $script) {
            if (!preg_match('/(?:^|\s)setup(?:\s|=|$)/i', $script[1][0])) continue;
            $start = $script[0][1];
            foreach ($wrappers as $wrapper) {
                if ($wrapper->covers($start, $start + strlen($script[0][0]))) continue 2;
            }
            $offset = $script[2][1];
            $body = substr($source, $offset, strlen($script[2][0]));
            $depth = 0;
            $canRegex = true;
            $statementStart = true;
            for ($i = 0, $n = strlen($body); $i < $n;) {
                $c = $body[$i];
                if (ctype_space($c)) { if ($c === "\n") $statementStart = true; $i++; continue; }
                $comment = in_array(substr($body, $i, 2), ['//', '/*'], true);
                if ($this->skipLiteral($body, $i, $canRegex)) { if (!$comment) $statementStart = false; continue; }
                if ($c === '@' && preg_match('/\G@([A-Za-z]+)\s*\(/', $body, $m, 0, $i) && in_array($m[1], self::NAMES, true)) {
                    $line = substr_count(substr($source, 0, $offset + $i), "\n") + 1;
                    if ($depth !== 0 || !$statementStart) throw new CompileException('@'.$m[1].' must be a top-level setup declaration.', sourceLine: $line);
                    if ($m[1] === 'import') throw new CompileException('Use @importView inside <script setup>; @import remains supported outside.', sourceLine: $line);
                    $open = $i + strlen($m[0]) - 1;
                    $end = $this->declarationEnd($body, $open, $line);
                    $text = substr($body, $i, $end - $i);
                    if ($m[1] === 'importView') $text = '@import'.substr($text, strlen('@importView'));
                    $found[] = ['index' => $offset + $i, 'text' => $text];
                    if (($body[$end] ?? '') === ';') $end++;
                    $source = substr_replace($source, self::blank(substr($body, $i, $end - $i)), $offset + $i, $end - $i);
                    $i = $end;
                    $canRegex = $statementStart = true;
                    continue;
                }
                if (str_contains('([{', $c)) $depth++;
                elseif (str_contains(')]}', $c)) $depth--;
                $statementStart = $c === ';' || ($c === '}' && $depth === 0);
                $this->advanceToken($body, $i, $canRegex);
            }
        }
        return [$source, $found];
    }

    private function declarationEnd(string $body, int $start, int $line): int
    {
        $stack = [];
        $canRegex = true;
        for ($i = $start, $n = strlen($body); $i < $n;) {
            if ($this->skipLiteral($body, $i, $canRegex)) continue;
            $c = $body[$i];
            if (str_contains('([{', $c)) $stack[] = $c;
            elseif (str_contains(')]}', $c)) {
                $expected = [')' => '(', ']' => '[', '}' => '{'][$c];
                if (array_pop($stack) !== $expected) throw new CompileException('Unbalanced setup declaration.', sourceLine: $line);
                if ($stack === []) return $i + 1;
            }
            $this->advanceToken($body, $i, $canRegex);
        }
        throw new CompileException('Unclosed setup declaration.', sourceLine: $line);
    }

    /** Skip comments, strings, nested template literals and regex literals. */
    private function skipLiteral(string $s, int &$i, bool &$canRegex): bool
    {
        $n = strlen($s);
        $c = $s[$i];
        $pair = substr($s, $i, 2);
        if ($pair === '//' || $pair === '/*') {
            $end = strpos($s, $pair === '//' ? "\n" : '*/', $i + 2);
            $i = $end === false ? $n : $end + ($pair === '//' ? 0 : 2);
            return true;
        }
        if ($c === '"' || $c === "'" || $c === '`') {
            $quote = $c;
            for ($i++; $i < $n; $i++) {
                if ($s[$i] === '\\') { $i++; continue; }
                if ($s[$i] === $quote) { $i++; break; }
                if ($quote === '`' && substr($s, $i, 2) === '${') {
                    $i += 2;
                    $this->skipInterpolation($s, $i);
                    $i--;
                }
            }
            $canRegex = false;
            return true;
        }
        if ($c === '/' && $canRegex) {
            $inClass = false;
            for ($j = $i + 1; $j < $n && $s[$j] !== "\n"; $j++) {
                if ($s[$j] === '\\') { $j++; continue; }
                if ($s[$j] === '[') $inClass = true;
                elseif ($s[$j] === ']') $inClass = false;
                elseif ($s[$j] === '/' && !$inClass) {
                    $i = $j + 1;
                    while ($i < $n && ctype_alpha($s[$i])) $i++;
                    $canRegex = false;
                    return true;
                }
            }
        }
        return false;
    }

    private function skipInterpolation(string $s, int &$i): void
    {
        $depth = 1;
        $canRegex = true;
        while ($i < strlen($s)) {
            if ($this->skipLiteral($s, $i, $canRegex)) continue;
            if ($s[$i] === '{') $depth++;
            if ($s[$i] === '}') {
                $depth--;
                if ($depth === 0) { $i++; return; }
            }
            $this->advanceToken($s, $i, $canRegex);
        }
    }

    private function advanceToken(string $s, int &$i, bool &$canRegex): void
    {
        if (preg_match('/\G[A-Za-z_$][\w$]*/', $s, $m, 0, $i)) {
            $canRegex = in_array($m[0], ['return', 'throw', 'case', 'delete', 'void', 'typeof', 'yield', 'await', 'in', 'of'], true);
            $i += strlen($m[0]);
        } else {
            if (!ctype_space($s[$i])) $canRegex = str_contains('=(:,[!&|?;{+-*%~<>', $s[$i]);
            $i++;
        }
    }

    private static function blank(string $s): string
    {
        return preg_replace('/[^\r\n]/', ' ', $s) ?? $s;
    }
}
