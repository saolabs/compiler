<?php

declare(strict_types=1);

namespace Saola\Compiler\Ast;

final class ForBlock extends Node
{
    public ?string $customKey = null;

    public ?string $customKeyJs = null;

    /** @var list<Node> */
    public array $children = [];

    /** @var array<string, true> */
    public array $stateVars = [];

    public function __construct(
        public string $varName,
        public string $startJs,
        public string $endJs,
        public string $operator,
    ) {
    }
}
