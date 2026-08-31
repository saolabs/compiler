<?php

declare(strict_types=1);

namespace Saola\Compiler\Support;

/**
 * Bọc mỏng quanh preg_* để lỗi trở thành exception thay vì null im lặng.
 *
 * Compiler chạy khoảng 740 lệnh regex. preg_* trả null khi lỗi (backtrack
 * limit, pattern hỏng, UTF-8 không hợp lệ) mà không phát tín hiệu gì; giá trị
 * null đó sẽ trôi qua cả pipeline và chỉ lộ ra ở output sai. Bọc một lần ở đây
 * để mọi chỗ gọi đều an toàn.
 *
 * Quy ước byte: KHÔNG dùng cờ /u trừ khi chỗ gọi yêu cầu tường minh. Mọi
 * delimiter mà compiler đi tìm đều là ASCII, và UTF-8 tự đồng bộ, nên offset
 * theo byte luôn rơi đúng biên ký tự. Trộn lẫn `mb_*` với offset của preg là
 * cách chắc chắn nhất để hỏng ngầm khi gặp tiếng Việt.
 */
final class Re
{
    /** Chặn khởi tạo — đây là namespace hàm, không phải object có trạng thái. */
    private function __construct()
    {
    }

    /**
     * @param  array<int|string, mixed> $matches Nhận về theo tham chiếu
     * @param  int $offset Vị trí BYTE bắt đầu tìm. Kết hợp với `\G` trong
     *         pattern để neo cứng vào đúng vị trí đó — tương đương
     *         `regex.match(subject, pos)` của Python.
     */
    public static function match(
        string $pattern,
        string $subject,
        ?array &$matches = [],
        int $flags = 0,
        int $offset = 0,
    ): bool {
        $result = preg_match($pattern, $subject, $matches, $flags, $offset);

        if ($result === false) {
            throw self::error($pattern);
        }

        return $result === 1;
    }

    /**
     * @return list<array<int|string, string>>
     */
    public static function matchAll(
        string $pattern,
        string $subject,
        int $flags = PREG_SET_ORDER,
        int $offset = 0,
    ): array {
        $result = preg_match_all($pattern, $subject, $matches, $flags, $offset);

        if ($result === false) {
            throw self::error($pattern);
        }

        /** @var list<array<int|string, string>> $matches */
        return $matches;
    }

    public static function replace(string $pattern, string $replacement, string $subject, int $limit = -1): string
    {
        $result = preg_replace($pattern, $replacement, $subject, $limit);

        if ($result === null) {
            throw self::error($pattern);
        }

        return $result;
    }

    public static function replaceCallback(string $pattern, callable $callback, string $subject, int $limit = -1): string
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $result = preg_replace_callback($pattern, $callback, $subject, $limit);
        } finally {
            restore_error_handler();
        }

        if ($result === null) {
            throw self::error($pattern, $warning);
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public static function split(string $pattern, string $subject, int $limit = -1, int $flags = 0): array
    {
        $result = preg_split($pattern, $subject, $limit, $flags);

        if ($result === false) {
            throw self::error($pattern);
        }

        /** @var list<string> $result */
        return $result;
    }

    private static function error(string $pattern, ?string $warning = null): RegexException
    {
        return new RegexException(sprintf(
            'preg thất bại với pattern %s: %s',
            $pattern,
            $warning ?? preg_last_error_msg(),
        ));
    }
}
