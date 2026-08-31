<?php

declare(strict_types=1);

namespace Saola\Compiler\Laravel;

use Illuminate\Support\ServiceProvider;
use Saola\Compiler\Directive\Builtin\BuiltinDirective;
use Saola\Compiler\Directive\DirectiveRegistry;
use Saola\Compiler\Laravel\Commands\SaoCompileCommand;
use Saola\Compiler\SaolaCompiler;

/**
 * Nối compiler vào Laravel.
 *
 * Package này KHÔNG phụ thuộc Laravel — `illuminate/support` chỉ nằm ở
 * `suggest`. Class này chỉ được nạp khi chạy trong một app Laravel, do
 * package discovery gọi tới; ngoài Laravel thì PSR-4 không bao giờ chạm đến nó.
 *
 * Đăng ký hai singleton:
 *
 *   DirectiveRegistry — chỗ ứng dụng thêm directive riêng
 *   SaolaCompiler     — dùng chính registry đó
 *
 * Thêm directive trong `AppServiceProvider::boot()`:
 *
 *     $this->app->make(DirectiveRegistry::class)->directive('money', fn (string $e) => [
 *         'blade' => "{{ number_format({$e}, 0, ',', '.') }}",
 *         'js'    => "fmtMoney({$e})",
 *     ]);
 */
final class SaolaCompilerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Registry là singleton để directive đăng ký ở AppServiceProvider còn
        // hiệu lực với mọi lần compile sau đó.
        $this->app->singleton(DirectiveRegistry::class, static fn (): DirectiveRegistry
            => DirectiveRegistry::builtins());

        // Compiler thì KHÔNG giữ trạng thái giữa các lần compile (xem
        // docs/02-public-api.md §5 quy tắc 4), nhưng bản thân nó rẻ để dựng và
        // registry mới là thứ cần dùng chung — nên singleton là an toàn.
        $this->app->singleton(SaolaCompiler::class, static fn ($app): SaolaCompiler
            => new SaolaCompiler($app->make(DirectiveRegistry::class)));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SaoCompileCommand::class]);
        }
    }

    /** @return list<class-string> */
    public function provides(): array
    {
        return [SaolaCompiler::class, DirectiveRegistry::class, BuiltinDirective::class];
    }
}
