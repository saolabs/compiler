<?php

declare(strict_types=1);

namespace Saola\Compiler;

use Saola\Compiler\Compiler\MainCompiler;
use Saola\Compiler\Compiler\RegisterParser;
use Saola\Compiler\Directive\DirectiveRegistry;
use Saola\Compiler\Support\BladeComment;
use Saola\Compiler\Support\Re;
use Saola\Compiler\Emit\BladeBuiltinCheck;
use Saola\Compiler\Emit\BladeInterpolationCheck;
use Saola\Compiler\Emit\BladeEmitter;
use Saola\Compiler\Hydration\IdMode;
use Saola\Compiler\Preprocessor\Preprocessor;
use Saola\Compiler\Source\SourceParts;
use Saola\Compiler\Source\SourceSplitter;
use Saola\Compiler\Template\ImportParser;

final class SaolaCompiler
{
    private readonly DirectiveRegistry $directiveRegistry;
    private readonly string $viewTemplate;
    private readonly string $wrapperTemplate;

    public function __construct(?DirectiveRegistry $directives = null)
    {
        $this->directiveRegistry = $directives ?? DirectiveRegistry::builtins();
        $base = dirname(__DIR__).'/resources/templates';
        $view = file_get_contents($base.'/view.js');
        $wrapper = file_get_contents($base.'/wraper.js');
        if (!is_string($view) || !is_string($wrapper)) {
            throw new \RuntimeException('Không thể nạp template nội bộ của Saola Compiler.');
        }
        $this->viewTemplate = $view;
        $this->wrapperTemplate = $wrapper;
    }

    public function directives(): DirectiveRegistry
    {
        return $this->directiveRegistry;
    }

    public function compile(string $source, CompileOptions $options): CompileResult
    {
        try {
            $this->validateOptions($options);
            if (strlen($source) > $options->maxFileBytes) {
                throw new CompileException('Source vượt giới hạn maxFileBytes.', $options->viewPath);
            }
            if ($options->sandbox) {
                $this->assertSandboxSafe($source, $options);
            }

            $mode = IdMode::tryFrom(strtolower($options->idMode));
            if ($mode === null) {
                throw new CompileException("idMode không hợp lệ: {$options->idMode}", $options->viewPath);
            }

            // Hai source có thể khác phần text do custom directive, nhưng được
            // mở rộng trong cùng một transaction và dùng chung idMode/options.
            $bladeSource = $this->directiveRegistry->transform($source, 'blade');
            $jsSource = $this->directiveRegistry->transform($source, 'js');
            $splitter = new SourceSplitter();
            $bladeParts = (new Preprocessor($options->assetPrefix))->preprocess($splitter->split($bladeSource));
            $jsParts = (new Preprocessor($options->assetPrefix))->preprocess($splitter->split($jsSource));

            $bladeInput = $this->buildBladeInput($bladeParts, $bladeSource);
            $jsInput = $this->buildJsInput($jsParts);

            // Cả hai luôn được sinh để cùng đi qua một cấu hình marker. Target
            // chỉ quyết định field nào được trả về cho caller.
            $compiledBlade = (new BladeEmitter(idMode: $mode))->compile($bladeInput);
            $compiledBlade = $this->injectSsrHeadAssets($compiledBlade, $bladeSource);
            $mainCompiler = new MainCompiler($this->viewTemplate, $mode, $this->wrapperTemplate);
            $compiledJs = $mainCompiler
                ->compileBladeToJs(
                    $jsInput,
                    $options->viewPath,
                    $options->functionName,
                    $options->factoryName,
                    $options->lang === Lang::Ts,
                );

            $imports = (new ImportParser())->parseImports($jsInput);
            $css = $this->scopedStyles($source);
            $markers = $this->collectMarkers($compiledBlade, $compiledJs);

            return new CompileResult(
                blade: $options->emit === Target::JsOnly ? null : $compiledBlade,
                js: $options->emit === Target::BladeOnly ? null : $compiledJs,
                css: $css,
                imports: $imports,
                markers: $markers,
                // Blade luôn được sinh kể cả khi target là JsOnly, nên soát
                // được cả hai chiều: view này rồi cũng sẽ SSR ở đâu đó.
                warnings: array_merge(
                    // [sao2js] tên hàm lạ — gom trong lúc sinh JS
                    $mainCompiler->warnings(),
                    // [sao2blade] builtin JS rơi vào Blade — soát trên output cuối
                    BladeBuiltinCheck::scan($compiledBlade, $options->viewPath),
                    // [sao2blade] nội suy "{$...}" PHP không parse nổi
                    BladeInterpolationCheck::scan($compiledBlade, $options->viewPath),
                ),
            );
        } catch (CompileException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CompileException($e->getMessage(), $options->viewPath, null, $e);
        }
    }

    public function compileFile(string $path, CompileOptions $options): CompileResult
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new CompileException("Không đọc được file: {$path}", $options->viewPath);
        }
        $source = file_get_contents($path);
        if (!is_string($source)) {
            throw new CompileException("Không đọc được file: {$path}", $options->viewPath);
        }
        $result = $this->compile($source, $options);
        if ($options->bladeOutputPath !== null && $result->blade !== null) {
            $this->atomicWrite($options->bladeOutputPath, $result->blade);
        }
        if ($options->jsOutputPath !== null && $result->js !== null) {
            $this->atomicWrite($options->jsOutputPath, $result->js);
        }
        return $result;
    }

    public function compileDirectory(string $dir, CompileOptions $options): BatchResult
    {
        if (!is_dir($dir)) {
            throw new CompileException("Thư mục không tồn tại: {$dir}");
        }
        $root = realpath($dir);
        if ($root === false) {
            throw new CompileException("Không resolve được thư mục: {$dir}");
        }
        $files = [];
        $totalBytes = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            if (!$item->isFile() || strtolower($item->getExtension()) !== 'sao') continue;
            $files[] = $item->getPathname();
            $totalBytes += $item->getSize();
            if (count($files) > $options->maxFiles) {
                throw new CompileException("Batch vượt giới hạn {$options->maxFiles} files.");
            }
            if ($item->getSize() > $options->maxFileBytes || $totalBytes > $options->maxTotalBytes) {
                throw new CompileException('Batch vượt giới hạn dung lượng.');
            }
        }
        sort($files, SORT_STRING);

        $started = microtime(true);
        $results = [];
        $errors = [];
        $views = [];
        foreach ($files as $path) {
            if (microtime(true) - $started > $options->compileTimeout) {
                $errors[] = ['file' => $path, 'message' => 'Batch compile timeout.'];
                break;
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
            $stem = substr($relative, 0, -4);
            $viewPath = $options->namespace.str_replace('/', '.', $stem);
            $fileOptions = clone $options;
            $fileOptions->viewPath = $viewPath;
            $fileOptions->functionName = self::pascalCase(basename($stem));
            $fileOptions->factoryName = self::pascalCase(str_replace('/', '-', $options->namespace.$stem));
            if ($options->bladeOutputPath !== null) {
                $fileOptions->bladeOutputPath = rtrim($options->bladeOutputPath, '/\\').DIRECTORY_SEPARATOR.$stem.'.blade.php';
            }
            if ($options->jsOutputPath !== null) {
                $fileOptions->jsOutputPath = rtrim($options->jsOutputPath, '/\\').DIRECTORY_SEPARATOR.$stem.'.'.$options->lang->value;
            }
            try {
                $results[$relative] = $this->compileFile($path, $fileOptions);
                $url = rtrim($options->publicBaseUrl, '/').'/'.ltrim($stem.'.'.$options->lang->value, '/');
                $views[$viewPath] = $url;
            } catch (\Throwable $e) {
                $errors[] = ['file' => $relative, 'message' => $e->getMessage()];
            }
        }
        ksort($views, SORT_STRING);
        $revision = substr(hash('sha256', json_encode($views, JSON_UNESCAPED_SLASHES) ?: ''), 0, 8);
        return new BatchResult($results, $errors, ['revision' => $revision, 'views' => $views]);
    }

    private function validateOptions(CompileOptions $options): void
    {
        if ($options->viewPath === '' || $options->functionName === '' || $options->factoryName === '') {
            throw new CompileException('viewPath, functionName và factoryName không được rỗng.', $options->viewPath);
        }
        if ($options->sandbox && $options->lang === Lang::Ts) {
            throw new CompileException('Runtime sandbox chỉ hỗ trợ JavaScript, không hỗ trợ TypeScript.', $options->viewPath);
        }
    }

    private function assertSandboxSafe(string $source, CompileOptions $options): void
    {
        $blocked = [
            '/@php\b/i' => '@php', '/@endphp\b/i' => '@endphp', '/@exec\b/i' => '@exec',
            '/\b(?:system|exec|shell_exec|passthru|proc_open|popen|file_get_contents|file_put_contents|unlink|eval)\s*\(/i' => 'hàm ngoài allowlist',
        ];
        foreach ($blocked as $pattern => $label) {
            if (preg_match($pattern, $source) === 1) {
                throw new CompileException("Sandbox từ chối {$label}.", $options->viewPath);
            }
        }
        if (preg_match('/@import\s*\([^)]*(?:\.\.\/|\.\.\\\\|["\']\s*\/)/i', $source) === 1) {
            throw new CompileException('Sandbox từ chối @import vượt khỏi thư mục theme.', $options->viewPath);
        }
        $depth = 0;
        preg_match_all('/@(include|importInclude)\s*\(/i', $source, $matches);
        $depth += count($matches[0] ?? []);
        if ($depth > $options->maxIncludeDepth) {
            throw new CompileException("Vượt giới hạn include {$options->maxIncludeDepth}.", $options->viewPath);
        }
    }

    private function buildBladeInput(SourceParts $parts, string $source): string
    {
        $content = $parts->declarations === [] ? '' : implode("\n", $parts->declarations)."\n\n";
        $template = $parts->bladeWithSSR !== '' ? $parts->bladeWithSSR : $parts->blade;
        $content .= $parts->wrapperType === null
            ? $template
            : "<{$parts->wrapperType}>\n{$template}\n</{$parts->wrapperType}>";
        preg_match_all('/<style[^>]*\bscoped\b[^>]*>[\s\S]*?<\/style>/i', $source, $matches);
        if (($matches[0] ?? []) !== []) $content .= "\n".implode("\n", $matches[0]);
        return $content;
    }

    private function buildJsInput(SourceParts $parts): string
    {
        $content = $parts->declarations === [] ? '' : implode("\n", $parts->declarations)."\n\n";
        // Gom asset trên bản ĐÃ LÀM TRẮNG comment. `<script>` nhắc trong chú
        // thích mở một match chạy tới `</script>` THẬT, nên "asset" hoá ra là
        // phần đuôi comment cộng cả khối script thật — rồi được chèn lên đầu
        // input JS (§16, chỗ này bị bỏ sót).
        $scan = BladeComment::blank($parts->cleanedContent);
        $assets = [
            ...self::matchesFromOriginal('/<script[^>]*>[\s\S]*?<\/script>/i', $scan, $parts->cleanedContent),
            ...self::matchesFromOriginal('/<style[^>]*>[\s\S]*?<\/style>/i', $scan, $parts->cleanedContent),
            ...self::matchesFromOriginal(
                '/<link\b(?=[^>]*\brel\s*=\s*["\'][^"\']*\bstylesheet\b[^"\']*["\'])[^>]*>/i',
                $scan,
                $parts->cleanedContent,
            ),
        ];
        if ($assets !== []) $content .= implode("\n", $assets)."\n\n";
        $content .= $parts->wrapperType === null
            ? $parts->blade
            : "<{$parts->wrapperType}>\n{$parts->blade}\n</{$parts->wrapperType}>";
        return $content;
    }

    /**
     * Khớp trên bản làm trắng comment, CẮT từ bản gốc theo offset.
     *
     * Cắt từ gốc chứ không đọc bản trắng: thân script/style thật có thể chứa
     * `{{--` (chuỗi JS, selector CSS) và sẽ bị làm trắng oan.
     *
     * @return list<string>
     */
    private static function matchesFromOriginal(string $pattern, string $scan, string $original): array
    {
        $out = [];

        foreach (Re::matchAll($pattern, $scan, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) as $set) {
            $out[] = substr($original, $set[0][1], strlen($set[0][0]));
        }

        return $out;
    }

    /**
     * `<link rel=stylesheet>` / `<script src>` khai báo trong .sao → directive
     * ĐĂNG KÝ ở đầu file blade, không in thẻ tại chỗ khai báo.
     *
     * In tại chỗ là nguồn của lỗi quirks mode: với trang `@extends`, phần output
     * nằm ngoài block được echo TRƯỚC khi layout in `<!DOCTYPE html>`, mà doctype
     * đứng sau nội dung thì trình duyệt bỏ qua nó — cả trang chạy BackCompat.
     *
     * Danh sách asset lấy từ CHÍNH RegisterParser mà đường JS dùng để sinh
     * `styles`/`scripts`, nên href/src và attribute hai bên luôn khớp. Đó là
     * điều kiện để AssetManager phía client nhận ra node SSR và ADOPT nó thay vì
     * chèn bản thứ hai lúc hydrate (findExistingStylesheet / findExistingScript).
     *
     * Chèn ở ĐẦU file cho mọi loại view: layout phải đăng ký xong trước khi
     * `@pageStart` in <head>, page thì `@extends` render con trước cha nên chỗ
     * nào cũng kịp.
     */
    private function injectSsrHeadAssets(string $content, string $source): string
    {
        $resources = (new RegisterParser())->parseRegisterContent($source)['resources'] ?? [];
        $lines = [];
        foreach ($resources as $resource) {
            $attributes = $resource['attrs'] ?? [];
            $isLink = ($resource['tag'] ?? '') === 'link';
            $urlKey = $isLink ? 'href' : 'src';
            $url = (string) ($attributes[$urlKey] ?? '');
            if (trim($url) === '') {
                continue;
            }
            // rel/href/src do helper tự in — không nhồi lại vào mảng attribute.
            unset($attributes[$urlKey], $attributes['rel']);
            $args = self::bladeStringExpression($url);
            if ($attributes !== []) {
                $args .= ', '.self::phpArrayLiteral($attributes);
            }
            // Khai báo trùng hệt nhau trong cùng file chỉ cần một dòng; trùng
            // giữa các view thì store phía server lo (theo id, hoặc theo url).
            $lines['@'.($isLink ? 'addCssLink' : 'addScriptSrc').'('.$args.')'] = true;
        }

        return $lines === [] ? $content : implode("\n", array_keys($lines))."\n".$content;
    }

    /**
     * URL có thể chứa nội suy Blade (`href="{{ asset('x.css') }}"`) — directive
     * nhận BIỂU THỨC PHP nên phải đổi thành phép nối chuỗi.
     */
    private static function bladeStringExpression(string $value): string
    {
        if (!str_contains($value, '{{')) {
            return self::phpString($value);
        }
        $parts = preg_split('/\{\{(.*?)\}\}/s', $value, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$value];
        $out = [];
        foreach ($parts as $index => $part) {
            if ($index % 2 === 1) {
                $expression = trim($part);
                if ($expression !== '') {
                    $out[] = '('.$expression.')';
                }
                continue;
            }
            if ($part !== '') {
                $out[] = self::phpString($part);
            }
        }

        return $out === [] ? "''" : implode('.', $out);
    }

    private static function phpString(string $value): string
    {
        return "'".strtr($value, ['\\' => '\\\\', "'" => "\\'"])."'";
    }

    /** @param array<string, mixed> $attributes */
    private static function phpArrayLiteral(array $attributes): string
    {
        $parts = [];
        foreach ($attributes as $name => $value) {
            if ($value === false || $value === null) {
                continue;
            }
            $key = self::phpString((string) $name);
            $parts[] = $value === true
                ? $key.' => true'
                : $key.' => '.self::bladeStringExpression((string) $value);
        }

        return '['.implode(', ', $parts).']';
    }

    /** @return list<string> */
    private function scopedStyles(string $source): array
    {
        // `<style scoped>` in ra làm ví dụ trong comment KHÔNG phải CSS thật.
        // Bỏ sót chỗ này còn nguy hơn lọt CSS: scope class suy từ CHÍNH nội
        // dung CSS, nên class của MỌI element trong view đổi theo.
        preg_match_all(
            '/<style[^>]*\bscoped\b[^>]*>([\s\S]*?)<\/style>/i',
            BladeComment::blank($source),
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        return array_values(array_map(
            static fn (array $m): string => trim(substr($source, $m[1], strlen($m[0]))),
            $matches[1] ?? [],
        ));
    }

    /** @return list<string> */
    private function collectMarkers(string $blade, string $js): array
    {
        $markers = [];
        preg_match_all('/@(?:hydrate|startReactive|startMarker)\([^\n)]*?["\']([^"\']+)["\']/', $blade, $bladeMatches);
        foreach ($bladeMatches[1] ?? [] as $id) $markers[$id] = true;
        preg_match_all('/this\.(?:html|output|reactive|include|yield|blockOutlet)\(`([^`$]+)`/', $js, $jsMatches);
        foreach ($jsMatches[1] ?? [] as $id) $markers[$id] = true;
        return array_keys($markers);
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new CompileException("Không tạo được thư mục output: {$dir}");
        }
        $temp = $dir.'/.'.basename($path).'.'.bin2hex(random_bytes(6)).'.tmp';
        if (file_put_contents($temp, $contents, LOCK_EX) === false || !rename($temp, $path)) {
            if (is_file($temp)) @unlink($temp);
            throw new CompileException("Không ghi được output: {$path}");
        }
    }

    private static function pascalCase(string $value): string
    {
        $parts = preg_split('/[-_\s.]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return implode('', array_map(static fn (string $part): string => strtoupper($part[0]).substr($part, 1), $parts)) ?: 'View';
    }
}
