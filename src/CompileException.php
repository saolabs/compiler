<?php

declare(strict_types=1);

namespace Saola\Compiler;

final class CompileException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $viewPath = null,
        public readonly ?int $sourceLine = null,
        ?\Throwable $previous = null,
    ) {
        $location = $viewPath === null ? '' : " [{$viewPath}" . ($sourceLine === null ? '' : ":{$sourceLine}") . ']';
        parent::__construct($message . $location, 0, $previous);
    }
}
