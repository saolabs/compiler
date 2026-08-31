<?php

declare(strict_types=1);

namespace Saola\Compiler\Ast;

final class HtmlElement extends Node
{
    /** @var list<Node> */
    public array $children = [];

    /** @var list<string> */
    public array $staticClasses = [];

    /** @var array<string, array<string, mixed>> */
    public array $bindingClasses = [];

    /** @var list<array<string, mixed>> */
    public array $dynamicClasses = [];

    /** @var array<string, string|bool> */
    public array $staticAttrs = [];

    /** @var array<string, array<string, mixed>> */
    public array $bindingAttrs = [];

    /** @var array<string, array<string, mixed>> */
    public array $styles = [];

    /** @var array<string, array<string, mixed>> */
    public array $bindingProps = [];

    /** @var array<string, list<string>> */
    public array $events = [];

    /** @var array<string, list<string>> */
    public array $eventModifiers = [];

    public ?string $transitionName = null;

    public ?string $bindKey = null;

    public string $rawAttrsRemaining = '';

    public function __construct(public string $tag, public bool $isVoid = false)
    {
    }
}
