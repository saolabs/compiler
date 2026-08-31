<?php

declare(strict_types=1);

namespace Saola\Compiler;

final class CompileResult implements \JsonSerializable
{
    /**
     * @param list<string> $css
     * @param array<string, string> $imports
     * @param list<string> $markers
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly ?string $blade,
        public readonly ?string $js,
        public readonly array $css = [],
        public readonly array $imports = [],
        public readonly array $markers = [],
        public readonly array $warnings = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'blade' => $this->blade,
            'js' => $this->js,
            'css' => $this->css,
            'imports' => $this->imports,
            'markers' => $this->markers,
            'warnings' => $this->warnings,
        ];
    }
}
