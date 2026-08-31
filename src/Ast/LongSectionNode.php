<?php

declare(strict_types=1);

namespace Saola\Compiler\Ast;

final class LongSectionNode extends Node
{
    /** @var list<Node> */
    public array $children = [];

    /** @var array<string, true> */
    public array $stateVars = [];

    public function __construct(public string $name)
    {
    }
}
