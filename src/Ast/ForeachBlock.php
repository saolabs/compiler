<?php

declare(strict_types=1);

namespace Saola\Compiler\Ast;

final class ForeachBlock extends Node
{
    public ?string $customKey = null;

    public ?string $customKeyJs = null;

    /** @var list<Node> */
    public array $children = [];

    /** @var array<string, true> */
    public array $stateVars = [];

    public function __construct(
        public string $arrayPhp,
        public string $arrayJs,
        public string $valueVar,
        public ?string $keyVar = null,
    ) {
    }
}
