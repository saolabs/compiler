<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\Source\SetupFunctions;

final class SetupFunctionsTest extends TestCase
{
    public function test_finds_only_top_level_function_declarations(): void
    {
        $source = <<<'TS'
const text = "function fake() {}";
const regex = /function alsoFake\(\) \{\}/;
const expression = function namedExpression() {};
const arrow = () => {
    function nestedInArrow() {}
};

function increment() {}
async function save() {}
function* rows() {}
function typed<T>(value: T): T { return value; }

export default {
    started() {
        function nestedInMethod() {}
    },
};
TS;

        self::assertSame(
            ['increment', 'save', 'rows', 'typed'],
            (new SetupFunctions())->names($source),
        );
    }

    public function test_supports_function_after_asi_boundary(): void
    {
        $source = <<<'JS'
const step = 1
function increment() { return step + 1 }
JS;

        self::assertSame(['increment'], (new SetupFunctions())->names($source));
    }
}
