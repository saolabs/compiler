<?php

declare(strict_types=1);

namespace Saola\Compiler\Ast;

final class IfBlock extends Node
{
    /** @var list<array{?string, ?string, list<Node>}> */
    public array $branches = [];

    /** @var array<string, true> */
    public array $stateVars = [];
}
