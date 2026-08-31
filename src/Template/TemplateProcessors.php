<?php

declare(strict_types=1);

namespace Saola\Compiler\Template;

use Saola\Compiler\Directive\DirectiveProcessor;
use Saola\Compiler\Expr\ExpressionCompiler;
use Saola\Compiler\Support\Balanced;

/** Port của sao2js/template_processors.py. */
final class TemplateProcessors
{
    public function __construct(
        private readonly ExpressionCompiler $expressions = new ExpressionCompiler(),
        private readonly DirectiveProcessor $directives = new DirectiveProcessor(),
    ) {
    }

    public function processTemplateLine(string $line): string
    {
        $line = preg_replace_callback('/@yield\s*\(\s*(.*?)\s*\)/s', function (array $m): string {
            $value = trim($m[1]);
            $js = str_starts_with($value, '$') ? $this->expressions->compileStatement($value) : $value;
            return '${App.Helper.yield(' . $js . ')}';
        }, $line) ?? $line;

        while (preg_match('/@out\s*\(/', $line, $m, PREG_OFFSET_CAPTURE) === 1) {
            $start = $m[0][1]; $open = $start + strlen($m[0][0]) - 1;
            [$content, $end] = Balanced::extractParensAt($line, $open);
            if ($content === null) break;
            $replacement = $this->directives->processOutDirective(substr($line, $start, $end - $start));
            if ($replacement === null) break;
            $line = substr($line, 0, $start) . $replacement . substr($line, $end);
        }

        $line = preg_replace_callback('/@include\s*\(\s*([^,\'\"][^)]*?)\s*,\s*(\[[^\]]*\]|\{[^}]*\}|[^)]*)\s*\)/s', function (array $m): string {
            return '${App.View.renderView(this.__include(' . $this->expressions->compileStatement(trim($m[1])) . ', ' . $this->convertPhpArrayToJson(trim($m[2])) . '))}';
        }, $line) ?? $line;
        $line = preg_replace_callback('/@include\s*\(\s*[\'\"]([^\'\"]*)[\'\"]\s*,\s*(\[[^\]]*\]|\{[^}]*\}|[^)]*)\s*\)/s', function (array $m): string {
            return '${App.View.renderView(this.__include(\'' . $m[1] . '\', ' . $this->convertPhpArrayToJson(trim($m[2])) . '))}';
        }, $line) ?? $line;
        $line = preg_replace_callback('/@include\s*\(\s*([^,\'\"][^)]*?)\s*\)/', fn (array $m): string => '${App.View.renderView(this.__include(' . $this->expressions->compileStatement(trim($m[1])) . '))}', $line) ?? $line;
        $line = preg_replace('/@include\s*\(\s*[\'\"]([^\'\"]*)[\'\"]\s*\)/', '${App.View.renderView(this.__include("$1", {}))}', $line) ?? $line;

        $line = preg_replace_callback('/@(?:template|view)\s*\(([^)]*)\)/is', function (array $m): string {
            $expression = trim($m[1]);
            if ($expression === '') return '__WRAPPER_CONFIG__ = { enable: true };';
            if (str_contains($expression, ':') || str_contains($expression, '=>')) {
                $attributes = $this->parseTemplateParameters($expression);
                $tag = $attributes['tag'] ?? null;
                unset($attributes['tag']);
                if (array_key_exists('subscribe', $attributes)) {
                    $attributes['subscribe'] = $this->processSubscribeValue($attributes['subscribe']);
                }
                return $this->generateWrapperConfig($attributes, is_string($tag) ? $tag : null);
            }
            if (str_starts_with($expression, '[') && str_ends_with($expression, ']')) {
                return $this->generateWrapperConfig($this->parseWrapAttributes($expression));
            }
            [$tag, $attributes] = $this->parseWrapExpression($expression);
            return $this->generateWrapperConfig($attributes, $tag);
        }, $line) ?? $line;
        $line = preg_replace_callback('/@(?:wrap|wrapper)\s*\(\s*([^)]*?)\s*\)/is', function (array $m): string {
            $expression = trim($m[1]);
            if ($expression === '') return '__WRAPPER_CONFIG__ = { enable: true };';
            if (str_starts_with($expression, '[') && str_ends_with($expression, ']')) {
                return $this->generateWrapperConfig($this->parseWrapAttributes($expression));
            }
            [$tag, $attributes] = $this->parseWrapExpression($expression);
            return $this->generateWrapperConfig($attributes, $tag);
        }, $line) ?? $line;
        $line = preg_replace('/@(?:template|view)(?:\s*\(\s*\))?\s*$/i', '__WRAPPER_CONFIG__ = { enable: true };', $line) ?? $line;
        $line = preg_replace('/@(?:wrap|wrapper)(?:\s*\(\s*\))?\s*$/i', '__WRAPPER_CONFIG__ = { enable: true };', $line) ?? $line;
        $line = preg_replace('/@end(?:wrap|wrapper|view|template)(?:\s*\(\s*\))?\s*$/i', '__WRAPPER_END__', $line) ?? $line;

        $line = preg_replace_callback('/@(?:yieldon|onyield|yieldlisten|yieldwatch)\s*\(\s*\[([^\[\]]*(?:\[[^\[\]]*\][^\[\]]*)*)\]\s*\)/is', function (array $m): string {
            $output = []; $subscriptions = [];
            foreach ($this->splitTopLevel(trim($m[1]), ',') as $item) {
                $pair = $this->splitTopLevel($item, '=>');
                if (count($pair) < 2) continue;
                $key = trim(trim($pair[0]), "'\"");
                $value = ltrim(trim(trim(implode('=>', array_slice($pair, 1))), "'\""), '$');
                if ($key === '#content') $output[] = 'data-yield-content="' . $value . '"';
                elseif ($key === '#children') $output[] = 'data-yield-children="' . $value . '"';
                else {
                    $output[] = $key . '="${App.Helper.yieldContent(\'' . $value . '\', null)}"';
                    $subscriptions[] = $key . ':' . $value;
                }
            }
            if ($subscriptions !== []) $output[] = 'data-yield-attr="' . implode(',', $subscriptions) . '"';
            return implode(' ', $output);
        }, $line) ?? $line;
        $line = preg_replace_callback('/@(?:yieldon|onyield|yieldlisten|yieldwatch)\s*\(\s*[\'\"]([^\'\"]*)[\'\"]\s*,\s*[\'\"]([^\'\"]*)[\'\"]\s*(?:,\s*[\'\"]([^\'\"]*)[\'\"])?\s*\)/i', function (array $m): string {
            $default = isset($m[3]) && $m[3] !== '' ? "'{$m[3]}'" : 'null';
            return $m[1] . '="${App.Helper.yieldContent(\'' . $m[2] . '\', ' . $default . ')}" data-yield-attr="' . $m[1] . ':' . $m[2] . '"';
        }, $line) ?? $line;
        $line = $this->processYieldAttributes($line);

        // @attr có thể lồng function call; dùng balanced scan thay vì regex đóng ngoặc đầu.
        while (preg_match('/@attr\s*\(/', $line, $m, PREG_OFFSET_CAPTURE) === 1) {
            $start = $m[0][1]; $open = $start + strlen($m[0][0]) - 1;
            [$content, $end] = Balanced::extractParensAt($line, $open);
            if ($content === null) break;
            $replacement = $this->processAttrDirective(trim($content));
            if ($replacement === null) break;
            $line = substr($line, 0, $start) . $replacement . substr($line, $end);
        }

        $line = preg_replace_callback('/@subscribe\s*\(([^)]*)\)/i', fn (array $m): string => $this->processSubscribeDirective($m[1], $m[0]), $line) ?? $line;

        $line = preg_replace('/<([^>]*?)\s@(?:wrap|wrapAttr|wrapattr)\s*(?:\([^)]*\))?\s*([^>]*?)>/i', '<$1 $2 ${this.wrapattr()}>', $line) ?? $line;
        $line = preg_replace('/\bAttr\(\)\s*/', '', $line) ?? $line;
        $line = str_replace('@viewId', '${App.Helper.generateViewId()}', $line);

        $line = preg_replace_callback('/\{!!\s*(.*?)\s*!!\}/s', fn (array $m): string => '${' . $this->expressions->compileStatement(trim($m[1])) . '}', $line) ?? $line;
        $line = preg_replace_callback('/\{\{\s*(.*?)\s*\}\}/s', function (array $m): string {
            $js = $this->expressions->compileStatement(trim($m[1]));
            return $this->isComplexStructure($js) ? '${' . $js . '}' : '${App.Helper.escString(' . $js . ')}';
        }, $line) ?? $line;
        $line = preg_replace_callback('/\{\s*\$(\w+)\s*\}/', fn (array $m): string => '${' . $this->expressions->compileStatement($m[1]) . '}', $line) ?? $line;
        $line = preg_replace('/@useState\s*\([^)]*\)/i', '', $line) ?? $line;

        return $line;
    }

    public function processServersideDirective(string $line): string|false
    {
        foreach (['@serverside','@serverSide','@ssr','@SSR','@useSSR','@useSsr'] as $alias) if (str_starts_with($line, $alias)) return 'skip_until_@endserverside';
        return false;
    }

    public function processClientsideDirective(string $line): string|false
    {
        foreach (['@clientside','@clientSide','@csr','@CSR','@useCSR','@useCsr'] as $alias) if (str_starts_with($line, $alias)) return 'remove_directive_markers_until_@endclientside';
        return false;
    }

    private function processAttrDirective(string $expression): ?string
    {
        if ($expression === '') return '${this.__attr({})}';
        $entries = [];
        if (str_starts_with($expression, '[') && str_ends_with($expression, ']')) {
            foreach ($this->splitTopLevel(substr($expression, 1, -1), ',') as $pair) {
                $parts = $this->splitTopLevel($pair, '=>');
                if (count($parts) < 2) continue;
                $key = trim(trim($parts[0]), "'\"");
                $entries[$key] = trim(implode('=>', array_slice($parts, 1)));
            }
        } elseif (preg_match('/^([\'\"])(.+?)\1\s*,\s*(.+)$/s', $expression, $m) === 1) {
            $entries[$m[2]] = trim($m[3]);
        } else return null;

        $parts = [];
        foreach ($entries as $key => $value) {
            $states = $this->extractVariables($value);
            $parts[] = '"' . $key . '": {"states": ' . json_encode($states, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                . ', "render": () => ' . $this->expressions->compileStatement($value) . '}';
        }
        return '${this.__attr({' . implode(', ', $parts) . '})}';
    }

    private function processYieldAttributes(string $line): string
    {
        $pattern = '/@yieldattr\s*\(\s*[\'\"]([^\'\"]*)[\'\"]\s*,\s*[\'\"]([^\'\"]*)[\'\"]\s*(?:,\s*[\'\"]([^\'\"]*)[\'\"])?\s*\)/i';
        if (preg_match_all($pattern, $line, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) < 1) return $line;
        $attributes = []; $subscriptions = [];
        foreach ($matches as $match) {
            $default = isset($match[3][0]) && $match[3][0] !== '' ? "'{$match[3][0]}'" : 'null';
            $attributes[] = $match[1][0] . '="${App.Helper.yieldContent(\'' . $match[2][0] . '\', ' . $default . ')}"';
            $subscriptions[] = $match[1][0] . ':' . $match[2][0];
        }
        for ($i = count($matches) - 1; $i >= 0; $i--) {
            $line = substr($line, 0, $matches[$i][0][1]) . substr($line, $matches[$i][0][1] + strlen($matches[$i][0][0]));
        }
        $position = strpos($line, '>');
        if ($position !== false) {
            $insert = ' ' . implode(' ', $attributes) . ' data-yield-attr="' . implode(',', $subscriptions) . '"';
            $line = substr($line, 0, $position) . $insert . substr($line, $position);
        }
        return $line;
    }

    private function processSubscribeDirective(string $content, string $original): string
    {
        $content = trim($content);
        if (preg_match('/^\$?(\w+)$/', $content, $m) === 1) {
            return '${this.__subscribe({"#all": ["' . $m[1] . '"]})}';
        }
        if (preg_match('/^\$?(\w+)\s*,\s*[\'\"]([^\'\"]*)[\'\"]$/', $content, $m) === 1) {
            return '${this.__subscribe({"' . $m[2] . '": ["' . $m[1] . '"]})}';
        }
        if (preg_match('/^\[([^\]]+)\]\s*,\s*[\'\"]([^\'\"]*)[\'\"]$/', $content, $m) === 1) {
            return '${this.__subscribe({"' . $m[2] . '": ' . $this->pythonJsonArray($this->parseStateArray($m[1])) . '})}';
        }
        if (str_starts_with($content, '[') && str_ends_with($content, ']')) {
            $inside = trim(substr($content, 1, -1));
            if (!str_contains($inside, '=>')) {
                return '${this.__subscribe({"#all": ' . $this->pythonJsonArray($this->parseStateArray($inside)) . '})}';
            }
            $entries = [];
            foreach ($this->splitTopLevel($inside, ',') as $item) {
                $pair = $this->splitTopLevel($item, '=>');
                if (count($pair) < 2) continue;
                $key = trim(trim($pair[0]), "'\"");
                $raw = trim(implode('=>', array_slice($pair, 1)));
                $values = str_starts_with($raw, '[') && str_ends_with($raw, ']')
                    ? $this->parseStateArray(substr($raw, 1, -1))
                    : [ltrim($raw, '$')];
                $entries[] = '"' . $key . '": ' . $this->pythonJsonArray($values);
            }
            return '${this.__subscribe({' . implode(', ', $entries) . '})}';
        }
        return $original;
    }

    /** @return list<string> */
    private function parseStateArray(string $content): array
    {
        return array_values(array_map(
            static fn (string $value): string => ltrim(trim($value), '$'),
            array_filter($this->splitTopLevel($content, ','), static fn (string $value): bool => trim($value) !== ''),
        ));
    }

    /** @param list<string> $values */
    private function pythonJsonArray(array $values): string
    {
        return '[' . implode(', ', array_map(static fn (string $value): string => '"' . $value . '"', $values)) . ']';
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function parseWrapExpression(string $expression): array
    {
        $parts = $this->splitTopLevel($expression, ',');
        $tag = trim(trim(array_shift($parts) ?? ''), "'\"");
        return [$tag, $parts === [] ? [] : $this->parseWrapAttributes(implode(',', $parts))];
    }

    /** @return array<string, mixed> */
    private function parseWrapAttributes(string $expression): array
    {
        $expression = trim($expression);
        if (str_starts_with($expression, '[') && str_ends_with($expression, ']')) $expression = substr($expression, 1, -1);
        $attributes = [];
        foreach ($this->splitTopLevel($expression, ',') as $item) {
            $pair = $this->splitTopLevel($item, '=>');
            if (count($pair) < 2) continue;
            $key = trim(trim($pair[0]), "'\"");
            $value = trim(trim(implode('=>', array_slice($pair, 1))), "'\"");
            if (in_array($key, ['follow', 'subscribe'], true)) {
                if ($value === 'false') $attributes[$key] = false;
                elseif ($value === 'true') $attributes[$key] = true;
                elseif (str_starts_with($value, '[') && str_ends_with($value, ']')) {
                    $attributes[$key] = array_map(static fn (string $part): string => trim(trim($part), "'\""), $this->splitTopLevel(substr($value, 1, -1), ','));
                } else $attributes[$key] = $value;
            } else $attributes[$key] = $value;
        }
        return $attributes;
    }

    /** @return array<string, mixed> */
    private function parseTemplateParameters(string $expression): array
    {
        $expression = trim($expression);
        if (str_starts_with($expression, '[') && str_ends_with($expression, ']')) return $this->parseWrapAttributes($expression);
        $separator = str_contains($expression, ':') ? ':' : '=';
        $attributes = [];
        foreach ($this->splitTopLevel($expression, ',') as $item) {
            $pair = $this->splitTopLevel($item, $separator);
            if (count($pair) >= 2) {
                $key = ltrim(trim(trim(array_shift($pair)), "'\""), '$');
                $attributes[$key] = trim(implode($separator, $pair));
            } elseif (!array_key_exists('tag', $attributes)) $attributes['tag'] = trim(trim($item), "'\"");
        }
        return $attributes;
    }

    private function processSubscribeValue(mixed $value): mixed
    {
        if (!is_string($value)) return $value;
        $value = trim($value);
        if ((str_starts_with($value, "'") && str_ends_with($value, "'")) || (str_starts_with($value, '"') && str_ends_with($value, '"'))) $value = trim(substr($value, 1, -1));
        if (strtolower($value) === 'false') return false;
        if (strtolower($value) === 'true') return true;
        if (str_starts_with($value, '[') && str_ends_with($value, ']')) return array_map(static fn (string $part): string => ltrim(trim(trim($part), "'\""), '$'), $this->splitTopLevel(substr($value, 1, -1), ','));
        if (str_starts_with($value, '$')) return [substr($value, 1)];
        return $value;
    }

    /** @param array<string, mixed> $attributes */
    private function generateWrapperConfig(array $attributes, ?string $tag = null): string
    {
        $parts = ['enable: true', $tag !== null && $tag !== '' ? 'tag: "' . trim($tag, "'\"") . '"' : 'tag: null'];
        $follow = $attributes['follow'] ?? $attributes['subscribe'] ?? null;
        unset($attributes['follow'], $attributes['subscribe']);
        if ($follow !== null) {
            if ($follow === false || $follow === 'false') $parts[] = 'subscribe: false';
            elseif ($follow === true || $follow === 'true') $parts[] = 'subscribe: true';
            elseif (is_array($follow)) $parts[] = 'subscribe: ' . $this->pythonJsonArray(array_map(static fn (mixed $item): string => ltrim((string) $item, '$'), $follow));
            else $parts[] = 'subscribe: ["' . ltrim((string) $follow, '$') . '"]';
        }
        if ($attributes === []) $parts[] = 'attributes: {}';
        else {
            $pairs = [];
            foreach ($attributes as $key => $value) $pairs[] = "'{$key}': '" . trim((string) $value, "'\"") . "'";
            $parts[] = 'attributes: {' . implode(', ', $pairs) . '}';
        }
        return '__WRAPPER_CONFIG__ = { ' . implode(', ', $parts) . ' };';
    }

    /** @return list<string> */
    private function extractVariables(string $expr): array
    {
        $result=[];$single=false;$double=false;$escape=false;$length=strlen($expr);
        for($i=0;$i<$length;){$ch=$expr[$i];if($escape){$escape=false;$i++;continue;}if($ch==='\\'){$escape=true;$i++;continue;}if($single){if($ch==="'")$single=false;$i++;continue;}if($double){if($ch==='"'){$double=false;$i++;continue;}if($ch!=='$'){$i++;continue;}}else{if($ch==="'"){$single=true;$i++;continue;}if($ch==='"'){$double=true;$i++;continue;}if($ch!=='$'){$i++;continue;}}$j=$i+1;if($j<$length&&preg_match('/[a-zA-Z_]/',$expr[$j])===1){$start=$j++;while($j<$length&&preg_match('/[a-zA-Z0-9_]/',$expr[$j])===1)$j++;$name=substr($expr,$start,$j-$start);if(!in_array($name,$result,true))$result[]=$name;$i=$j;continue;}$i++;}
        return $result;
    }

    /** @return list<string> */
    private function splitTopLevel(string $text, string $delimiter): array
    {
        $parts=[];$buffer='';$paren=0;$bracket=0;$brace=0;$single=false;$double=false;$length=strlen($text);$dlen=strlen($delimiter);
        for($i=0;$i<$length;){$ch=$text[$i];if($ch==='\\'){$buffer.=$ch;if($i+1<$length)$buffer.=$text[++$i];$i++;continue;}if($single){$buffer.=$ch;if($ch==="'")$single=false;$i++;continue;}if($double){$buffer.=$ch;if($ch==='"')$double=false;$i++;continue;}if($ch==="'"){$single=true;$buffer.=$ch;$i++;continue;}if($ch==='"'){$double=true;$buffer.=$ch;$i++;continue;}if($ch==='(')$paren++;elseif($ch===')')$paren=max(0,$paren-1);elseif($ch==='[')$bracket++;elseif($ch===']')$bracket=max(0,$bracket-1);elseif($ch==='{')$brace++;elseif($ch==='}')$brace=max(0,$brace-1);if($paren===0&&$bracket===0&&$brace===0&&substr($text,$i,$dlen)===$delimiter){$parts[]=$buffer;$buffer='';$i+=$dlen;continue;}$buffer.=$ch;$i++;}
        if($buffer!=='')$parts[]=$buffer;return $parts;
    }

    private function convertPhpArrayToJson(string $value): string
    {
        $warnings = '';
        if (preg_match_all('/\$([a-zA-Z_]\w*)/', $value, $matches) > 0) {
            foreach ($matches[1] as $name) {
                $warnings .= 'Warning: Undefined variable ' . $name . " in Command line code on line 1\n";
            }
            $value = preg_replace('/\$([a-zA-Z_]\w*)/', 'null', $value) ?? $value;
        }
        $compiled=$this->expressions->compileStatement($value);$decoded=json_decode($compiled,true);
        $result=json_last_error()===JSON_ERROR_NONE?json_encode($decoded,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR):(preg_replace("/'([^']*)'/",'"$1"',$value)??$value);
        return $warnings . (preg_replace('/\$(\w+)/','$1',$result)??$result);
    }

    private function isComplexStructure(string $expr): bool
    {
        if (preg_match('/^\s*(?:\[|\{)/', $expr) === 1) return true;
        if (preg_match('/\b(?:array|object)\b/i', $expr) === 1) return true;
        return str_contains($expr, '{') && str_contains($expr, '}') && str_contains($expr, ':');
    }
}
