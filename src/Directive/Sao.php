<?php

declare(strict_types=1);

namespace Saola\Compiler\Directive;

/**
 * Cửa khai báo directive cho ứng dụng và thư viện — dùng trong `sao.directives.php`:
 *
 *     return [
 *         Sao::directive('money', fn (string $e) => "{{ formatMoney({$e}) }}"),
 *         Sao::block('can', fn (string $e, string $body) => "@if(canDo({$e})){$body}@endif"),
 *         Sao::tag('can', fn (string $e) => ["@if(canDo({$e}))", '@endif']),
 *         Sao::attr('tooltip', fn (string $e) => 'data-tooltip="{{ ' . $e . ' }}"'),
 *         Sao::helper('formatMoney'),
 *     ];
 *
 * Mọi handler trả về `.sao`, không phải Blade hay JS. Xem docs/03-directives.md §2.
 */
final class Sao
{
    /** `@name(expr)` → đoạn .sao. */
    public static function directive(string $name, callable $handler, bool $override = false): UserDirective
    {
        return new UserDirective($name, $handler, false, $override);
    }

    /** `@name(expr) … @endname` → đoạn .sao; handler nhận (expr, body). */
    public static function block(string $name, callable $handler, bool $override = false): UserDirective
    {
        return new UserDirective($name, $handler, true, $override);
    }

    /** `<tag #name="expr">` → bọc element giữa `[trước, sau]`; handler nhận (expr, tag). */
    public static function tag(string $name, callable $handler, bool $value = true, bool $override = false): TagDirective
    {
        return new TagDirective($name, $handler, true, $value, $override);
    }

    /**
     * `name(...)` trong biểu thức là helper có ở cả hai phía: hàm PHP toàn cục
     * cho Blade, `App.Helper.name` cho JS. Compiler thôi cảnh báo "tên lạ".
     */
    public static function helper(string $name): UserHelper
    {
        return new UserHelper($name);
    }

    /** `<tag #name="expr">` → thay bằng đoạn thuộc tính .sao; handler nhận (expr, tag). */
    public static function attr(string $name, callable $handler, bool $value = true, bool $override = false): TagDirective
    {
        return new TagDirective($name, $handler, false, $value, $override);
    }
}
