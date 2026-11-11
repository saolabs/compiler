<?php

declare(strict_types=1);

namespace Saola\Compiler\Directive;

/** `Sao::helper('name')` — helper runtime có ở cả PHP lẫn App.Helper. */
final class UserHelper
{
    public function __construct(public readonly string $name)
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("Tên helper không hợp lệ: {$name}");
        }
    }
}
