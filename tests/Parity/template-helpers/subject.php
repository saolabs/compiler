#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Saola\Compiler\Directive\BindingDirectiveService;
use Saola\Compiler\Directive\ClassBindingHandler;
use Saola\Compiler\Directive\ShowDirectiveHandler;
use Saola\Compiler\Directive\StyleDirectiveHandler;
use Saola\Compiler\Template\EchoProcessor;
use Saola\Compiler\Template\TemplateAnalyzer;

$binding = new BindingDirectiveService();
$analyzer = new TemplateAnalyzer();

function normalize_template_helper_result(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('normalize_template_helper_result', $value);
    }
    $normalized = [];
    foreach ($value as $key => $item) {
        $normalized[$key] = normalize_template_helper_result($item);
    }
    ksort($normalized);

    return $normalized;
}

while (($line = fgets(STDIN)) !== false) {
    if (trim($line) === '') {
        continue;
    }
    $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $content = $case['content'] ?? '';
    $states = $case['states'] ?? [];
    $result = [
        'binding_all' => $binding->processAllBindingDirectives($content),
        'binding_bind' => $binding->processBindDirective($content),
        'binding_val' => $binding->processValDirective($content),
        'class' => (new ClassBindingHandler($states))->processClassDirective($content),
        'conditional' => $analyzer->analyzeConditionalStructures(
            $content,
            $case['vars'] ?? null,
            $case['await'] ?? false,
            $case['fetch'] ?? false,
        ),
        'echo' => (new EchoProcessor($states, $case['typescript'] ?? false))->processEchoExpressions($content),
        'sections' => $analyzer->analyzeSectionsInfo(
            $case['sections'] ?? [],
            $case['vars'] ?? null,
            $case['await'] ?? false,
            $case['fetch'] ?? false,
            $states,
            $case['blade'] ?? null,
        ),
        'show' => (new ShowDirectiveHandler($states))->processShowDirective($content),
        'style' => (new StyleDirectiveHandler($states))->processStyleDirective($content),
    ];
    echo json_encode(
        ['name' => $case['name'], 'result' => normalize_template_helper_result($result)],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ) . "\n";
}
