#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Saola\Compiler\Directive\DirectiveParsers;
use Saola\Compiler\Directive\DirectiveRegistry;

$methods = [
    'parse_extends' => 'extends', 'parse_vars' => 'vars',
    'parse_props' => 'props', 'parse_let_directives' => 'let',
    'parse_const_directives' => 'const', 'parse_usestate_directives' => 'useState',
    'parse_states_directives' => 'states', 'parse_fetch' => 'fetch',
    'parse_init' => 'onInit', 'parse_view_type' => 'viewType',
    'parse_block_directives' => 'block', 'parse_endblock_directives' => 'endBlock',
    'parse_useblock_directives' => 'useBlock', 'parse_onblock_directives' => 'onBlock',
];

function normalize_parser_result(mixed $value, ?string $field = null): mixed
{
    if (! is_array($value)) return $value;
    if ($value === [] && in_array($field, ['data', 'headers'], true)) return (object) [];
    if (array_is_list($value)) return array_map(static fn (mixed $item): mixed => normalize_parser_result($item), $value);
    $out = [];
    foreach ($value as $key => $item) $out[$key] = normalize_parser_result($item, (string) $key);
    ksort($out);
    return $out;
}

$parser = new DirectiveParsers();
$registry = DirectiveRegistry::builtins($parser);
while (($line = fgets(STDIN)) !== false) {
    if (trim($line) === '') continue;
    $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $results = [];
    foreach ($methods as $oracle => $directive) {
        try {
            $results[$oracle] = normalize_parser_result($registry->parse($directive, $case['source']));
        } catch (Throwable $e) {
            $results[$oracle] = ['error' => (new ReflectionClass($e))->getShortName(), 'message' => $e->getMessage()];
        }
    }
    ksort($results);
    echo json_encode(['name' => $case['name'], 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}
