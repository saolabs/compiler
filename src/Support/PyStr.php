<?php

declare(strict_types=1);

namespace Saola\Compiler\Support;

/**
 * Các vị từ chuỗi của Python mà PHP không có sẵn tương đương chính xác.
 *
 * Bản Python dùng `str.isdigit()` / `str.isalnum()` để rẽ nhánh. Hai hàm đó
 * hiểu Unicode: `'chào'.isalnum()` là True, trong khi `ctype_alnum('chào')`
 * là false. Với template chứa tiếng Việt, khác biệt đó đủ để rẽ sai nhánh.
 *
 * Gom về một chỗ để quyết định ngữ nghĩa chỉ phải đưa ra một lần, và khi cổng
 * parity chỉ ra sai lệch thì chỉ có một chỗ để sửa.
 */
final class PyStr
{
    private function __construct()
    {
    }

    /**
     * Tương đương str.isdigit().
     *
     * Cố ý giữ ASCII: chữ số trong mã nguồn luôn là ASCII, còn các ký tự chữ
     * số Unicode (chỉ số trên, chữ số Ả Rập) không xuất hiện ở vị trí mà hàm
     * này được gọi.
     */
    public static function isDigit(string $value): bool
    {
        return $value !== '' && ctype_digit($value);
    }

    /**
     * Tương đương str.isalnum() — CÓ hiểu Unicode, khác với ctype_alnum().
     *
     * Cần Unicode ở đây vì hàm này chạy trên giá trị phần tử mảng, mà giá trị
     * đó có thể là văn bản tiếng Việt không nằm trong nháy.
     */
    public static function isAlnum(string $value): bool
    {
        return $value !== '' && Re::match('/^[\p{L}\p{N}]+$/u', $value);
    }
}
