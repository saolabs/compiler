<?php

declare(strict_types=1);

namespace Saola\Compiler\Directive;

use RuntimeException;
use Saola\Compiler\Directive\Builtin\BuiltinDirective;

/**
 * Tìm và nạp `sao.directives.php`.
 *
 * Một project khai báo directive ở `<root>/sao.directives.php`; một thư viện
 * khai báo trong composer.json của nó:
 *
 *     "extra": { "saola": { "directives": "sao.directives.php" } }
 *
 * Thứ tự nạp: các package (theo tên), rồi tới app — trùng tên thì báo lỗi,
 * trừ khi khai báo sau có `override: true`.
 */
final class DirectiveFiles
{
    public const APP_FILE = 'sao.directives.php';

    /** Định nghĩa đã `require` trong tiến trình này — worker `saoc serve` nạp lại mỗi request. */
    private static array $loaded = [];

    /**
     * `$spec`: danh sách phân tách bằng PATH_SEPARATOR; mỗi mục là một file,
     * hoặc một thư mục project (khi đó tự dò app + vendor).
     *
     * @return list<string>
     */
    public static function resolve(string $spec): array
    {
        $files = [];
        foreach (array_filter(explode(PATH_SEPARATOR, $spec), static fn (string $p): bool => $p !== '') as $path) {
            if (is_dir($path)) {
                array_push($files, ...self::discover($path));
            } elseif (is_file($path)) {
                $files[] = $path;
            } else {
                throw new RuntimeException("Không tìm thấy directives file: {$path}");
            }
        }

        return array_values(array_unique($files));
    }

    /** @return list<string> */
    public static function discover(string $root): array
    {
        $root = rtrim($root, '/\\');
        $files = [];
        $installed = $root . '/vendor/composer/installed.json';
        if (is_file($installed)) {
            $data = json_decode((string) file_get_contents($installed), true);
            $packages = is_array($data) ? ($data['packages'] ?? $data) : [];
            usort($packages, static fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
            foreach ($packages as $package) {
                $declared = $package['extra']['saola']['directives'] ?? null;
                if ($declared === null) {
                    continue;
                }
                $dir = $root . '/vendor/composer/' . ($package['install-path'] ?? '../' . ($package['name'] ?? ''));
                foreach ((array) $declared as $relative) {
                    $file = $dir . '/' . $relative;
                    if (!is_file($file)) {
                        throw new RuntimeException("Package {$package['name']} khai báo directives file không tồn tại: {$relative}");
                    }
                    $files[] = realpath($file) ?: $file;
                }
            }
        }
        if (is_file($root . '/' . self::APP_FILE)) {
            $files[] = realpath($root . '/' . self::APP_FILE) ?: $root . '/' . self::APP_FILE;
        }

        return $files;
    }

    public static function load(DirectiveRegistry $registry, string $file): void
    {
        $key = realpath($file) ?: $file;
        if (!array_key_exists($key, self::$loaded)) {
            $definitions = require $file;
            if (!is_iterable($definitions)) {
                throw new RuntimeException("{$file} phải trả về mảng khai báo (Sao::directive/block/tag/attr).");
            }
            self::$loaded[$key] = is_array($definitions) ? $definitions : iterator_to_array($definitions);
        }

        foreach (self::$loaded[$key] as $name => $definition) {
            if ($definition instanceof BuiltinDirective || $definition instanceof UserDirective
                || $definition instanceof TagDirective || $definition instanceof UserHelper) {
                $registry->add($definition);
            } elseif (is_string($name) && is_callable($definition)) {
                $registry->directive($name, $definition);
            } else {
                throw new RuntimeException("Khai báo directive không hợp lệ trong {$file}.");
            }
        }
    }
}
