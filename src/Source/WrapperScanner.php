<?php

declare(strict_types=1);

namespace Saola\Compiler\Source;

use Saola\Compiler\Support\BladeComment;

/**
 * Tìm các thẻ bọc template ở CẤP NGOÀI CÙNG trong file `.sao`.
 *
 * Không dùng regex: thẻ bọc lồng nhau được (`<template>` bên trong
 * `<template>`), mà regex thì không đếm được độ sâu. Quét theo vị trí và đếm
 * cấp giống hệt bản JS.
 *
 * Port từ compiler/src/index.js — hàm findLevel0Wrappers trong parseSaoFile.
 */
final class WrapperScanner
{
    /**
     * Thứ tự có ý nghĩa: gom kết quả theo đúng trình tự này rồi mới sắp xếp
     * theo vị trí. Cả JS lẫn PHP đều sắp xếp ỔN ĐỊNH, nên hai thẻ trùng vị trí
     * xuất phát sẽ giữ nguyên thứ tự tương đối — đổi thứ tự mảng này có thể
     * đổi kết quả.
     */
    private const TAGS = ['sao:blade', 'template', 'blade'];

    /**
     * Mọi thẻ bọc cấp ngoài cùng, sắp xếp theo vị trí xuất phát.
     *
     * @return list<WrapperTag>
     */
    public function scan(string $text): array
    {
        $found = [];

        // Quét vị trí trên bản ĐÃ LÀM TRẮNG comment, nhưng cắt nội dung từ bản
        // GỐC. `{{-- <template>...</template> --}}` mà không che thì thẻ bọc
        // trong CHÚ THÍCH bị coi là thẻ bọc thật: template thật bị bỏ qua và
        // view render nội dung comment. Làm trắng giữ nguyên độ dài nên mọi
        // offset còn dùng được (§16 — chỗ này bị bỏ sót).
        $scan = BladeComment::blank($text);

        foreach (self::TAGS as $tagName) {
            foreach ($this->findByTag($scan, $tagName, $text) as $wrapper) {
                $found[] = $wrapper;
            }
        }

        // Loại thẻ nằm trong thẻ khác — chỉ giữ cấp ngoài cùng thật sự
        $levelZero = [];
        foreach ($found as $wrapper) {
            $isNested = false;

            foreach ($found as $other) {
                if ($wrapper->isInside($other)) {
                    $isNested = true;
                    break;
                }
            }

            if (! $isNested) {
                $levelZero[] = $wrapper;
            }
        }

        usort($levelZero, static fn (WrapperTag $a, WrapperTag $b): int => $a->startPos <=> $b->startPos);

        return $levelZero;
    }

    /**
     * Mọi cặp thẻ mở/đóng khớp nhau của một tên thẻ, có đếm độ sâu.
     *
     * @return list<WrapperTag>
     */
    private function findByTag(string $text, string $tagName, string $original): array
    {
        $openTag = '<' . $tagName . '>';
        $closeTag = '</' . $tagName . '>';
        $openLength = strlen($openTag);
        $closeLength = strlen($closeTag);

        $wrappers = [];
        $pos = 0;

        while (true) {
            $openPos = strpos($text, $openTag, $pos);

            if ($openPos === false) {
                break;
            }

            $closePos = $this->findMatchingClose($text, $openPos + $openLength, $openTag, $closeTag);

            if ($closePos === -1) {
                // Không có thẻ đóng khớp — nhảy qua thẻ mở này rồi tìm tiếp
                $pos = $openPos + $openLength;
                continue;
            }

            $innerStart = $openPos + $openLength;
            $end = $closePos + $closeLength;

            $wrappers[] = new WrapperTag(
                tagName: $tagName,
                fullMatch: substr($original, $openPos, $end - $openPos),
                innerContent: substr($original, $innerStart, $closePos - $innerStart),
                startPos: $openPos,
                endPos: $end,
            );

            $pos = $end;
        }

        return $wrappers;
    }

    /** Vị trí thẻ đóng khớp với thẻ mở, hoặc -1 nếu không có. */
    private function findMatchingClose(string $text, int $searchPos, string $openTag, string $closeTag): int
    {
        $depth = 1;
        $length = strlen($text);

        while ($searchPos < $length && $depth > 0) {
            $nextOpen = strpos($text, $openTag, $searchPos);
            $nextClose = strpos($text, $closeTag, $searchPos);

            if ($nextClose === false) {
                return -1;
            }

            if ($nextOpen !== false && $nextOpen < $nextClose) {
                $depth++;
                $searchPos = $nextOpen + strlen($openTag);
                continue;
            }

            $depth--;

            if ($depth === 0) {
                return $nextClose;
            }

            $searchPos = $nextClose + strlen($closeTag);
        }

        return -1;
    }
}
