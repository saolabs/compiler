#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Saola\Compiler\Template\TemplateProcessor;

while (($line = fgets(STDIN)) !== false) {
    if (trim($line) === '') continue;
    $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $processor = new TemplateProcessor($case['states'], $case['typescript']);
    [$template, $sections] = $processor->processTemplate($case['source']);
    echo json_encode(['name' => $case['name'], 'result' => ['sections' => $sections, 'template' => $template]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}
