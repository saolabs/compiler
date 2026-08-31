<?php

declare(strict_types=1);

namespace Saola\Compiler\Preprocessor;

use Closure;

/**
 * Cắt biểu thức Saola Syntax thành token.
 *
 * Port từ compiler/src/preprocessor/expression-transformer.js — `_tokenize`,
 * `_parseTemplateLiteral`.
 *
 * Template literal được xử lý NGAY tại đây: nội dung `${...}` phải đi ngược
 * lại transformer để dịch đệ quy, nên tokenizer nhận một closure thay vì tham
 * chiếu thẳng tới transformer (tránh phụ thuộc vòng).
 *
 * ⚠️ Quét theo BYTE. Ký tự nhiều byte (tiếng Việt) không khớp lớp ký tự nào
 * nên rơi xuống nhánh "toán tử một ký tự" — bản JS sinh 1 token, bản này sinh
 * 2–3 token. Giá trị nối lại vẫn y hệt, và không phép so sánh token nào trong
 * transformer bị ảnh hưởng (chúng chỉ so với '.', '(', ',' ... đều là ASCII).
 */
final class Tokenizer
{
    private const KEYWORDS = ['true', 'false', 'null', 'as', 'instanceof', 'new', 'typeof'];

    private const OPERATORS_3 = ['===', '!==', '...'];

    private const OPERATORS_2 = [
        '==', '!=', '>=', '<=', '&&', '||', '??', '=>', '++', '--', '+=', '-=', '*=', '/=',
    ];

    /** @param Closure(string): string $transformExpression Dịch đệ quy nội dung `${...}` */
    public function __construct(
        private readonly Closure $transformExpression,
    ) {
    }

    /** @return list<Token> */
    public function tokenize(string $expr): array
    {
        $tokens = [];
        $i = 0;
        $length = strlen($expr);

        while ($i < $length) {
            $ch = $expr[$i];

            if (self::isSpace($ch)) {
                $ws = '';
                while ($i < $length && self::isSpace($expr[$i])) {
                    $ws .= $expr[$i];
                    $i++;
                }
                $tokens[] = new Token(TokenType::Whitespace, $ws);
                continue;
            }

            if ($ch === '`') {
                [$phpExpr, $endPos] = $this->parseTemplateLiteral($expr, $i);
                $tokens[] = new Token(TokenType::TemplateLiteral, $phpExpr);
                $i = $endPos + 1;
                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                $str = $quote;
                $i++;

                while ($i < $length && ! ($expr[$i] === $quote && ($i > 0 ? $expr[$i - 1] : '') !== '\\')) {
                    $str .= $expr[$i];
                    $i++;
                }

                if ($i < $length) {
                    $str .= $expr[$i];
                    $i++;
                }

                $tokens[] = new Token(TokenType::Str, $str);
                continue;
            }

            if (self::isDigit($ch) || ($ch === '.' && $i + 1 < $length && self::isDigit($expr[$i + 1]))) {
                $num = '';
                while ($i < $length && (self::isDigit($expr[$i]) || $expr[$i] === '.')) {
                    $num .= $expr[$i];
                    $i++;
                }
                $tokens[] = new Token(TokenType::Number, $num);
                continue;
            }

            if (self::isIdentStart($ch)) {
                $id = '';
                while ($i < $length && self::isIdentPart($expr[$i])) {
                    $id .= $expr[$i];
                    $i++;
                }

                $tokens[] = new Token(
                    in_array($id, self::KEYWORDS, true) ? TokenType::Keyword : TokenType::Identifier,
                    $id,
                );
                continue;
            }

            // Toán tử 3 ký tự — điều kiện `$i + 2 < $length` giữ nguyên của bản
            // JS, nghĩa là toán tử 3 ký tự ở SÁT cuối chuỗi không được nhận ra.
            if ($i + 2 < $length) {
                $three = substr($expr, $i, 3);
                if (in_array($three, self::OPERATORS_3, true)) {
                    $tokens[] = new Token(TokenType::Operator, $three);
                    $i += 3;
                    continue;
                }
            }

            if ($i + 1 < $length) {
                $two = substr($expr, $i, 2);
                if (in_array($two, self::OPERATORS_2, true)) {
                    $tokens[] = new Token(TokenType::Operator, $two);
                    $i += 2;
                    continue;
                }
            }

            $tokens[] = new Token(TokenType::Operator, $ch);
            $i++;
        }

        return $tokens;
    }

    /**
     * `text ${expr} text` → `'text ' . php_expr . ' text'`.
     *
     * @return array{string, int} [biểu thức PHP, vị trí dấu ` đóng]
     */
    public function parseTemplateLiteral(string $str, int $startPos): array
    {
        /** @var list<array{bool, string}> $parts [làThuầnVănBản, giáTrị] */
        $parts = [];
        $currentText = '';
        $i = $startPos + 1;      // bỏ qua dấu ` mở
        $length = strlen($str);

        while ($i < $length) {
            if ($str[$i] === '`') {
                if ($currentText !== '') {
                    $parts[] = [true, $currentText];
                }
                break;
            }

            if ($str[$i] === '$' && ($str[$i + 1] ?? '') === '{') {
                if ($currentText !== '') {
                    $parts[] = [true, $currentText];
                    $currentText = '';
                }

                $depth = 1;
                $j = $i + 2;

                while ($j < $length && $depth > 0) {
                    if ($str[$j] === '{') {
                        $depth++;
                    } elseif ($str[$j] === '}') {
                        $depth--;
                    }
                    $j++;
                }

                $parts[] = [false, substr($str, $i + 2, $j - 1 - ($i + 2))];
                $i = $j;
                continue;
            }

            if ($str[$i] === '\\' && $i + 1 < $length) {
                $currentText .= $str[$i + 1];
                $i += 2;
                continue;
            }

            $currentText .= $str[$i];
            $i++;
        }

        if ($parts === []) {
            return ["''", $i];
        }

        $phpParts = [];
        foreach ($parts as [$isText, $value]) {
            $phpParts[] = $isText
                ? "'" . str_replace("'", "\\'", $value) . "'"
                : ($this->transformExpression)($value);
        }

        return [implode(' . ', $phpParts), $i];
    }

    /**
     * `/\s/` của JS.
     *
     * Chỉ ASCII. JS còn coi NBSP và các khoảng trắng Unicode khác là \s, nhưng
     * ở đây ta duyệt theo byte nên không nhận ra chúng. Chưa gặp trong 56 view
     * thật lẫn fixture; nếu cổng parity đỏ vì lý do này thì đây là chỗ sửa.
     */
    private static function isSpace(string $ch): bool
    {
        return $ch === ' ' || $ch === "\t" || $ch === "\n"
            || $ch === "\r" || $ch === "\v" || $ch === "\f";
    }

    private static function isDigit(string $ch): bool
    {
        return $ch >= '0' && $ch <= '9';
    }

    private static function isIdentStart(string $ch): bool
    {
        return ($ch >= 'a' && $ch <= 'z') || ($ch >= 'A' && $ch <= 'Z') || $ch === '_';
    }

    /** `/\w/` của JS — ASCII, khớp PCRE không cờ /u. */
    private static function isIdentPart(string $ch): bool
    {
        return self::isIdentStart($ch) || self::isDigit($ch);
    }
}
