<?php

declare(strict_types=1);

namespace Saola\Compiler\Declaration;

use Saola\Compiler\Expr\ExpressionCompiler;
use Saola\Compiler\Support\Balanced;
use Saola\Compiler\Support\Re;

/**
 * Quét mọi khai báo biến trong nguồn, GIỮ ĐÚNG thứ tự xuất hiện.
 *
 * Thứ tự quan trọng: output sinh ra khai báo theo đúng trình tự người dùng
 * viết, nên `@let(b = a + 1)` sau `@let(a = 1)` mới chạy được.
 *
 * Port từ compiler/src/common/declaration_tracker.py. Dùng bởi cả Blade
 * emitter lẫn JS emitter.
 *
 * ⚠️ Các hàm quét trong class này (`splitByComma`, `findFirstEquals`,
 * `findFirstColon`) KHÔNG dùng {@see Balanced}: bản Python ở đây có hành vi
 * riêng — `findFirstEquals` KHÔNG bỏ qua `==`/`=>` như bản trong Balanced, và
 * `splitByComma` không xử lý dấu escape. Dùng chung là đổi hành vi.
 */
final class DeclarationTracker
{
    /** @var list<Declaration> */
    private array $declarations = [];

    public function __construct(
        private readonly ExpressionCompiler $expressions = new ExpressionCompiler(),
    ) {
    }

    /**
     * @return list<Declaration>
     */
    public function parseAll(string $bladeCode): array
    {
        $this->declarations = [];

        // Bỏ <script> để không đọc nhầm mã JS thành khai báo
        $filtered = Re::replace('/<script[^>]*>.*?<\/script>/si', '', $bladeCode);

        // Bỏ @verbatim: khai báo trong đó là VĂN BẢN MẪU
        $filtered = Re::replace('/@verbatim\s*.*?\s*@endverbatim/si', '', $filtered);

        $wrappers = self::findWrappers($filtered);

        // Thứ tự gọi quyết định cách phá hoà khi hai khai báo cùng vị trí —
        // sắp xếp phía dưới là ỔN ĐỊNH ở cả Python lẫn PHP.
        $this->find($filtered, 'vars', '/@vars\s*\(/', $this->parseVarsContent(...));
        $this->find($filtered, 'props', '/@props\s*\(/', $this->parsePropsContent(...));
        $this->find($filtered, 'let', '/@let\s*\(/', $this->parseLetContent(...));
        $this->find($filtered, 'const', '/@const\s*\(/', $this->parseConstContent(...));
        $this->find($filtered, 'useState', '/@useState\s*\(/', $this->parseUseStateContent(...), skipEmpty: true);
        $this->find($filtered, 'states', '/@states\s*\(/', $this->parseStatesContent(...), skipEmpty: true);
        $this->find($filtered, 'computed', '/@computed\s*\(/', $this->parseComputedContent(...), skipEmpty: true);

        // Bỏ khai báo nằm TRONG thẻ bọc template — đó là khai báo cục bộ của
        // template, không phải khai báo cấp view
        $kept = [];
        foreach ($this->declarations as $declaration) {
            $inside = false;

            foreach ($wrappers as [$start, $end]) {
                if ($start <= $declaration->position && $declaration->position < $end) {
                    $inside = true;
                    break;
                }
            }

            if (! $inside) {
                $kept[] = $declaration;
            }
        }

        usort($kept, static fn (Declaration $a, Declaration $b): int => $a->position <=> $b->position);

        $this->declarations = $kept;

        return $this->declarations;
    }

    /**
     * Vùng của các thẻ bọc cấp ngoài cùng.
     *
     * Cài đặt riêng chứ không dùng {@see \Saola\Compiler\Source\WrapperScanner}:
     * bản Python ở đây KHÔNG lọc thẻ lồng nhau và KHÔNG sắp xếp — nó chỉ cần
     * biết "vị trí này có nằm trong thẻ bọc nào không".
     *
     * @return list<array{int, int}>
     */
    private static function findWrappers(string $code): array
    {
        $wrappers = [];

        foreach (['template', 'blade', 'sao:blade'] as $tag) {
            $openTag = '<' . $tag . '>';
            $closeTag = '</' . $tag . '>';
            $openLen = strlen($openTag);
            $closeLen = strlen($closeTag);
            $pos = 0;

            while (true) {
                $openPos = strpos($code, $openTag, $pos);

                if ($openPos === false) {
                    break;
                }

                $depth = 1;
                $searchPos = $openPos + $openLen;
                $closePos = -1;

                while ($searchPos < strlen($code) && $depth > 0) {
                    $nextOpen = strpos($code, $openTag, $searchPos);
                    $nextClose = strpos($code, $closeTag, $searchPos);

                    if ($nextClose === false) {
                        break;
                    }

                    if ($nextOpen !== false && $nextOpen < $nextClose) {
                        $depth++;
                        $searchPos = $nextOpen + $openLen;
                        continue;
                    }

                    $depth--;

                    if ($depth === 0) {
                        $closePos = $nextClose;
                    }

                    $searchPos = $nextClose + $closeLen;
                }

                if ($closePos === -1) {
                    $pos = $openPos + $openLen;
                    continue;
                }

                $wrappers[] = [$openPos, $closePos + $closeLen];
                $pos = $closePos + $closeLen;
            }
        }

        return $wrappers;
    }

    /**
     * @param callable(string): list<array<string, mixed>> $parse
     */
    private function find(string $code, string $type, string $pattern, callable $parse, bool $skipEmpty = false): void
    {
        $offset = 0;
        $length = strlen($code);

        while ($offset < $length) {
            if (! Re::match($pattern, substr($code, $offset), $m, PREG_OFFSET_CAPTURE)) {
                break;
            }

            $matchStart = $offset + $m[0][1];
            $matchEnd = $matchStart + strlen($m[0][0]);

            [$content] = Balanced::extractParensAt($code, $matchEnd - 1);
            $offset = $matchEnd;

            if ($content === null || trim($content) === '') {
                continue;
            }

            $variables = $parse(trim($content));

            if ($skipEmpty && $variables === []) {
                continue;
            }

            $this->declarations[] = new Declaration($type, $matchStart, trim($content), $variables);
        }
    }

    // ── Từng loại khai báo ────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function parsePropsContent(string $content): array
    {
        $content = trim($content);

        if (str_starts_with($content, '[') && str_ends_with($content, ']')) {
            return $this->parsePropsArrayFormat($content);
        }

        return $this->parseVarsContent($content);
    }

    /** @return list<array<string, mixed>> */
    private function parsePropsArrayFormat(string $content): array
    {
        $variables = [];

        foreach (self::splitByComma(trim(substr($content, 1, -1))) as $part) {
            $part = trim($part);

            if (str_contains($part, '=>')) {
                $arrowPos = strpos($part, '=>');
                $key = ltrim(trim(trim(substr($part, 0, (int) $arrowPos)), "'\""), '$');

                $variables[] = [
                    'name' => $key,
                    'value' => $this->toJs(trim(substr($part, (int) $arrowPos + 2))),
                    'hasDefault' => true,
                ];

                continue;
            }

            $name = ltrim(trim(trim($part), "'\""), '$');

            if ($name !== '') {
                $variables[] = ['name' => $name, 'value' => null, 'hasDefault' => false];
            }
        }

        return $variables;
    }

    /**
     * `@vars`/`@props` dạng chuẩn `$a = 0`, object `{ a: 0, b }`, shorthand `{a, b}`.
     *
     * @return list<array<string, mixed>>
     */
    private function parseVarsContent(string $content): array
    {
        $variables = [];
        $isObject = str_starts_with($content, '{') && str_ends_with($content, '}');
        $inner = $isObject ? substr($content, 1, -1) : $content;

        foreach (self::splitByComma($inner) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            // object → ':' ; chuẩn → '='. Object cũng chấp nhận '=' (mặc định shorthand)
            $sepPos = $isObject ? self::findFirstColon($part) : -1;

            if ($sepPos === -1 && str_contains($part, '=')) {
                $sepPos = self::findFirstEquals($part);
            }

            if ($sepPos !== -1) {
                $variables[] = [
                    'name' => ltrim(trim(trim(substr($part, 0, $sepPos)), "'\""), '$'),
                    'value' => $this->toJs(trim(substr($part, $sepPos + 1))),
                    'hasDefault' => true,
                ];

                continue;
            }

            $variables[] = [
                'name' => ltrim(trim(trim($part), "'\""), '$'),
                'value' => null,
                'hasDefault' => false,
            ];
        }

        return $variables;
    }

    /** @return list<array<string, mixed>> */
    private function parseLetContent(string $content): array
    {
        $variables = [];

        foreach (self::splitByComma($content) as $part) {
            $part = ltrim(trim($part), '$');

            if (self::isDestructuring($part)) {
                $info = $this->parseDestructuring($part);

                if ($info !== null) {
                    $variables[] = $info;
                }

                continue;
            }

            if (str_contains($part, '=')) {
                $eq = self::findFirstEquals($part);

                if ($eq !== -1) {
                    $variables[] = [
                        'name' => ltrim(trim(substr($part, 0, $eq)), '$'),
                        'value' => $this->toJs(trim(substr($part, $eq + 1))),
                        'hasDefault' => true,
                        'isDestructuring' => false,
                    ];
                }

                continue;
            }

            $variables[] = [
                'name' => ltrim(trim($part), '$'),
                'value' => null,
                'hasDefault' => false,
                'isDestructuring' => false,
            ];
        }

        return $variables;
    }

    /** @return list<array<string, mixed>> */
    private function parseConstContent(string $content): array
    {
        $variables = [];

        foreach (self::splitByComma($content) as $part) {
            $part = ltrim(trim($part), '$');

            if (self::isDestructuring($part)) {
                $info = $this->parseDestructuring($part);

                if ($info !== null) {
                    if (is_string($info['value']) && str_contains($info['value'], 'useState(')) {
                        $info['isUseState'] = true;
                    }

                    $variables[] = $info;
                }

                continue;
            }

            if (str_contains($part, '=')) {
                $eq = self::findFirstEquals($part);

                if ($eq !== -1) {
                    $variables[] = [
                        'name' => ltrim(trim(substr($part, 0, $eq)), '$'),
                        'value' => $this->toJs(trim(substr($part, $eq + 1))),
                        'hasDefault' => true,
                        'isDestructuring' => false,
                        'isUseState' => false,
                    ];
                }
            }
        }

        return $variables;
    }

    /** `@computed(name = expr)` — chỉ dạng gán, không hỗ trợ phá cấu trúc. */
    private function parseComputedContent(string $content): array
    {
        $variables = [];

        foreach (self::splitByComma($content) as $part) {
            $part = ltrim(trim($part), '$');

            if (! str_contains($part, '=')) {
                continue;
            }

            $eq = self::findFirstEquals($part);

            if ($eq === -1) {
                continue;
            }

            $name = ltrim(trim(substr($part, 0, $eq)), '$');

            if ($name === '' || ! Re::match('/^[A-Za-z_]\w*$/', $name)) {
                continue;
            }

            $valuePhp = trim(substr($part, $eq + 1));

            $variables[] = [
                'name' => $name,
                'value' => $this->toJs($valuePhp),
                'valuePhp' => $valuePhp,
            ];
        }

        return $variables;
    }

    /** @return list<array<string, mixed>> */
    private function parseUseStateContent(string $content): array
    {
        $content = trim($content);

        if (str_starts_with($content, '[') && str_ends_with($content, ']')) {
            return $this->parseUseStateArrayFormat($content);
        }

        $parts = self::splitByComma($content);

        // Dạng 2 tham số: @useState($varName, value) — setter sinh tự động
        if (count($parts) === 2) {
            $varName = trim($parts[0]);

            if (str_starts_with($varName, '$')) {
                $varName = ltrim($varName, '$');

                return [self::destructuredRecord(
                    [$varName, self::setterName($varName)],
                    'useState(' . $this->toJs(trim($parts[1])) . ')',
                )];
            }
        }

        // Dạng 3 tham số: @useState($value, $varName, $setVarName)
        if (count($parts) === 3) {
            return [self::destructuredRecord(
                [ltrim(trim($parts[1]), '$'), ltrim(trim($parts[2]), '$')],
                'useState(' . $this->toJs(ltrim(trim($parts[0]), '$')) . ')',
            )];
        }

        return [];
    }

    /** @return list<array<string, mixed>> */
    private function parseUseStateArrayFormat(string $content): array
    {
        $variables = [];

        foreach (self::splitByComma(trim(substr($content, 1, -1))) as $part) {
            $part = trim($part);

            if (! str_contains($part, '=>')) {
                continue;
            }

            $arrowPos = (int) strpos($part, '=>');
            $key = ltrim(trim(trim(substr($part, 0, $arrowPos)), "'\""), '$');

            $variables[] = self::destructuredRecord(
                [$key, self::setterName($key)],
                'useState(' . $this->toJs(trim(substr($part, $arrowPos + 2))) . ')',
            );
        }

        return $variables;
    }

    /** @return list<array<string, mixed>> */
    private function parseStatesContent(string $content): array
    {
        $content = trim($content);

        if (str_starts_with($content, '[') && str_ends_with($content, ']')) {
            return $this->parseUseStateArrayFormat($content);
        }

        if (str_starts_with($content, '{') && str_ends_with($content, '}')) {
            return $this->parseStatesObjectFormat($content);
        }

        return $this->parseStatesStandardFormat($content);
    }

    /** @return list<array<string, mixed>> */
    private function parseStatesObjectFormat(string $content): array
    {
        $variables = [];

        foreach (self::splitByComma(trim(substr($content, 1, -1))) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            $colon = self::findFirstColon($part);

            if ($colon !== -1) {
                $name = ltrim(trim(trim(substr($part, 0, $colon)), "'\""), '$');
                $valueJs = $this->toJs(trim(substr($part, $colon + 1)));
            } else {
                $name = ltrim(trim(trim($part), "'\""), '$');
                $valueJs = 'null';
            }

            if ($name !== '') {
                $variables[] = self::destructuredRecord(
                    [$name, self::setterName($name)],
                    'useState(' . $valueJs . ')',
                );
            }
        }

        return $variables;
    }

    /** @return list<array<string, mixed>> */
    private function parseStatesStandardFormat(string $content): array
    {
        $variables = [];

        foreach (self::splitByComma($content) as $part) {
            $part = trim($part);
            $eq = self::findFirstEquals($part);

            if ($eq !== -1) {
                $name = ltrim(trim(substr($part, 0, $eq)), '$');
                $valueJs = $this->toJs(trim(substr($part, $eq + 1)));
            } else {
                $name = ltrim(trim($part), '$');
                $valueJs = 'null';
            }

            if ($name !== '') {
                $variables[] = self::destructuredRecord(
                    [$name, self::setterName($name)],
                    'useState(' . $valueJs . ')',
                );
            }
        }

        return $variables;
    }

    /** @return array<string, mixed>|null */
    private function parseDestructuring(string $part): ?array
    {
        $eq = self::findFirstEquals($part);

        if ($eq === -1) {
            return null;
        }

        $left = trim(substr($part, 0, $eq));
        $right = trim(substr($part, $eq + 1));

        if (str_starts_with($left, '[') && str_contains($left, ']')) {
            $inner = substr($left, 1, (int) strpos($left, ']') - 1);
        } elseif (str_starts_with($left, '{') && str_contains($left, '}')) {
            $inner = substr($left, 1, (int) strpos($left, '}') - 1);
        } else {
            return null;
        }

        $names = array_map(
            static fn (string $v): string => ltrim(trim($v), '$'),
            explode(',', $inner),
        );

        $rightJs = $this->toJs($right);

        return [
            'names' => array_values($names),
            'value' => $rightJs,
            'isDestructuring' => true,
            'destructuringType' => str_contains($left, '[') ? 'array' : 'object',
            'isUseState' => str_contains($rightJs, 'useState('),
        ];
    }

    /**
     * @param  list<string> $names
     * @return array<string, mixed>
     */
    private static function destructuredRecord(array $names, string $value): array
    {
        return [
            'names' => $names,
            'value' => $value,
            'isDestructuring' => true,
            'destructuringType' => 'array',
            'isUseState' => true,
        ];
    }

    private static function setterName(string $varName): string
    {
        return $varName === '' ? 'setValue' : 'set' . ucfirst($varName);
    }

    private static function isDestructuring(string $part): bool
    {
        return (str_contains($part, '[') && str_contains($part, ']') && str_contains($part, '='))
            || (str_contains($part, '{') && str_contains($part, '}') && str_contains($part, '='));
    }

    private function toJs(string $expr): string
    {
        return Re::replace('/\$(\w+)/', '${1}', $this->expressions->compileStatement($expr));
    }

    // ── Quét chuỗi riêng của module này ───────────────────────────────

    /**
     * Tách theo dấu phẩy ở mức 0. KHÔNG xử lý dấu escape (khác
     * {@see Balanced::splitTopLevelStripped}) — giữ đúng hành vi bản Python.
     *
     * @return list<string>
     */
    private static function splitByComma(string $text): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $inString = false;
        $stringChar = '';

        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            $char = $text[$i];

            if ($char === '"' || $char === "'") {
                if ($inString && $char === $stringChar) {
                    $inString = false;
                } elseif (! $inString) {
                    $inString = true;
                    $stringChar = $char;
                }
            } elseif (! $inString) {
                if ($char === '(' || $char === '[' || $char === '{') {
                    $depth++;
                } elseif ($char === ')' || $char === ']' || $char === '}') {
                    $depth--;
                } elseif ($char === ',' && $depth === 0) {
                    $parts[] = trim($current);
                    $current = '';
                    continue;
                }
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }

    /**
     * Dấu '=' đầu tiên ở mức 0.
     *
     * ⚠️ KHÔNG bỏ qua `==`, `=>`, `!=` — khác hẳn
     * {@see Balanced::findAssignment}. Bản Python ở đây trả về dấu '=' đầu
     * tiên bất kể nó thuộc toán tử nào; đổi đi là lệch parity.
     */
    private static function findFirstEquals(string $text): int
    {
        $depth = 0;
        $inString = false;
        $stringChar = '';

        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            $char = $text[$i];

            if ($char === '"' || $char === "'") {
                if ($inString && $char === $stringChar) {
                    $inString = false;
                } elseif (! $inString) {
                    $inString = true;
                    $stringChar = $char;
                }
            } elseif (! $inString) {
                if ($char === '(' || $char === '[' || $char === '{') {
                    $depth++;
                } elseif ($char === ')' || $char === ']' || $char === '}') {
                    $depth--;
                } elseif ($char === '=' && $depth === 0) {
                    return $i;
                }
            }
        }

        return -1;
    }

    /** Dấu ':' đầu tiên ở mức 0, ngoài nháy — dùng cho object `{ key: value }`. */
    private static function findFirstColon(string $var): int
    {
        $paren = 0;
        $bracket = 0;
        $brace = 0;
        $inQuotes = false;
        $quoteChar = '';

        for ($i = 0, $n = strlen($var); $i < $n; $i++) {
            $char = $var[$i];

            if (($char === '"' || $char === "'") && ! $inQuotes) {
                $inQuotes = true;
                $quoteChar = $char;
            } elseif ($inQuotes && $char === $quoteChar) {
                $inQuotes = false;
                $quoteChar = '';
            } elseif (! $inQuotes) {
                match ($char) {
                    '(' => $paren++,
                    ')' => $paren--,
                    '[' => $bracket++,
                    ']' => $bracket--,
                    '{' => $brace++,
                    '}' => $brace--,
                    default => null,
                };

                if ($char === ':' && $paren === 0 && $bracket === 0 && $brace === 0) {
                    return $i;
                }
            }
        }

        return -1;
    }
}
