<?php

declare(strict_types=1);

namespace Saola\Compiler\Template;

use RuntimeException;

/** Ném khi thẻ component được import không cân bằng về cấu trúc. */
final class TemplateStructureError extends RuntimeException
{
}
