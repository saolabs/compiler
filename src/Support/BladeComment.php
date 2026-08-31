<?php

declare(strict_types=1);

namespace Saola\Compiler\Support;

/**
 * Làm trắng vùng `{{-- --}}` và `@verbatim`, GIỮ NGUYÊN độ dài.
 *
 * Mọi khâu quét file `.sao` (khai báo, thẻ `<script>`/`<style>`, CSS scoped)
 * đều đọc thô toàn văn bản, nên ví dụ minh hoạ trong comment bị coi là mã thật:
 *
 *     {{-- @props({gia: 1}) --}}          → prop "gia" được đăng ký thật
 *     {{-- <style scoped>.x{}</style> --}} → CSS lọt vào stylesheet, và vì
 *                                            scope class suy từ CHÍNH nội dung
 *                                            CSS nên class của MỌI element đổi
 *
 * Trang tài liệu là nơi dính nặng nhất — nó tồn tại để in ra cú pháp Saola.
 *
 * Thay bằng khoảng trắng chứ không xoá: độ dài giữ nguyên nên mọi offset tính
 * trên bản làm trắng vẫn trỏ đúng vào bản gốc. Nhờ đó chỗ nào cần giữ lại
 * comment trong output (vd tách template) chỉ việc dùng offset để cắt bản gốc.
 *
 * Xuống dòng được giữ: nhiều khâu quét theo dòng, nuốt mất '\n' là lệch dòng.
 */
final class BladeComment
{
    private const PATTERN = '/\{\{--[\s\S]*?--\}\}|@verbatim\b[\s\S]*?@endverbatim\b/i';

    private function __construct()
    {
    }

    public static function blank(string $content): string
    {
        if ($content === '') {
            return $content;
        }

        return Re::replaceCallback(
            self::PATTERN,
            static fn (array $m): string => Re::replace('/[^\n]/', ' ', $m[0]),
            $content,
        );
    }
}
