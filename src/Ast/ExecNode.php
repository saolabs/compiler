<?php

declare(strict_types=1);

namespace Saola\Compiler\Ast;

final class ExecNode extends Node
{
    public function __construct(public string $jsExpr)
    {
    }
}
