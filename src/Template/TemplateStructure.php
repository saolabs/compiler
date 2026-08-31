<?php

declare(strict_types=1);

namespace Saola\Compiler\Template;

use Saola\Compiler\Support\Re;

/**
 * Kiểm tra cấu trúc thẻ component được import — dùng chung cho Blade và JS.
 *
 * Hai đích phân giải thẻ import ĐỘC LẬP nhau. Nguồn hỏng phải fail TRƯỚC khi
 * bên nào viết lại thẻ; nếu không, Blade có thể giữ lại một thẻ đóng lơ lửng
 * trong khi cây AST phía JS lặng lẽ bỏ qua nó — ra hai cây DOM khác nhau.
 *
 * HTML gốc cố ý để cho pipeline HTML/AST lo. Thẻ import thì nghiêm ngặt vì
 * chúng trở thành ranh giới vòng đời component.
 *
 * Port từ compiler/src/common/template_structure.py.
 */
final class TemplateStructure
{
    private const TAG_NAME = '/\G[A-Za-z][\w-]*/';

    /** Nội dung bên trong các thẻ này là văn bản thô, không phải markup. */
    private const RAWTEXT_TAGS = ['script', 'style', 'textarea', 'title'];

    private function __construct()
    {
    }

    /**
     * @param array<string, string> $imports thẻ → đường dẫn
     *
     * @throws TemplateStructureError
     */
    public static function validate(string $source, array $imports): void
    {
        if ($imports === []) {
            return;
        }

        /** @var list<array{string, int}> $stack [tên thẻ, vị trí mở] */
        $stack = [];
        $pos = 0;
        $length = strlen($source);

        while ($pos < $length) {
            if (str_starts_with(substr($source, $pos, 4), '{{--')) {
                $end = strpos($source, '--}}', $pos + 4);
                $pos = $end === false ? $length : $end + 4;
                continue;
            }

            if (str_starts_with(substr($source, $pos, 4), '<!--')) {
                $end = strpos($source, '-->', $pos + 4);
                $pos = $end === false ? $length : $end + 3;
                continue;
            }

            if ($source[$pos] !== '<') {
                $pos++;
                continue;
            }

            $cursor = $pos + 1;
            $isClosing = false;

            if ($cursor < $length && $source[$cursor] === '/') {
                $isClosing = true;
                $cursor++;
            }

            while ($cursor < $length && ctype_space($source[$cursor])) {
                $cursor++;
            }

            // \G neo vào đúng $cursor — tương đương regex.match(source, cursor)
            if (! Re::match(self::TAG_NAME, $source, $m, PREG_OFFSET_CAPTURE, $cursor)) {
                $pos++;
                continue;
            }

            $tagName = $m[0][0];
            $nameEnd = $cursor + strlen($tagName);
            $tagEnd = self::scanTagEnd($source, $nameEnd);
            $tagSource = substr($source, $pos, $tagEnd - $pos);

            // Không soi văn bản giống template bên trong thẻ raw/RCDATA gốc
            if (! $isClosing && in_array(strtolower($tagName), self::RAWTEXT_TAGS, true)) {
                $rest = substr($source, $tagEnd);
                $closePattern = '/<\/\s*' . preg_quote($tagName, '/') . '\s*>/i';

                if (Re::match($closePattern, $rest, $close, PREG_OFFSET_CAPTURE)) {
                    $pos = $tagEnd + $close[0][1] + strlen($close[0][0]);
                } else {
                    $pos = $length;
                }

                continue;
            }

            if (! isset($imports[$tagName])) {
                $pos = $tagEnd;
                continue;
            }

            if ($isClosing) {
                if ($stack === []) {
                    throw self::error(
                        $source,
                        $pos,
                        "Unexpected closing component tag </{$tagName}>; "
                        . "<{$tagName} /> is already self-closing",
                    );
                }

                [$openName, $openPos] = $stack[count($stack) - 1];

                if ($openName !== $tagName) {
                    [$openLine, $openColumn] = self::location($source, $openPos);

                    throw self::error(
                        $source,
                        $pos,
                        "Mismatched closing component tag </{$tagName}>; expected "
                        . "</{$openName}> for <{$openName}> opened at line "
                        . "{$openLine}, column {$openColumn}",
                    );
                }

                array_pop($stack);
            } elseif (! Re::match('/\/\s*>$/', $tagSource)) {
                $stack[] = [$tagName, $pos];
            }

            $pos = $tagEnd;
        }

        if ($stack !== []) {
            [$tagName, $openPos] = $stack[count($stack) - 1];

            throw self::error(
                $source,
                $openPos,
                "Unclosed component tag <{$tagName}>; expected </{$tagName}>",
            );
        }
    }

    /** Vị trí ngay sau dấu `>`, có tôn trọng thuộc tính nằm trong nháy. */
    private static function scanTagEnd(string $source, int $start): int
    {
        $quote = null;
        $escaped = false;
        $length = strlen($source);

        for ($pos = $start; $pos < $length; $pos++) {
            $char = $source[$pos];

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }

            if ($char === '>') {
                return $pos + 1;
            }
        }

        return $length;
    }

    /**
     * Dòng và CỘT của một vị trí.
     *
     * Cột tính theo CODEPOINT chứ không theo byte: thông báo lỗi phải trùng với
     * bản Python, mà Python đánh chỉ số theo codepoint. Dòng thì đếm '\n' nên
     * byte hay codepoint đều như nhau.
     *
     * @return array{int, int}
     */
    private static function location(string $source, int $index): array
    {
        $line = substr_count(substr($source, 0, $index), "\n") + 1;
        $lineStart = strrpos(substr($source, 0, $index), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;

        $column = mb_strlen(substr($source, $lineStart, $index - $lineStart), 'UTF-8') + 1;

        return [$line, $column];
    }

    private static function error(string $source, int $index, string $message): TemplateStructureError
    {
        [$line, $column] = self::location($source, $index);

        return new TemplateStructureError("{$message} at line {$line}, column {$column}.");
    }
}
