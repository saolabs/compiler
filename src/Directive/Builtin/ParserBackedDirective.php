<?php

declare(strict_types=1);

namespace Saola\Compiler\Directive\Builtin;

use Saola\Compiler\Directive\DirectiveParsers;

abstract class ParserBackedDirective implements BuiltinDirective
{
    public function __construct(protected readonly DirectiveParsers $parsers)
    {
    }
}
