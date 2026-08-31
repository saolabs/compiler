<?php

declare(strict_types=1);

namespace Saola\Compiler\Template;

final class ReactiveScope
{
    private int $childCounter = 0;

    public function __construct(private readonly string $prefix = '', private ?string $loopVariable = null)
    {
    }

    public function setLoopVariable(?string $loopVariable): void
    {
        $this->loopVariable = $loopVariable;
    }

    public function nextChildId(string $type): string
    {
        $segment = $type . '-' . ++$this->childCounter;
        $prefix = $this->prefix;
        if ($this->loopVariable !== null && $this->loopVariable !== '') {
            $suffix = '${' . $this->loopVariable . '}';
            $prefix = $prefix !== '' ? $prefix . '-' . $suffix : $suffix;
        }

        return $prefix !== '' ? $prefix . '-' . $segment : $segment;
    }
}
