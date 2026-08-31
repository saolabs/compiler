<?php

declare(strict_types=1);

namespace Saola\Compiler\Ast;

final class BlockSection extends Node
{
    /** @var list<Node> */
    public array $children = [];

    public function __construct(public string $name)
    {
    }
}
