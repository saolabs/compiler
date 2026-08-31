#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Saola\Compiler\Template\TemplateProcessors;

$handler = new TemplateProcessors();
while (($line = fgets(STDIN)) !== false) {
    if (trim($line) === '') continue;
    $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $result = [
        'client' => $handler->processClientsideDirective($case['source']),
        'server' => $handler->processServersideDirective($case['source']),
        'template' => $handler->processTemplateLine($case['source']),
    ];
    echo json_encode(['name' => $case['name'], 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}
