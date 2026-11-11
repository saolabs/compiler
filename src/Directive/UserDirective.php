<?php

declare(strict_types=1);

namespace Saola\Compiler\Directive;

use Saola\Compiler\CompileException;

/**
 * `@name(...)` của ứng dụng/thư viện — "sao in, sao out".
 *
 * Handler trả về MỘT đoạn `.sao`; nó được dán lại vào source rồi đi qua cả hai
 * pipeline như code người dùng tự viết, nên marker/reactive/escape đúng sẵn.
 * Dạng `['blade' => sao, 'js' => sao]` chỉ dành cho khi hai đầu buộc phải khác;
 * hai giá trị vẫn là `.sao` và SaolaCompiler soát marker hai bản phải khớp.
 */
final class UserDirective
{
    /** @param callable(string, ...string): (string|array{blade:string,js:string}) $handler */
    public function __construct(
        public readonly string $name,
        private readonly mixed $handler,
        public readonly bool $block = false,
        public readonly bool $override = false,
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("Tên directive không hợp lệ: {$name}");
        }
        if (!is_callable($handler)) {
            throw new \InvalidArgumentException("Handler của @{$name} không callable.");
        }
    }

    public function emit(string $target, string $expression, ?string $body = null): string
    {
        $result = $this->block
            ? ($this->handler)($expression, $body ?? '')
            : ($this->handler)($expression);

        if (is_string($result)) {
            return $result;
        }
        if (!is_array($result) || !array_key_exists('blade', $result) || !array_key_exists('js', $result)
            || !is_string($result['blade']) || !is_string($result['js'])) {
            throw new CompileException(
                "Directive @{$this->name} phải trả về chuỗi .sao, hoặc ['blade' => sao, 'js' => sao].",
            );
        }

        return $result[$target];
    }
}
