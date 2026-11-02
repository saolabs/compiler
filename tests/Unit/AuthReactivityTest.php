<?php

declare(strict_types=1);

namespace Saola\Compiler\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Saola\Compiler\{CompileOptions, SaolaCompiler};
use Saola\Compiler\Hydration\MarkerParity;
use Saola\Compiler\Support\AuthExpressions;

final class AuthReactivityTest extends TestCase
{
    public function test_auth_blocks_and_bindings_share_reactive_markers_on_both_targets(): void
    {
        $result = (new SaolaCompiler())->compile(<<<'SAO'
@computed(user = auth().user())
<template>
<header>
@auth
    <span @class({'member': auth().check()}) title="{{ auth().user().username }}">{{ auth().user().name }}</span>
@elseguest('admin')
    <a>Sign in</a>
@else
    <span>Admin</span>
@endauth
@guest('admin')<b>Guest</b>@else<b>Staff</b>@endguest
<span>{{ user ? user.name : 'Guest' }}</span>
</header>
</template>
SAO, new CompileOptions(viewPath: 'test.auth'));
        self::assertStringContainsString('@if(auth()->check())', $result->blade);
        self::assertStringContainsString("@elseif(auth('admin')->guest())", $result->blade);
        self::assertStringContainsString('App.Helper.auth().check()', $result->js);
        self::assertStringContainsString("App.Helper.auth('admin').guest()", $result->js);
        self::assertStringContainsString('["@auth"]', $result->js);
        self::assertStringContainsString("['@auth']", $result->blade);
        self::assertSame(['blade' => [], 'js' => []], MarkerParity::diff($result->blade, $result->js));
        self::assertStringNotContainsString('@endauth', $result->js);
    }

    public function test_only_real_auth_reads_create_dependencies(): void
    {
        foreach (['auth()->user()', 'auth("admin").check()', 'App.Auth.user()', 'App.Helper.auth().check()', 'isAuth()'] as $source) {
            self::assertTrue(AuthExpressions::readsAuth($source), $source);
        }
        foreach (["'auth().user()'", '"App.Auth.user()"', 'other.auth()', '$auth()', 'reauth()'] as $source) {
            self::assertFalse(AuthExpressions::readsAuth($source), $source);
        }
    }
}
