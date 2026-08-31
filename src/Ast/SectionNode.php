<?php

declare(strict_types=1);

namespace Saola\Compiler\Ast;

final class SectionNode extends Node
{
    /** @param array<string, true> $stateVars */
    public function __construct(
        public string $name,
        public string $valuePhp,
        public string $valueJs,
        public string $contentType = 'text',
        public array $stateVars = [],
    ) {
    }
}
