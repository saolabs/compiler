<?php

declare(strict_types=1);

namespace Saola\Compiler\Directive;

use Saola\Compiler\CompileException;

final class UserDirective
{
    /** @param callable(string, ...string): array{blade:string,js:string} $handler */
    public function __construct(
        public readonly string $name,
        private readonly mixed $handler,
        public readonly bool $block = false,
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

        if (!is_array($result) || !array_key_exists('blade', $result) || !array_key_exists('js', $result)) {
            throw new CompileException(
                "Directive @{$this->name} phải phát đủ hai đích 'blade' và 'js'.",
            );
        }
        if (!is_string($result['blade']) || !is_string($result['js'])) {
            throw new CompileException("Output của @{$this->name} phải là chuỗi.");
        }

        return $result[$target];
    }
}
