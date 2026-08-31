<?php

declare(strict_types=1);

namespace Saola\Compiler\Preprocessor;

use Saola\Compiler\Support\Balanced;
use Saola\Compiler\Support\Re;

/**
 * Bảng alias khai báo bằng `@import`, dùng để gỡ đường dẫn view.
 *
 *   @import(<path> as tên)        → tên        -> <path>
 *   @import({ tên: <path>, ... }) → tên        -> <path>
 *   @import(<path>)               → tên suy ra -> <path>
 *
 * Alias là ĐIỂM NEO lúc biên dịch, không phải biến runtime: `@extends(layout)`
 * phải thành `@extends(__layout__ + 'base')` TRƯỚC khi biểu thức được dịch.
 * Không gỡ thì identifier trần bị thêm '$' → Blade ra `@extends($layout)` (biến
 * không tồn tại) còn JS ra `superView: layout` (ReferenceError).
 *
 * Port từ expression-transformer.js — collectImportAliases, deriveImportName.
 */
final class ImportAliases
{
    /** @var array<string, string> */
    private array $aliases = [];

    public function isEmpty(): bool
    {
        return $this->aliases === [];
    }

    public function has(string $name): bool
    {
        return isset($this->aliases[$name]);
    }

    public function get(string $name): ?string
    {
        return $this->aliases[$name] ?? null;
    }

    /**
     * Quét `@import` trong nội dung `.sao` GỐC (chưa transform).
     *
     * Alias tường minh THẮNG tên suy ra khi trùng — nên tên suy ra được gom
     * riêng rồi mới trộn vào sau.
     */
    public function collect(string $content): self
    {
        $this->aliases = [];

        if ($content === '') {
            return $this;
        }

        /** @var array<string, string> $derived */
        $derived = [];
        $offset = 0;
        $length = strlen($content);

        while ($offset < $length) {
            if (! Re::match('/@import\s*\(/', substr($content, $offset), $m, PREG_OFFSET_CAPTURE)) {
                break;
            }

            $start = $offset + $m[0][1] + strlen($m[0][0]);
            $depth = 1;
            $i = $start;

            while ($i < $length && $depth > 0) {
                if ($content[$i] === '(') {
                    $depth++;
                } elseif ($content[$i] === ')') {
                    $depth--;
                }
                $i++;
            }

            if ($depth !== 0) {
                break;
            }

            $inner = trim(substr($content, $start, $i - 1 - $start));
            $offset = $i;

            // Dạng object: @import({ counter: 'a.b', demo: __template__ + 'c' })
            if (str_starts_with($inner, '{') && str_ends_with($inner, '}')) {
                foreach (Balanced::splitTopLevelLoose(substr($inner, 1, -1), ',') as $entry) {
                    $colon = strpos($entry, ':');

                    if ($colon === false) {
                        continue;
                    }

                    $name = Re::replace('/^[\'"]|[\'"]$/', '', trim(substr($entry, 0, $colon)));
                    $path = trim(substr($entry, $colon + 1));

                    if ($name !== '' && $path !== '') {
                        $this->aliases[$name] = $path;
                    }
                }

                continue;
            }

            // Dạng 'as': @import(<path> as tên)
            if (Re::match('/^([\s\S]+?)\s+as\s+([A-Za-z_][\w-]*)$/', $inner, $as)) {
                $this->aliases[$as[2]] = trim($as[1]);
                continue;
            }

            $name = self::deriveName($inner);

            if ($name !== null) {
                $derived[$name] = $inner;
            }
        }

        foreach ($derived as $name => $path) {
            if (! isset($this->aliases[$name])) {
                $this->aliases[$name] = $path;
            }
        }

        return $this;
    }

    /**
     * Suy tên từ biểu thức đường dẫn `@import`.
     *
     *   __layout__ + 'docs'             → docs
     *   __layout__ + 'docs.test-layout' → test-layout
     *   'a'                             → a
     *   $__blade_custom_path__          → blade_custom_path
     *
     * PHẢI dùng cùng luật với import_parser.py::_extract_tag_from_path — tên
     * thẻ và alias mà trỏ hai nơi khác nhau là hỏng.
     */
    public static function deriveName(string $path): ?string
    {
        $path = trim($path);

        if ($path === '') {
            return null;
        }

        // Chuỗi literal CUỐI trong biểu thức → đoạn sau dấu chấm cuối
        $literals = Re::matchAll('/[\'"]([^\'"]+)[\'"]/', $path);

        if ($literals !== []) {
            $last = $literals[count($literals) - 1][1];
            $parts = explode('.', $last);
            $name = $parts[count($parts) - 1];

            return $name === '' ? null : $name;
        }

        // Tên biến PHP: $__custom_path__ → custom_path.
        // Dấu '$' là BẮT BUỘC: path trong .sao chưa có '$' nên rơi xuống nhánh
        // cuối và giữ nguyên gạch dưới. Bỏ '$' đi là JS ra 'blade_custom_path'
        // còn Python ra '__blade_custom_path__' → tên thẻ và alias lệch nhau.
        if (Re::match('/^\$_*([a-zA-Z][a-zA-Z0-9_]*?)_*$/', $path, $var)) {
            return $var[1];
        }

        $clean = Re::replace('/[^a-zA-Z0-9_]/', '', $path);

        return $clean === '' ? null : $clean;
    }

    /**
     * Thay alias ở vị trí đối số đường dẫn bằng chính biểu thức path.
     *
     * Chỉ khớp identifier TRẦN — `@include(counter, {...})` thì đối số 0 phải
     * đúng bằng tên alias, không phải chứa nó.
     */
    public function resolveViewArg(string $inner, int $viewArgIndex): string
    {
        if ($this->isEmpty()) {
            return $inner;
        }

        $args = Balanced::splitTopLevelLoose($inner, ',');

        if (count($args) <= $viewArgIndex) {
            return $inner;
        }

        $arg = $args[$viewArgIndex];
        $name = trim($arg);

        if (! $this->has($name)) {
            return $inner;
        }

        $at = strpos($arg, $name);

        if ($at === false) {
            return $inner;
        }

        $args[$viewArgIndex] = substr($arg, 0, $at)
            . $this->aliases[$name]
            . substr($arg, $at + strlen($name));

        return implode(',', $args);
    }
}
