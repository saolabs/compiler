<?php

declare(strict_types=1);

namespace Saola\Compiler\Directive\Builtin;

interface BuiltinDirective
{
    public function name(): string;

    public function parse(string $source): mixed;
}
