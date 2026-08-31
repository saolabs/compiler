<?php

declare(strict_types=1);

namespace Saola\Compiler\Compiler;

use Saola\Compiler\Support\BladeComment;
use Saola\Compiler\Support\Balanced;

final class RegisterParser
{
    private string $registerContent = '';
    /** @var list<array<string, mixed>> */
    private array $scripts = [];
    /** @var list<array<string, mixed>> */
    private array $styles = [];
    private string|array $userDefined = [];
    /** @var list<string> */
    private array $setupContent = [];
    private ?string $setupLang = null;
    private ?string $mergedContent = null;

    public function reset(): void
    {
        $this->registerContent = '';
        $this->scripts = [];
        $this->styles = [];
        $this->userDefined = [];
        $this->setupContent = [];
        $this->setupLang = null;
        $this->mergedContent = null;
    }

    /** @return array<string, mixed> */
    public function parseRegisterContent(string $content, ?string $viewName = null): array
    {
        $this->registerContent = $content;
        $this->scripts = [];
        $this->styles = [];
        $this->userDefined = [];
        $this->setupLang = null;
        $this->mergedContent = null;
        $this->parseScripts($content);
        $this->parseStyles($content);

        return $this->getAllData();
    }

    private function parseScripts(string $content): void
    {
        // Quét trên bản ĐÃ LÀM TRẮNG comment: `<script>` nhắc trong chú thích
        // mở một match chạy tới `</script>` THẬT, nên "nội dung script" hoá ra
        // là phần đuôi comment cộng thẻ mở thật (§16 — chỗ này bị bỏ sót).
        // Làm trắng giữ nguyên độ dài nên offset vẫn trỏ đúng $content gốc;
        // cắt từ GỐC vì thân script thật có thể chứa `{{--`.
        preg_match_all(
            '/<script\b([^>]*?)>(.*?)<\/script>/is',
            BladeComment::blank($content),
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );
        $processedExports = [];
        $processedScripts = [];

        foreach ($matches as $match) {
            $attrs = substr($content, $match[1][1], strlen($match[1][0]));
            $scriptContent = trim(substr($content, $match[2][1], strlen($match[2][0])));
            $full = substr($content, $match[0][1], strlen($match[0][0]));
            if (isset($processedScripts[$full])) {
                continue;
            }
            $processedScripts[$full] = true;

            $isSetup = preg_match('/(?:^|\s)setup(?:\s|=|$)/i', $attrs) === 1;
            if ($isSetup && preg_match('/lang=(["\']?)([^"\'\s>]+)\1/i', $attrs, $lang) === 1) {
                if (in_array(strtolower($lang[2]), ['ts', 'typescript'], true)) {
                    $this->setupLang = 'typescript';
                }
            }

            if (preg_match('/\bsrc\s*=\s*(["\'])([^"\']*?(?:\{\{[^}]*\}\}[^"\']*?)*[^"\']*?)\1/i', $attrs, $src) === 1) {
                $item = ['type' => 'src', 'src' => $src[2]];
                $this->copyAttributes($item, $this->parseAttributes($attrs, ['src']));
                $this->scripts[] = $item;
                continue;
            }

            if ($scriptContent === '') {
                continue;
            }
            $item = ['type' => 'code', 'content' => $scriptContent];
            $this->copyAttributes($item, $this->parseAttributes($attrs));
            $object = $this->findExportObject($scriptContent);
            $remaining = $scriptContent;
            if ($object !== null) {
                $hash = sha1($object);
                if (!isset($processedExports[$hash])) {
                    $processedExports[$hash] = true;
                    $this->extractToUserDefined($object);
                }
                $remaining = $this->removeExportFromScript($scriptContent, $isSetup);
            }

            if ($isSetup) {
                $this->setupContent[] = $scriptContent;
            } elseif (trim($remaining) !== '') {
                $item['content'] = $remaining;
                $this->scripts[] = $item;
            }
        }
    }

    private function parseStyles(string $content): void
    {
        preg_match_all('/<style\b([^>]*?)>(.*?)<\/style>/is', $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $attrs = $match[1];
            $css = trim($match[2]);
            if ($css === '') {
                continue;
            }
            $item = ['type' => 'code', 'content' => $css];
            if (preg_match('/\bscoped\b/i', $attrs) === 1) {
                $item['scoped'] = true;
            }
            $this->copyAttributes($item, $this->parseAttributes($attrs, ['scoped']));
            $this->styles[] = $item;
        }

        preg_match_all('/<link\b([^>]*)>/i', $content, $links, PREG_SET_ORDER);
        foreach ($links as $match) {
            $attrs = $match[1];
            if (preg_match('/\brel\s*=\s*(["\'])([^"\']*)\1/i', $attrs, $rel) !== 1
                || !in_array('stylesheet', preg_split('/\s+/', strtolower($rel[2])) ?: [], true)) {
                continue;
            }
            if (preg_match('/\bhref\s*=\s*(["\'])([^"\']*?(?:\{\{[^}]*\}\}[^"\']*?)*[^"\']*?)\1/i', $match[0], $href) !== 1) {
                continue;
            }
            $item = ['type' => 'href', 'href' => $href[2]];
            $this->copyAttributes($item, $this->parseAttributes($attrs, ['href', 'rel']));
            $this->styles[] = $item;
        }
    }

    /** @param list<string> $exclude @return array<string, mixed> */
    private function parseAttributes(string $attrs, array $exclude = []): array
    {
        $result = ['id' => '', 'className' => '', 'attributes' => []];
        $excluded = array_fill_keys(array_map('strtolower', $exclude), true);
        preg_match_all('/(\w+(?:-\w+)*)(?:=(["\'])([^"\']*?)\2|(?==|\s|$))/', $attrs, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $name = strtolower(trim($match[1]));
            if (isset($excluded[$name])) {
                continue;
            }
            $value = array_key_exists(3, $match) && $match[3] !== '' ? $match[3] : null;
            if ($name === 'id') {
                $result['id'] = $value ?? '';
            } elseif ($name === 'class') {
                $result['className'] = $value ?? '';
            } else {
                $result['attributes'][$name] = $value ?? true;
            }
        }
        if ($result['attributes'] === []) {
            unset($result['attributes']);
        }
        return $result;
    }

    /** @param array<string, mixed> $item @param array<string, mixed> $attrs */
    private function copyAttributes(array &$item, array $attrs): void
    {
        foreach (['id', 'className', 'attributes'] as $key) {
            if (!empty($attrs[$key])) {
                $item[$key] = $attrs[$key];
            }
        }
    }

    private function findExportObject(string $content): ?string
    {
        if (preg_match('/export\s+(?:default\s*)?(\{)/s', $content, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $start = $match[1][1];
        $depth = 1;
        $length = strlen($content);
        for ($i = $start + 1; $i < $length; $i++) {
            $depth += $content[$i] === '{' ? 1 : ($content[$i] === '}' ? -1 : 0);
            if ($depth === 0) {
                return substr($content, $start, $i - $start + 1);
            }
        }
        return null;
    }

    private function removeExportFromScript(string $content, bool $isSetup): string
    {
        $object = $this->findExportObject($content);
        if ($object !== null && preg_match('/export\s+(?:default\s*)?\{/s', $content, $match, PREG_OFFSET_CAPTURE) === 1) {
            $start = $match[0][1];
            $objectStart = strpos($content, '{', $start);
            $end = (int) $objectStart + strlen($object);
            while ($end < strlen($content) && str_contains("; \t", $content[$end])) {
                $end++;
            }
            while ($end < strlen($content) && str_contains("\n\r", $content[$end])) {
                $end++;
            }
            $content = substr($content, 0, $start).substr($content, $end);
        }
        if ($isSetup) {
            return '';
        }
        $content = preg_replace('/import\s+.*?(?:from\s+["\'][^"\']*["\']|["\'][^"\']*["\'])\s*;?\s*\n?/m', '', $content) ?? $content;
        return trim($content);
    }

    private function extractToUserDefined(string $object): void
    {
        $object = trim($object);
        if (!str_starts_with($object, '{') || !str_ends_with($object, '}')) {
            return;
        }
        $inner = trim(substr($object, 1, -1));
        if ($inner !== '') {
            $this->mergedContent = $this->mergedContent === null ? $inner : $this->mergedContent.",\n    ".$inner;
        }
    }

    /** @return list<array<string, mixed>> */
    public function getScripts(): array { return $this->scripts; }
    /** @return list<array<string, mixed>> */
    public function getStyles(): array { return $this->styles; }
    public function getUserDefined(): string|array { return $this->userDefined; }

    /** @return array<string, true> */
    public function getUserMethodNames(): array
    {
        $raw = $this->getLifecycleObj();
        if ($raw === '' || $raw === '{}') {
            return [];
        }
        $text = trim($raw);
        if (str_starts_with($text, '{') && str_ends_with($text, '}')) {
            $text = substr($text, 1, -1);
        }
        $names = [];
        foreach (Balanced::splitTopLevelStripped($text, ',') as $chunk) {
            if (preg_match('/^(?:async\s+)?(?:\*\s*)?([a-zA-Z_$][\w$]*)\s*\(/', trim($chunk), $m) === 1
                || preg_match('/^([a-zA-Z_$][\w$]*)\s*:\s*(?:async\s*)?(?:function\b|\([^()]*\)\s*=>|[a-zA-Z_$][\w$]*\s*=>)/', trim($chunk), $m) === 1) {
                $names[$m[1]] = true;
            }
        }
        return $names;
    }

    /** @return array<string, mixed> */
    public function getAllData(): array
    {
        $lifecycle = $this->getLifecycleObj();
        return [
            'scripts' => $this->scripts, 'styles' => $this->styles,
            'userDefined' => $lifecycle, 'lifecycle' => $lifecycle,
            'setup' => $this->getSetupScript(), 'setupContent' => $this->getSetupContent(),
            'setupLang' => $this->setupLang, 'sections' => [],
            'css' => ['inline' => $this->getInlineCss(), 'external' => $this->getExternalCss()],
            'resources' => $this->getResources(),
        ];
    }

    public function getLifecycleObj(): string
    {
        if ($this->mergedContent !== null && $this->mergedContent !== '') {
            return "{\n    ".$this->mergedContent."\n}";
        }
        return is_string($this->userDefined) ? $this->userDefined : '{}';
    }

    public function getSetupScript(): string
    {
        return implode("\n\n", array_column(array_values(array_filter($this->scripts, fn ($s) => $s['type'] === 'code')), 'content'));
    }
    public function getSetupContent(): string { return implode("\n\n", $this->setupContent); }
    /** @return array<string, mixed> */ public function getSectionScripts(): array { return []; }
    /** @return array<string, mixed> */
    public function getAllScripts(?string $viewName = null): array { return ['lifecycle' => $this->userDefined, 'setup' => $this->getSetupScript(), 'scripts' => $this->scripts]; }
    public function getInlineCss(): string { return implode("\n", array_column(array_values(array_filter($this->styles, fn ($s) => $s['type'] === 'code')), 'content')); }
    /** @return list<string> */ public function getExternalCss(): array { return array_values(array_map(fn ($s) => $s['href'], array_filter($this->styles, fn ($s) => $s['type'] === 'href'))); }

    /** @return list<array<string, mixed>> */
    public function getResources(): array
    {
        $resources = [];
        foreach ($this->scripts as $script) {
            if ($script['type'] !== 'src') continue;
            $attrs = ['src' => $script['src'], ...($script['attributes'] ?? [])];
            if (!empty($script['id'])) $attrs['id'] = $script['id'];
            if (!empty($script['className'])) $attrs['class'] = $script['className'];
            $resources[] = ['tag' => 'script', 'uuid' => 'script-'.count($resources), 'attrs' => $attrs];
        }
        foreach ($this->styles as $style) {
            if ($style['type'] !== 'href') continue;
            $attrs = ['rel' => 'stylesheet', 'href' => $style['href'], ...($style['attributes'] ?? [])];
            if (!empty($style['id'])) $attrs['id'] = $style['id'];
            if (!empty($style['className'])) $attrs['class'] = $style['className'];
            $resources[] = ['tag' => 'link', 'uuid' => 'link-'.count($resources), 'attrs' => $attrs];
        }
        return $resources;
    }
}
