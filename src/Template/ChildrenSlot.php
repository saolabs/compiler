<?php

declare(strict_types=1);

namespace Saola\Compiler\Template;

use Saola\Compiler\Support\Re;

/**
 * Hợp đồng cú pháp children slot, dùng chung cho Blade và JS emitter.
 *
 * Children placeholder là ĐIỂM CHÈN, không phải node chứa. Nội dung con vẫn
 * thuộc về component cha; nó chỉ được đổ ra khi renderer chạm tới một trong
 * các placeholder này bên trong component con:
 *
 *     @children
 *     {{ $children }}
 *     {!! $children !!}
 *
 * Port từ compiler/src/common/children_slot.py.
 */
final class ChildrenSlot
{
    public const DATA_NAME = '__ONE_CHILDREN_CONTENT__';

    private const DIRECTIVE = '/@children\b[^\S\r\n]*(?:\([^\S\r\n]*\))?/i';

    private const ECHO = '/(?:\{\{\s*\$children\s*\}\}|\{!!\s*\$children\s*!!\})/';

    /**
     * `@children` bên trong @verbatim là CODE MINH HOẠ, không phải slot: trang
     * docs in cú pháp .sao ra cho người đọc. Đếm hay thay ở đó thì trang docs
     * vừa hiện sai (`{!! $__ONE_CHILDREN_CONTENT__ !!}` thay vì `@children`)
     * vừa có thể bị báo lỗi "chỉ được một children placeholder" chỉ vì nêu ví
     * dụ hai lần.
     */
    private const VERBATIM = '/@verbatim.*?@endverbatim/si';

    private function __construct()
    {
    }

    /** True chỉ với đúng biểu thức dành riêng `$children`. */
    public static function isChildrenExpression(string $expression): bool
    {
        return trim($expression) === '$children';
    }

    public static function count(string $source): int
    {
        $total = 0;

        foreach (self::splitVerbatim($source) as [$segment, $isVerbatim]) {
            if ($isVerbatim) {
                continue;
            }

            $total += count(Re::matchAll(self::DIRECTIVE, $segment))
                + count(Re::matchAll(self::ECHO, $segment));
        }

        return $total;
    }

    public static function has(string $source): bool
    {
        return self::count($source) > 0;
    }

    /** Mỗi template component chỉ được có MỘT điểm chèn. */
    public static function validate(string $source): int
    {
        $count = self::count($source);

        if ($count > 1) {
            throw new ChildrenSlotError(
                'A component template may contain only one children placeholder '
                . '(@children or {{ $children }}).',
            );
        }

        return $count;
    }

    /** Đổi mọi placeholder thành dạng slot raw chuẩn của Blade. */
    public static function replaceForBlade(string $source): string
    {
        return self::applyOutsideVerbatim($source, '{!! $' . self::DATA_NAME . ' !!}');
    }

    /** Chuẩn hoá placeholder cho renderer JS kiểu chuỗi (đường cũ). */
    public static function replaceForLegacyJs(string $source): string
    {
        return self::applyOutsideVerbatim($source, '${' . self::DATA_NAME . "??''}");
    }

    private static function applyOutsideVerbatim(string $source, string $replacement): string
    {
        $out = '';

        foreach (self::splitVerbatim($source) as [$segment, $isVerbatim]) {
            if ($isVerbatim) {
                $out .= $segment;
                continue;
            }

            // Replacement dạng CALLBACK: dùng dạng chuỗi thì '$' trong
            // '{!! $__ONE_CHILDREN_CONTENT__ !!}' bị preg hiểu là tham chiếu nhóm.
            $segment = Re::replaceCallback(self::DIRECTIVE, static fn (): string => $replacement, $segment);
            $out .= Re::replaceCallback(self::ECHO, static fn (): string => $replacement, $segment);
        }

        return $out;
    }

    /**
     * Cắt source thành [(đoạn, có_phải_verbatim)] giữ nguyên thứ tự.
     *
     * @return list<array{string, bool}>
     */
    private static function splitVerbatim(string $source): array
    {
        $segments = [];
        $pos = 0;

        foreach (Re::matchAll(self::VERBATIM, $source, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) as $m) {
            $start = $m[0][1];
            $text = $m[0][0];

            if ($start > $pos) {
                $segments[] = [substr($source, $pos, $start - $pos), false];
            }

            $segments[] = [$text, true];
            $pos = $start + strlen($text);
        }

        $segments[] = [substr($source, $pos), false];

        return $segments;
    }
}
