<?php

declare(strict_types=1);

namespace Saola\Compiler\Directive;

use Saola\Compiler\CompileException;

/**
 * `#name="expr"` viết trên thẻ mở — "sao in, sao out" như UserDirective.
 *
 * - wrap: handler trả `[trước, sau]`, element được bọc giữa hai đoạn .sao đó
 *   (giống `#if` bọc thẻ bằng `@if(...)…@endif`).
 * - attr: handler trả một đoạn thuộc tính .sao, thay đúng chỗ `#name="..."`.
 *
 * `$value`: true = bắt buộc có `="..."`, false = cấm (handler nhận '').
 */
final class TagDirective
{
    /** @param callable(string, string): (string|array{0:string,1:string}) $handler */
    public function __construct(
        public readonly string $name,
        private readonly mixed $handler,
        public readonly bool $wraps,
        public readonly bool $value = true,
        public readonly bool $override = false,
    ) {
        if (preg_match('/^[a-z][a-z0-9_-]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("Tên #directive không hợp lệ (chữ thường, số, -, _): {$name}");
        }
        if (!is_callable($handler)) {
            throw new \InvalidArgumentException("Handler của #{$name} không callable.");
        }
    }

    /** @return array{0:string,1:string} */
    public function wrap(string $expression, string $tag): array
    {
        $result = ($this->handler)($expression, $tag);
        if (!is_array($result) || !is_string($result[0] ?? null) || !is_string($result[1] ?? null)) {
            throw new CompileException("#{$this->name} phải trả về [đoạn .sao trước, đoạn .sao sau].");
        }

        return [$result[0], $result[1]];
    }

    public function attributes(string $expression, string $tag): string
    {
        $result = ($this->handler)($expression, $tag);
        if (!is_string($result)) {
            throw new CompileException("#{$this->name} phải trả về chuỗi thuộc tính .sao.");
        }

        return $result;
    }
}
