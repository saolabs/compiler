#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Subject: chạy dãy thao tác bằng HydrateIdGenerator của bản PHP. */

require __DIR__ . '/../../../vendor/autoload.php';

use Saola\Compiler\Hydration\HydrateIdGenerator;
use Saola\Compiler\Hydration\HydrateIdScope;

/** popScope trả về object scope; chỉ so tiền tố của nó là đủ và ổn định. */
function render(mixed $value): string
{
    return match (true) {
        $value === null            => 'null',
        is_bool($value)            => $value ? 'true' : 'false',
        is_int($value)             => (string) $value,
        is_string($value)          => $value,
        $value instanceof HydrateIdScope => 'scope:' . $value->prefix,
        default                    => 'scope:?',
    };
}

$generator = new HydrateIdGenerator();
$index = 0;

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }

    /** @var array{op: string, args: list<mixed>} $op */
    $op = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $args = $op['args'];

    $result = match ($op['op']) {
        'nextElement'        => $generator->nextElement($args[0]),
        'pushElement'        => $generator->pushElement($args[0]),
        'pushReactive'       => $generator->pushReactive($args[0]),
        'pushCase'           => $generator->pushCase($args[0]),
        'pushLoopIteration'  => $generator->pushLoopIteration($args[0], $args[1]),
        'pushBlock'          => $generator->pushBlock($args[0]),
        'pushComponent'      => $generator->pushComponent(),
        'nextOutput'         => $generator->nextOutput(),
        'nextComponent'      => $generator->nextComponent(),
        'nextBlockOutlet'    => $generator->nextBlockOutlet(),
        'nextYield'          => $generator->nextYield(),
        'depth'              => $generator->depth(),
        'formatJsId'         => $generator->formatJsId($args[0]),
        'formatBladeHydrate' => $generator->formatBladeHydrate($args[0]),
        'reset'              => $generator->reset(),
        'popScope'           => $generator->popScope(),
    };

    printf("%d\t%s\t%s\n", $index, $op['op'], render($result));
    $index++;
}
