<?php

declare(strict_types=1);

namespace Saola\Compiler\Source;

/**
 * Các phần tách được từ một file `.sao`.
 *
 * Bản JS còn một field `ssrContent` nhưng nó luôn bằng `''` và không nơi nào
 * đọc (index.js:536 gán rồi bỏ đó). Không port — xem docs/06-coding-standards.md §5.
 */
final class SourceParts
{
    /**
     * @param list<string> $declarations Khai báo ở cấp ngoài cùng, THEO ĐÚNG
     *        thứ tự xuất hiện trong file
     * @param string $blade Template dành cho output JS — khối @ssr đã bị bỏ
     * @param string $bladeWithSSR Template dành cho output Blade — giữ nội
     *        dung bên trong khối @ssr
     * @param string $cleanedContent Nội dung sau khi bỏ @ssr và thẻ bọc; dùng
     *        để bóc <script>/<style>/<link> ở tầng trên
     * @param string|null $wrapperType 'sao:blade' | 'template' | 'blade', null
     *        nếu file không có thẻ bọc
     */
    public function __construct(
        public readonly array $declarations,
        public readonly string $blade,
        public readonly string $bladeWithSSR,
        public readonly string $script,
        public readonly string $style,
        public readonly string $cleanedContent,
        public readonly ?string $wrapperType,
    ) {
    }
}
