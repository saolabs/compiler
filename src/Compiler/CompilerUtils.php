<?php

declare(strict_types=1);

namespace Saola\Compiler\Compiler;

final class CompilerUtils
{
    /** @param array<string, mixed>|null $fetchConfig */
    public function formatFetchConfig(?array $fetchConfig): string
    {
        if (!$fetchConfig) {
            return 'null';
        }

        $parts = [];
        foreach ($fetchConfig as $key => $value) {
            if (is_string($value)) {
                $encoded = str_starts_with($value, '`') && str_ends_with($value, '`')
                    ? $value
                    : '"'.$value.'"';
            } else {
                $encoded = $this->json($value, spaced: true);
            }
            $parts[] = '"'.$key.'": '.$encoded;
        }

        return '{'.implode(', ', $parts).'}';
    }

    /** @param array<string, mixed> $attributes */
    public function formatAttrs(array $attributes): string
    {
        return $this->json($attributes);
    }

    /** @param array<string, mixed>|null $attributes */
    public function formatAttributesToJson(?array $attributes): string
    {
        if (!$attributes) {
            return '{}';
        }

        $formatted = [];
        foreach ($attributes as $key => $value) {
            $formatted[$key] = is_bool($value) ? $value : (string) $value;
        }

        return $this->json($formatted);
    }

    private function json(mixed $value, bool $spaced = false): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        $json = json_encode($value, $flags);
        if (!$spaced) {
            return $json;
        }

        return str_replace([':', ','], [': ', ', '], $json);
    }
}
