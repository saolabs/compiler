<?php

declare(strict_types=1);

namespace Saola\Compiler\Ast;

final class WhileBlock extends Node
{
    public ?string $customKey = null;

    public ?string $customKeyJs = null;

    /** @var list<Node> */
    public array $children = [];

    public function __construct(
        public string $conditionPhp,
        public string $conditionJs,
        public ?string $loopVar = null,
        public ?string $endVal = null,
    ) {
    }
}
