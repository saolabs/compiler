#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Saola\Compiler\Directive\LoopHandlers;
use Saola\Compiler\Template\ReactiveScopeManager;

while (($line = fgets(STDIN)) !== false) {
    if (trim($line) === '') continue;
    $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $handler = new LoopHandlers($case['states'] ?? [], new ReactiveScopeManager(), $case['typescript'] ?? false);
    $stack = $case['stack'] ?? []; $output = []; $returns = [];
    foreach ($case['ops'] as $operation) {
        $op = $operation['op'];
        if ($op === 'append') { $output[] = $operation['value']; $returns[] = null; }
        elseif ($op === 'foreach') $returns[] = $handler->processForeachDirective($operation['line'], $stack, $output, $operation['attribute'] ?? false);
        elseif ($op === 'endforeach') $returns[] = $handler->processEndforeachDirective($stack, $output);
        elseif ($op === 'for') $returns[] = $handler->processForDirective($operation['line'], $stack, $output, $operation['attribute'] ?? false);
        elseif ($op === 'endfor') $returns[] = $handler->processEndforDirective($stack, $output);
        elseif ($op === 'while') $returns[] = $handler->processWhileDirective($operation['line'], $stack, $output, $operation['attribute'] ?? false);
        elseif ($op === 'endwhile') $returns[] = $handler->processEndwhileDirective($stack, $output);
    }
    echo json_encode(['name' => $case['name'], 'result' => ['output' => $output, 'returns' => $returns, 'stack' => $stack]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}
