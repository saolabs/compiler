<?php

declare(strict_types=1);

namespace Saola\Compiler\Template;

use RuntimeException;

/** Ném khi một component vi phạm hợp đồng children slot. */
final class ChildrenSlotError extends RuntimeException
{
}
