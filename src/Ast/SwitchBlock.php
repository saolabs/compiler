<?php

declare(strict_types=1);

namespace Saola\Compiler\Ast;

final class SwitchBlock extends Node
{
    /** @var list<array{?string, list<Node>}> */
    public array $cases = [];

    /** @var array<string, true> */
    public array $stateVars = [];

    public function __construct(public string $exprPhp, public string $exprJs)
    {
    }
}
