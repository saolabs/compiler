<?php

declare(strict_types=1);

namespace Saola\Compiler\Expr;

use Saola\Compiler\Support\Balanced;

/** Lower only executable .sao expressions; Laravel's global __ is never changed. */
final class TranslationCalls
{
    public static function php(string $expression): string
    {
        $tokens = array_slice(token_get_all('<?php '.$expression), 1);
        $out = '';
        $previous = '';
        foreach ($tokens as $index => $token) {
            $value = is_array($token) ? $token[1] : $token;
            if (is_array($token) && $token[0] === T_STRING && $value === '__'
                && !in_array($previous, ['->', '?->', '::', '.', 'function', 'new'], true)) {
                $next = $index + 1;
                while (isset($tokens[$next]) && is_array($tokens[$next])
                    && in_array($tokens[$next][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $next++;
                }
                if (($tokens[$next] ?? null) === '(') {
                    $value = 'text';
                }
            }
            $out .= $value;
            if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $previous = $value;
            }
        }
        return $out;
    }

    public static function blade(string $blade): string
    {
        $out = '';
        $cursor = 0;
        $pattern = '~\{\{--.*?--\}\}|@verbatim\b.*?@endverbatim\b|<(script|style)\b[^>]*>.*?</\1\s*>|\{!!|\{\{|<\?php|@php\b(?!\s*\()|@[a-zA-Z_]\w*\s*\(~si';
        while (preg_match($pattern, $blade, $match, PREG_OFFSET_CAPTURE, $cursor)) {
            [$startToken, $start] = $match[0];
            $out .= substr($blade, $cursor, $start - $cursor);
            $innerStart = $start + strlen($startToken);
            if (str_starts_with($startToken, '{{--') || str_starts_with($startToken, '@verbatim')
                || preg_match('/^<(script|style)\b/i', $startToken)) {
                $out .= $startToken;
                $cursor = $innerStart;
                continue;
            }
            if (str_ends_with($startToken, '(')) {
                [$inner, $end] = Balanced::extractParensAt($blade, $innerStart - 1);
                if ($inner === null) {
                    $out .= $startToken;
                    $cursor = $innerStart;
                    continue;
                }
                $out .= $startToken.self::php($inner).')';
                $cursor = $end;
                continue;
            }
            $close = match ($startToken) {
                '{{' => '}}', '{!!' => '!!}', '<?php' => '?>', default => '@endphp',
            };
            $end = self::findClose($blade, $innerStart, $close);
            if ($end === null) {
                $out .= substr($blade, $start);
                return $out;
            }
            $out .= $startToken.self::php(substr($blade, $innerStart, $end - $innerStart)).$close;
            $cursor = $end + strlen($close);
        }
        return $out.substr($blade, $cursor);
    }

    private static function findClose(string $source, int $offset, string $close): ?int
    {
        $quote = null;
        for ($i = $offset, $length = strlen($source); $i < $length; $i++) {
            $char = $source[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif (substr_compare($source, $close, $i, strlen($close)) === 0) {
                return $i;
            }
        }
        return null;
    }
}
