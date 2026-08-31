<?php

declare(strict_types=1);

namespace Saola\Compiler\Ast;

final class TextNode extends Node
{
    public function __construct(public string $text)
    {
    }
}
