#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Saola\Compiler\Ast\Node;
use Saola\Compiler\Ast\Parser;

function snake(string $name): string
{
    return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
}

function normalize(mixed $value, ?string $field = null): mixed
{
    if ($value instanceof Node) {
        $data = ['type' => (new ReflectionClass($value))->getShortName()];
        foreach (get_object_vars($value) as $key => $item) {
            $data[snake($key)] = normalize($item, snake($key));
        }
        ksort($data);
        return $data;
    }
    if (is_array($value)) {
        if ($field === 'state_vars') {
            $keys = array_keys($value);
            sort($keys);
            return $keys;
        }
        $mapFields = [
            'binding_attrs', 'binding_classes', 'binding_props', 'event_modifiers',
            'events', 'static_attrs', 'styles',
        ];
        if ($value === [] && in_array($field, $mapFields, true)) {
            return (object) [];
        }
        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => normalize($item), $value);
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = normalize($item, snake((string) $key));
        }
        ksort($out);
        return $out;
    }
    return $value;
}

while (($line = fgets(STDIN)) !== false) {
    if (trim($line) === '') continue;
    $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    try {
        $result = normalize((new Parser($case['states']))->parse($case['template']));
    } catch (Throwable $e) {
        $result = ['error' => (new ReflectionClass($e))->getShortName(), 'message' => $e->getMessage()];
    }
    $row = ['name' => $case['name'], 'result' => $result];
    echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}
