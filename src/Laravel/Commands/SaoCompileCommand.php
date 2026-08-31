<?php

declare(strict_types=1);

namespace Saola\Compiler\Laravel\Commands;

use Illuminate\Console\Command;
use Saola\Compiler\CompileException;
use Saola\Compiler\CompileOptions;
use Saola\Compiler\Config\SaoConfig;
use Saola\Compiler\Config\ViewTarget;
use Saola\Compiler\SaolaCompiler;
use Saola\Compiler\Target;
use Throwable;

/**
 * `php artisan sao:compile` — biên dịch view `.sao` mà không cần Node.
 *
 * ⚠️ KHÔNG sinh registry và KHÔNG copy app files. Hai việc đó thuộc về
 * bundler, `builder/src/index.js` vẫn lo. Lệnh này dùng khi chỉ cần dựng lại
 * `.blade.php` + `.js` — vd trên server không có Node, hoặc để kiểm tra nhanh.
 * Chạy build đầy đủ thì vẫn dùng `sao-compile` bên Node.
 */
final class SaoCompileCommand extends Command
{
    protected $signature = 'sao:compile
        {context? : Tên context trong sao.config.json; bỏ trống = tất cả}
        {--path= : Thư mục bắt đầu tìm sao.config.json (mặc định: base_path)}
        {--dry-run : Chỉ liệt kê việc sẽ làm, không ghi file}';

    protected $description = 'Biên dịch .sao sang Blade + JavaScript (không sinh registry)';

    public function handle(SaolaCompiler $compiler): int
    {
        try {
            $config = SaoConfig::load($this->option('path') ?: base_path());
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $requested = $this->argument('context');
        $contexts = $requested !== null ? [$requested] : $config->contextNames();
        $dryRun = (bool) $this->option('dry-run');

        $compiled = 0;
        $failed = 0;

        foreach ($contexts as $context) {
            try {
                $views = $config->views($context);
            } catch (Throwable $e) {
                $this->components->error($e->getMessage());
                $failed++;
                continue;
            }

            $this->components->info(sprintf('Context %s — %d view', $context, count($views)));

            foreach ($views as $view) {
                if ($dryRun) {
                    $this->line("  · {$view->viewPath}");
                    $compiled++;
                    continue;
                }

                try {
                    $this->write($compiler, $view);
                    $compiled++;
                } catch (CompileException $e) {
                    $this->components->error("{$view->viewPath}: {$e->getMessage()}");
                    $failed++;
                }
            }
        }

        $this->newLine();

        if ($failed > 0) {
            $this->components->error("{$compiled} view thành công, {$failed} lỗi.");

            return self::FAILURE;
        }

        $verb = $dryRun ? 'sẽ biên dịch' : 'đã biên dịch';
        $this->components->info("{$verb} {$compiled} view.");

        if (! $dryRun) {
            $this->components->warn('Registry và app files KHÔNG được sinh — chạy build Node nếu cần.');
        }

        return self::SUCCESS;
    }

    private function write(SaolaCompiler $compiler, ViewTarget $view): void
    {
        $source = file_get_contents($view->source);

        if ($source === false) {
            throw new CompileException("Không đọc được {$view->source}", $view->viewPath);
        }

        $result = $compiler->compile($source, new CompileOptions(
            viewPath: $view->viewPath,
            functionName: $view->functionName,
            factoryName: $view->factoryName,
            emit: Target::Both,
            lang: $view->lang,
        ));

        foreach ([[$view->bladeOutput, $result->blade], [$view->jsOutput, $result->js]] as [$path, $content]) {
            if ($content === null) {
                continue;
            }

            $dir = dirname($path);

            if (! is_dir($dir) && ! mkdir($dir, 0o775, true) && ! is_dir($dir)) {
                throw new CompileException("Không tạo được thư mục {$dir}", $view->viewPath);
            }

            file_put_contents($path, $content);
        }
    }
}
