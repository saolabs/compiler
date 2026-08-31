#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Saola\Compiler\Directive\SectionHandlers;

$handler = new SectionHandlers();
while (($line = fgets(STDIN)) !== false) {
    if (trim($line) === '') continue;
    $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $stack = $output = $sections = $returns = [];
    foreach ($case['operations'] as $operation) {
        $op = $operation['op'];
        if ($op === 'append') {
            $output[] = $operation['value'];
            $returns[] = null;
        } elseif ($op === 'section') {
            $returns[] = $handler->processSectionDirective($operation['line'], $stack, $output, $sections);
        } elseif ($op === 'endsection') {
            $returns[] = $handler->processEndsectionDirective($stack, $output, $sections);
        } elseif ($op === 'block') {
            $returns[] = $handler->processBlockDirective($operation['line'], $stack, $output, $sections);
        } elseif ($op === 'endblock') {
            $returns[] = $handler->processEndblockDirective($stack, $output, $sections);
        }
    }
    $result = ['output' => $output, 'returns' => $returns, 'sections' => $sections, 'stack' => $stack];
    echo json_encode(['name' => $case['name'], 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}
