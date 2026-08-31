<?php

declare(strict_types=1);

namespace Saola\Compiler\Preprocessor;

/**
 * Một mục trong bảng ký hiệu.
 *
 * Bản JS nhét metadata tuỳ ý qua `...extra` (stateOf, assetPath, pattern).
 * Ở đây khai báo tường minh — cả ba trường đều đã dùng thật, và kiểu rõ ràng
 * thì transformer không phải đoán.
 */
final class Symbol
{
    public function __construct(
        public readonly SymbolType $type,
        public readonly string $source,
        /** Với setter: tên state mà nó ghi vào. */
        public readonly ?string $stateOf = null,
        /** Với asset: đường dẫn tương đối trong assets/. */
        public readonly ?string $assetPath = null,
        /** 'destructured' khi ký hiệu đến từ khai báo phá cấu trúc. */
        public readonly ?string $pattern = null,
    ) {
    }
}
