<?php

declare(strict_types=1);

namespace Saola\Compiler\Ast;

final class RootNode extends Node
{
    /** @var list<Node> */
    public array $children = [];
}
