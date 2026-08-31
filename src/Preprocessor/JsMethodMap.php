<?php

declare(strict_types=1);

namespace Saola\Compiler\Preprocessor;

/**
 * Ánh xạ method kiểu JS sang hàm PHP tương đương.
 *
 *   items.length   →  count($items)
 *   str.upper()    →  strtoupper($str)
 *   arr.join(',')  →  implode(',', $arr)
 *
 * Port từ expression-transformer.js::_getMethodMapping.
 *
 * Chú ý thứ tự đối số: `replace` và `map` đặt ĐỐI SỐ trước ĐỐI TƯỢNG, còn lại
 * thì ngược lại — đó là quy ước của hàm PHP tương ứng, không phải nhầm lẫn.
 */
final class JsMethodMap
{
    private function __construct()
    {
    }

    /**
     * @param  string $method Tên method phía JS
     * @param  string $obj    Biểu thức đối tượng, đã ở dạng PHP
     * @param  string $args   Đối số, đã ở dạng PHP (chuỗi rỗng nếu không có)
     * @return string|null    null nếu không có ánh xạ
     */
    public static function map(string $method, string $obj, string $args): ?string
    {
        return match ($method) {
            // Chuỗi
            'upper' => "strtoupper({$obj})",
            'lower' => "strtolower({$obj})",
            'trim' => "trim({$obj})",
            'replace' => "str_replace({$args}, {$obj})",
            'contains' => "str_contains({$obj}, {$args})",
            'startsWith' => "str_starts_with({$obj}, {$args})",
            'endsWith' => "str_ends_with({$obj}, {$args})",
            'split' => "explode({$args}, {$obj})",

            // Mảng
            'join' => "implode({$args}, {$obj})",
            'includes' => "in_array({$args}, {$obj})",
            'push' => "array_push({$obj}, {$args})",
            'pop' => "array_pop({$obj})",
            'shift' => "array_shift({$obj})",
            'reverse' => "array_reverse({$obj})",
            'first' => "reset({$obj})",
            'last' => "end({$obj})",
            'keys' => "array_keys({$obj})",
            'values' => "array_values({$obj})",
            'unique' => "array_unique({$obj})",
            'merge' => "array_merge({$obj}, {$args})",
            'filter' => "array_filter({$obj}, {$args})",
            'map' => "array_map({$args}, {$obj})",
            'sort' => "sort({$obj})",
            'slice' => "array_slice({$obj}, {$args})",

            default => null,
        };
    }
}
