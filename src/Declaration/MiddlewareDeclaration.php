<?php

declare(strict_types=1);

namespace Saola\Compiler\Declaration;

/** Static aliases are available before any view instance or setup script exists. */
final class MiddlewareDeclaration
{
    /** @return list<string> */
    public static function parse(string $declaration): array
    {
        $body = trim(substr($declaration, strlen('@middleware('), -1));
        if (str_starts_with($body, '[') && str_ends_with($body, ']')) $body = trim(substr($body, 1, -1));
        if ($body === '') throw new \InvalidArgumentException('@middleware requires at least one static alias.');
        $names = [];
        $parts = TypedDeclarations::split($body, ',', false);
        foreach ($parts as $index => $part) {
            $part = trim($part);
            if ($part === '' && $index === count($parts) - 1 && $names !== []) continue;
            if (!preg_match('/^([\'"])([A-Za-z_][\w.-]*(?::[^\s\'"\\\\$]*)?)\1$/D', $part, $m)) {
                throw new \InvalidArgumentException('@middleware accepts quoted static aliases, optionally in an array; expressions and functions are not supported.');
            }
            $names[] = $m[2];
        }
        return $names;
    }
}
