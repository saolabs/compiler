<?php

declare(strict_types=1);

namespace Saola\Compiler\Ast;

final class ImportIncludeNode extends Node
{
    /** @var list<array{string, string}> */
    public array $dataPairs;

    /** @var list<Node> */
    public array $children = [];

    /** @param list<array{string, string}> $dataPairs @param array<string, true> $stateVars */
    public function __construct(
        public string $pathPhp,
        public string $pathJs,
        array $dataPairs = [],
        public array $stateVars = [],
    ) {
        $this->dataPairs = $dataPairs;
    }
}
