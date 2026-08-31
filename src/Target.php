<?php

declare(strict_types=1);

namespace Saola\Compiler;

enum Target: string
{
    case Both = 'both';
    case BladeOnly = 'blade';
    case JsOnly = 'js';
}
