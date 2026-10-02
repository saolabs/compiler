<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\Lang;
use Saola\Compiler\SaolaCompiler;

final class ForeachScopeProtocolTest extends TestCase
{
    public function test_compiler_opts_into_scoped_reconciliation_and_stable_reference_identity(): void
    {
        $source = <<<'SAO'
        @state(items = [])
        <template>
        @foreach(items as item)
        <span>{{ item['name'] }}:{{ loop.index }}</span>
        @endforeach
        @foreach(items as item)
        @key(item['id'])
        <b>{{ item['name'] }}</b>
        @endforeach
        </template>
        SAO;
        $result = (new SaolaCompiler())->compile($source, new CompileOptions(
            viewPath: 'test.foreach', functionName: 'ForeachScope', factoryName: 'ForeachScope', lang: Lang::Ts,
        ));
        $this->assertStringContainsString('__loopIdentity: any', $result->js);
        $this->assertStringContainsString('${__loopIdentity}', $result->js);
        $this->assertStringContainsString(", undefined, true, ", $result->js);
        $this->assertStringContainsString("(item: any) => item['id'], true, ", $result->js);
        $this->assertStringContainsString('__loop.index', $result->js);
        $this->assertStringNotContainsString('__loopIdentity', $result->blade);
    }

    public function test_pure_literal_state_is_allocated_once_and_commits_the_rendered_reference(): void
    {
        $result = (new SaolaCompiler())->compile(<<<'SAO'
        @state(items = [{id: 1, name: 'one'}])
        <template><p>{{ items.length }}</p></template>
        SAO, new CompileOptions());
        $this->assertStringContainsString('update$items(items);', $result->js);
        $this->assertSame(1, substr_count($result->js, '"id": 1'));
    }
}
