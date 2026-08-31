<?php

declare(strict_types=1);

namespace Saola\Compiler\Source;

/**
 * Một thẻ bọc template tìm được trong file `.sao`.
 *
 * `<sao:blade>`, `<template>` hoặc `<blade>` — thẻ quây lấy phần template,
 * phân biệt với khai báo và `<script>` / `<style>` nằm ngoài.
 */
final class WrapperTag
{
    public function __construct(
        public readonly string $tagName,
        public readonly string $fullMatch,
        public readonly string $innerContent,
        public readonly int $startPos,
        public readonly int $endPos,
    ) {
    }

    /** Thẻ này có nằm HOÀN TOÀN bên trong $other không? */
    public function isInside(self $other): bool
    {
        return $this !== $other
            && $this->startPos > $other->startPos
            && $this->endPos < $other->endPos;
    }

    /** Vị trí $offset..$end có nằm trong thẻ này không? */
    public function covers(int $start, int $end): bool
    {
        return $start >= $this->startPos && $end <= $this->endPos;
    }
}
