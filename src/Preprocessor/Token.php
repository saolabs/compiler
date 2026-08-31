<?php

declare(strict_types=1);

namespace Saola\Compiler\Preprocessor;

/**
 * Một token trong biểu thức.
 *
 * Bản JS còn gắn thêm `token.transformedTo` và `token._isProperty` — cả hai
 * chỉ được GHI, không chỗ nào đọc. Không port (docs/06-coding-standards.md §5).
 */
final class Token
{
    public function __construct(
        public readonly TokenType $type,
        public readonly string $value,
    ) {
    }

    public function is(TokenType $type): bool
    {
        return $this->type === $type;
    }
}
