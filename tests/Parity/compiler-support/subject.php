#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__.'/../../../vendor/autoload.php';
use Saola\Compiler\Compiler\CompilerUtils;
use Saola\Compiler\Compiler\RegisterParser;

$utils = new CompilerUtils();
while (($raw = fgets(STDIN)) !== false) {
    $raw = rtrim($raw, "\r\n");
    if ($raw === '') continue;
    $call = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    $args = $call['args'];
    if ($call['fn'] === 'register') {
        $parser = new RegisterParser();
        $value = $parser->parseRegisterContent(...$args);
        $value['methodNames'] = array_keys($parser->getUserMethodNames());
        sort($value['methodNames']);
        $value['sections'] = (object) [];
    } else {
        $method = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $call['fn']))));
        $value = $utils->{$method}(...$args);
    }
    echo $raw."\t".json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}
