<?php

declare(strict_types=1);

namespace Saola\Compiler\Ast;

final class EchoNode extends Node
{
    /** @param array<string, true> $stateVars */
    public function __construct(
        public string $phpExpr,
        public string $jsExpr,
        public bool $escaped = true,
        public array $stateVars = [],
    ) {
    }
}
