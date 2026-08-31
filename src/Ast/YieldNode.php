<?php

declare(strict_types=1);

namespace Saola\Compiler\Ast;

final class YieldNode extends Node
{
    public function __construct(
        public string $name,
        public ?string $defaultPhp = null,
        public ?string $defaultJs = null,
    ) {
    }
}
