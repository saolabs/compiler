<?php

declare(strict_types=1);

namespace Saola\Compiler\Ast;

final class BlockOutlet extends Node
{
    public function __construct(public string $name)
    {
    }
}
