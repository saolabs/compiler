<?php

declare(strict_types=1);

namespace Saola\Compiler\Config;

use RuntimeException;
use Saola\Compiler\Lang;

/**
 * Đọc `sao.config.json` và suy ra đường dẫn cho từng view.
 *
 * Bản sao PHP của `compiler/src/config-manager.js` cộng phần suy tên trong
 * `index.js::processSaoFile`. Cần có để compiler đứng độc lập: artisan không
 * gọi được Node, mà mọi luật đặt tên đều nằm bên đó.
 *
 * ⚠️ Mọi thay đổi ở đây phải soi lại `index.js` — tên và đường dẫn lệch nhau
 * nghĩa là Node và artisan ghi ra hai file khác nhau cho cùng một view.
 */
final class SaoConfig
{
    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        public readonly string $projectRoot,
        public readonly array $data,
    ) {
    }

    /** Đi ngược lên từ $startPath tìm `sao.config.json`. */
    public static function load(string $startPath): self
    {
        $dir = realpath($startPath) ?: $startPath;

        while (true) {
            $candidate = $dir . '/sao.config.json';

            if (is_file($candidate)) {
                $raw = file_get_contents($candidate);

                if ($raw === false) {
                    throw new RuntimeException("Không đọc được {$candidate}");
                }

                /** @var array<string, mixed> $data */
                $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

                return new self($dir, $data);
            }

            $parent = dirname($dir);

            if ($parent === $dir) {
                throw new RuntimeException("Không tìm thấy sao.config.json từ {$startPath} trở lên");
            }

            $dir = $parent;
        }
    }

    /** @return list<string> Tên context, trừ `default` (không phải context thật) */
    public function contextNames(): array
    {
        $contexts = $this->data['contexts'] ?? [];

        return array_values(array_filter(
            array_keys(is_array($contexts) ? $contexts : []),
            static fn (string $name): bool => $name !== 'default',
        ));
    }

    /**
     * Mọi view của một context, đã sắp xếp ổn định theo đường dẫn nguồn.
     *
     * Sắp xếp để lần chạy nào cũng ra thứ tự như nhau — cùng lý do với việc
     * sắp registry bên index.js.
     *
     * @return list<ViewTarget>
     */
    public function views(string $context): array
    {
        $config = $this->data['contexts'][$context] ?? null;

        if (! is_array($config)) {
            throw new RuntimeException("Context \"{$context}\" không có trong sao.config.json");
        }

        $paths = $this->data['paths'] ?? [];
        $namespaces = $config['views'] ?? [];

        // index.js chỉ chèn namespace vào đường dẫn JS khi context có NHIỀU
        // namespace — nếu không sẽ đè file khi hai namespace trùng tên view.
        $includeNamespace = count($namespaces) > 1;

        $targets = [];

        foreach ($namespaces as $namespace => $viewsRel) {
            $viewsDir = $this->resolve($paths['saoView'] ?? '', (string) $viewsRel);
            $bladeDir = $this->resolve($paths['bladeView'] ?? '', (string) ($config['blade'][$namespace] ?? ''));
            $compiledDir = $this->resolve($paths['compiled'] ?? '', (string) ($config['compiled']['views'] ?? ''));

            foreach (self::findSaoFiles($viewsDir) as $source) {
                $targets[] = $this->targetFor(
                    $source,
                    $viewsDir,
                    $bladeDir,
                    $compiledDir,
                    (string) $namespace,
                    $includeNamespace,
                );
            }
        }

        usort($targets, static fn (ViewTarget $a, ViewTarget $b): int => strcmp($a->source, $b->source));

        return $targets;
    }

    private function targetFor(
        string $source,
        string $viewsDir,
        string $bladeDir,
        string $compiledDir,
        string $namespace,
        bool $includeNamespace,
    ): ViewTarget {
        $relative = substr($source, strlen($viewsDir) + 1);
        $stem = substr($relative, 0, -4);                 // bỏ '.sao'
        $dir = dirname($stem) === '.' ? '' : dirname($stem);
        $base = basename($stem);

        $viewPath = $namespace . '.' . str_replace('/', '.', $stem);

        $lang = self::detectLang($source);
        $jsDir = $includeNamespace
            ? trim($namespace . '/' . $dir, '/')
            : $dir;

        return new ViewTarget(
            source: $source,
            viewPath: $viewPath,
            functionName: self::pascal($base),
            factoryName: implode('', array_map(self::pascal(...), explode('.', $viewPath))),
            bladeOutput: self::join($bladeDir, $dir, $base . '.blade.php'),
            jsOutput: self::join($compiledDir, $jsDir, $base . ($lang === Lang::Ts ? '.ts' : '.js')),
            lang: $lang,
            namespace: $namespace,
        );
    }

    /** Khớp `index.js`: chỉ `<script setup lang="ts">` mới bật TypeScript. */
    private static function detectLang(string $source): Lang
    {
        $content = file_get_contents($source);

        if ($content === false) {
            return Lang::Js;
        }

        if (preg_match('/<script\s+setup\b[^>]*\blang=["\']?([^"\'\s>]+)["\']?/i', $content, $m) !== 1) {
            return Lang::Js;
        }

        return in_array(strtolower($m[1]), ['ts', 'typescript'], true) ? Lang::Ts : Lang::Js;
    }

    /**
     * PascalCase, GIỮ NGUYÊN chữ hoa bên trong.
     *
     * `hero-section` → `HeroSection`, `useState` → `UseState`. Dùng ucfirst
     * chứ không phải ucwords: ucwords sẽ hạ `useState` thành `Usestate`.
     */
    private static function pascal(string $value): string
    {
        $parts = preg_split('/[-_\s]+/', $value) ?: [];

        return implode('', array_map(static fn (string $w): string => ucfirst($w), $parts));
    }

    /** @return list<string> Đường dẫn tuyệt đối, đã sắp xếp */
    private static function findSaoFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'sao') {
                $files[] = $file->getPathname();
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    private function resolve(string ...$parts): string
    {
        return self::join($this->projectRoot, ...$parts);
    }

    private static function join(string ...$parts): string
    {
        $clean = array_filter($parts, static fn (string $p): bool => $p !== '' && $p !== '.');

        return preg_replace('#/+#', '/', implode('/', $clean)) ?? implode('/', $clean);
    }
}
