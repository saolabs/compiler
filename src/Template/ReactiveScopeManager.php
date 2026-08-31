<?php

declare(strict_types=1);

namespace Saola\Compiler\Template;

final class ReactiveScopeManager
{
    /** @var list<ReactiveScope> */
    private array $stack;

    public function __construct()
    {
        $this->reset();
    }

    public function reset(): void
    {
        $this->stack = [new ReactiveScope()];
    }

    public function generateChildId(string $type): string
    {
        return $this->stack[array_key_last($this->stack)]->nextChildId($type);
    }

    public function makeRcId(string $childId): string
    {
        return '`rc-${__VIEW_ID__}-' . $childId . '`';
    }

    public function pushReactiveScope(string $prefix, ?string $loopVariable = null): void
    {
        $this->stack[] = new ReactiveScope($prefix, $loopVariable);
    }

    public function popReactiveScope(): void
    {
        if (count($this->stack) > 1) {
            array_pop($this->stack);
        }
    }

    public function setCurrentLoopVariable(?string $loopVariable): void
    {
        $this->stack[array_key_last($this->stack)]->setLoopVariable($loopVariable);
    }
}
