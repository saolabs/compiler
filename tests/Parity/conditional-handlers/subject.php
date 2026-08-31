#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';
use Saola\Compiler\Directive\ConditionalHandlers;
use Saola\Compiler\Template\ReactiveScopeManager;

while (($line = fgets(STDIN)) !== false) {
    if (trim($line) === '') continue;
    $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $handler = new ConditionalHandlers($case['states'] ?? [], new ReactiveScopeManager(), $case['typescript'] ?? false);
    $stack = $case['stack'] ?? []; $output = []; $returns = [];
    foreach ($case['ops'] as $operation) {
        $op = $operation['op'];
        if ($op === 'append') { $output[] = $operation['value']; $returns[] = null; }
        elseif ($op === 'if') $returns[] = $handler->processIfDirective($operation['line'], $stack, $output, $operation['attribute'] ?? false);
        elseif ($op === 'elseif') $returns[] = $handler->processElseifDirective($operation['line'], $stack, $output);
        elseif ($op === 'else') $returns[] = $handler->processElseDirective('@else', $stack, $output);
        elseif ($op === 'endif') $returns[] = $handler->processEndifDirective($stack, $output);
        elseif ($op === 'switch') $returns[] = $handler->processSwitchDirective($operation['line'], $stack, $output, $operation['attribute'] ?? false);
        elseif ($op === 'case') $returns[] = $handler->processCaseDirective($operation['line'], $stack, $output);
        elseif ($op === 'default') $returns[] = $handler->processDefaultDirective('@default', $stack, $output);
        elseif ($op === 'break') $returns[] = $handler->processBreakDirective('@break', $stack, $output);
        elseif ($op === 'endswitch') $returns[] = $handler->processEndswitchDirective($stack, $output);
    }
    echo json_encode(['name' => $case['name'], 'result' => ['output' => $output, 'returns' => $returns, 'stack' => $stack]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}
