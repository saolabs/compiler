<?php

declare(strict_types=1);

namespace Saola\Compiler\Support;

/** Shared SSR/CSR auth dependency and structural directive lowering. */
final class AuthExpressions
{
    public const STATE_KEY = '@auth';

    public static function readsAuth(string $expression): bool
    {
        // Quoted examples are text, not service reads. Respect escaped quotes.
        $code = preg_replace('/\x27(?:\\\\.|[^\x27\\\\])*\x27|"(?:\\\\.|[^"\\\\])*"/s', ' ', $expression) ?? $expression;
        return preg_match('/(?<![\w$.:>])(?:auth|isAuth)\s*\(|\bApp\s*\.\s*Helper\s*\.\s*(?:auth|isAuth)\s*\(|\bApp\s*\.\s*Auth\b/', $code) === 1;
    }

    /** Called only for a structural directive line, outside raw/SSR-only regions. */
    public static function lowerDirective(string $line): string
    {
        if (preg_match('/^\s*@end(?:auth|guest)\s*$/', $line)) return '@endif';
        if (!preg_match('/^\s*@(auth|guest|elseauth|elseguest)\b/', $line, $match)) return $line;
        $tail = trim(substr($line, strlen($match[0])));
        $guard = '';
        if ($tail !== '') {
            if ($tail[0] !== '(') return $line;
            [$guard, $end] = Balanced::extractParensAt($tail, 0);
            if ($guard === null || trim(substr($tail, $end)) !== '') return $line;
        }
        $method = str_ends_with($match[1], 'guest') ? 'guest' : 'check';
        $directive = str_starts_with($match[1], 'else') ? 'elseif' : 'if';
        return '@'.$directive.'(auth('.$guard.')->'.$method.'())';
    }
}
