<?php

declare(strict_types=1);

namespace Saola\Compiler;

final class BatchResult implements \JsonSerializable
{
    /**
     * @param array<string, CompileResult> $results
     * @param list<array{file:string,message:string}> $errors
     * @param array<string, mixed> $manifest
     */
    public function __construct(
        public readonly array $results,
        public readonly array $errors,
        public readonly array $manifest = [],
    ) {
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'results' => $this->results,
            'errors' => $this->errors,
            'manifest' => $this->manifest,
        ];
    }
}
