<?php

declare(strict_types=1);

namespace Saola\Compiler\Ast;

final class IncludeNode extends Node
{
    /** @param array<string, true> $stateVars */
    public function __construct(
        public string $pathPhp,
        public string $pathJs,
        public ?string $dataPhp = null,
        public ?string $dataJs = null,
        public array $stateVars = [],
    ) {
    }
}
