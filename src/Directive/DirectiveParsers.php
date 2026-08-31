<?php

declare(strict_types=1);

namespace Saola\Compiler\Directive;

use Saola\Compiler\Expr\ExpressionCompiler;
use Saola\Compiler\Support\Balanced;
use Saola\Compiler\Support\Re;

/** Compatibility facade for the active public surface of sao2js/parsers.py. */
final class DirectiveParsers
{
    public function __construct(private readonly ExpressionCompiler $expressions = new ExpressionCompiler())
    {
    }

    /** @return array{?string, ?string, ?string} */
    public function parseExtends(string $code): array
    {
        if (! Re::match('/@extends\s*\(\s*([^)]+)\s*\)/s', $code, $m)) return [null, null, null];
        $content = trim($m[1]);
        $comma = strpos($content, ',');
        $view = trim($comma === false ? $content : substr($content, 0, $comma));
        $data = $comma === false ? null : $this->convertExtendsData(trim(substr($content, $comma + 1)));
        return $this->isSimpleStringLiteral($view)
            ? [substr($view, 1, -1), null, $data]
            : [null, $this->convertExtendsExpression($view), $data];
    }

    public function parseVars(string $code): string
    {
        $code = $this->removeVerbatimBlocks($code);
        if (! Re::match('/@vars\s*\(\s*(.*?)\s*\)/s', $code, $m)) return '';
        $content = $m[1];
        if (str_starts_with(trim($content), '{') && str_ends_with(trim($content), '}')) $content = substr(trim($content), 1, -1);
        $vars = [];
        foreach ($this->splitVars($content) as $part) {
            $part = trim($part);
            $equals = str_contains($part, '=') ? $this->findFirstEquals($part) : -1;
            if ($equals !== -1) $vars[] = ltrim(trim(substr($part, 0, $equals)), '$') . ' = ' . $this->convertPhpToJs(trim(substr($part, $equals + 1)));
            else $vars[] = ltrim($part, '$');
        }
        return 'let {' . implode(', ', $vars) . '} = $$$DATA$$$ || {};';
    }

    public function parseProps(string $code): string
    {
        $code = $this->removeVerbatimBlocks($code);
        if (! Re::match('/@props\s*\(/', $code, $m, PREG_OFFSET_CAPTURE)) return '';
        [$content] = Balanced::extractParensAt($code, $m[0][1] + strlen($m[0][0]) - 1);
        if ($content === null || trim($content) === '') return '';
        $content = trim($content); $vars = [];
        if (str_starts_with($content, '[') && str_ends_with($content, ']')) {
            foreach ($this->splitVars(trim(substr($content, 1, -1))) as $part) {
                $part = trim($part); $arrow = strpos($part, '=>');
                if ($arrow !== false) $vars[] = ltrim(trim(trim(substr($part, 0, $arrow)), "'\""), '$') . ' = ' . $this->convertPhpToJs(trim(substr($part, $arrow + 2)));
                else if (($name = ltrim(trim(trim($part), "'\""), '$')) !== '') $vars[] = $name;
            }
        } else {
            $object = str_starts_with($content, '{') && str_ends_with($content, '}');
            $inner = $object ? substr($content, 1, -1) : $content;
            foreach ($this->splitVars($inner) as $part) {
                $part = trim($part); if ($part === '') continue;
                $separator = $object ? $this->findFirstColon($part) : -1;
                if ($separator === -1 && str_contains($part, '=')) $separator = $this->findFirstEquals($part);
                if ($separator !== -1) $vars[] = ltrim(trim(trim(substr($part, 0, $separator)), "'\""), '$') . ' = ' . $this->convertPhpToJs(trim(substr($part, $separator + 1)));
                else $vars[] = ltrim(trim(trim($part), "'\""), '$');
            }
        }
        return $vars === [] ? '' : 'let {' . implode(', ', $vars) . '} = $$$DATA$$$ || {};';
    }

    public function parseLetDirectives(string $code): string
    {
        $matches = $this->balancedDirectiveContents($this->removeVerbatimBlocks($this->removeScriptTags($code)), 'let');
        $out = [];
        foreach ($matches as $expression) foreach ($this->splitAssignments($expression) as $part) {
            $part = Re::replace('/^\s*\$\s*/', '', trim($part));
            if ($part === '') continue;
            if (str_contains($part, '=') && ((str_contains($part, '[') && str_contains($part, ']')) || (str_contains($part, '{') && str_contains($part, '}')))) {
                $converted = $this->convertDestructuringAssignment($part);
                if ($converted !== null) { $out[] = str_replace('const ', 'let ', $converted); continue; }
            }
            $js = Re::replace('/\$(\w+)/', '${1}', $this->convertPhpExpressionWithArrays($part));
            if (str_contains($js, '=')) {
                [$left, $right] = array_map('trim', explode('=', $js, 2));
                $js = Re::replace('/^\s*\$\s*/', '', $left) . ' = ' . $right;
            }
            if (! str_starts_with($js, 'let ')) $js = 'let ' . $js;
            if (! str_ends_with($js, ';')) $js .= ';';
            $out[] = $js;
        }
        return implode("\n", $out);
    }

    public function parseConstDirectives(string $code): string
    {
        $matches = $this->balancedDirectiveContents($this->removeVerbatimBlocks($this->removeScriptTags($code)), 'const');
        $out = [];
        foreach ($matches as $expression) {
            if (str_contains($expression, '[') && str_contains($expression, ']') && str_contains($expression, '=')) {
                $converted = $this->convertDestructuringAssignment($expression);
                if ($converted !== null) { $out[] = $converted; continue; }
            }
            foreach ($this->splitAssignments($this->convertPhpExpressionWithArrays($expression)) as $assignment) {
                $assignment = trim($assignment); if ($assignment === '') continue;
                if (! str_starts_with($assignment, 'const ')) $assignment = 'const ' . $assignment;
                if (! str_ends_with($assignment, ';')) $assignment .= ';';
                $out[] = $assignment;
            }
        }
        return implode("\n", $out);
    }

    public function parseUseStateDirectives(string $code): string
    {
        $code = $this->removeVerbatimBlocks($this->removeScriptTags($code));
        $out = [];
        foreach (Re::matchAll('/@useState\s*\(\s*(.*?)\s*\)/s', $code) as $m) {
            $parts = $this->splitUseStateParams(trim($m[1]));
            if (count($parts) < 2) continue;
            $value = $this->convertPhpExpressionWithArrays($parts[0]);
            $state = $this->expressions->compileStatement($parts[1]);
            $setter = $this->expressions->compileStatement($parts[2] ?? 'setState');
            $out[] = "const [{$state}, {$setter}] = useState({$value});";
        }
        return implode("\n", $out);
    }

    public function parseStatesDirectives(string $code): string
    {
        $code = $this->removeVerbatimBlocks($this->removeScriptTags($code));
        $out = [];
        foreach ($this->balancedDirectiveContents($code, 'states') as $content) {
            $content = trim($content);
            if (str_starts_with($content, '[') && str_ends_with($content, ']')) {
                foreach ($this->splitVars(trim(substr($content, 1, -1))) as $part) {
                    $arrow = strpos(trim($part), '=>'); if ($arrow === false) continue;
                    $key = ltrim(trim(trim(substr(trim($part), 0, $arrow)), "'\""), '$');
                    $this->appendState($out, $key, $this->convertPhpExpressionWithArrays(trim(substr(trim($part), $arrow + 2))));
                }
            } elseif (str_starts_with($content, '{') && str_ends_with($content, '}')) {
                foreach ($this->splitVars(trim(substr($content, 1, -1))) as $part) {
                    $part = trim($part); if ($part === '') continue;
                    $colon = $this->findFirstColon($part);
                    $key = ltrim(trim(trim($colon !== -1 ? substr($part, 0, $colon) : $part), "'\""), '$');
                    $value = $colon !== -1 ? $this->convertPhpExpressionWithArrays(trim(substr($part, $colon + 1))) : 'null';
                    $this->appendState($out, $key, $value);
                }
            } else {
                foreach ($this->splitVars($content) as $part) {
                    $part = trim($part); $equals = $this->findFirstEquals($part);
                    $key = ltrim(trim($equals !== -1 ? substr($part, 0, $equals) : $part), '$');
                    $value = $equals !== -1 ? $this->convertPhpExpressionWithArrays(trim(substr($part, $equals + 1))) : 'null';
                    $this->appendState($out, $key, $value);
                }
            }
        }
        return implode("\n", $out);
    }

    /** @return array<string, mixed>|null */
    public function parseFetch(string $code): ?array
    {
        if (! Re::match('/@fetch\s*\(/', $code, $m, PREG_OFFSET_CAPTURE)) return null;
        [$content] = Balanced::extractParensAt($code, $m[0][1] + strlen($m[0][0]) - 1);
        if ($content === null || trim($content) === '') return null;
        $content = trim($content);
        if (str_contains($content, ',') && ! str_starts_with($content, '[')) {
            $parts = $this->splitFetchParameters($content);
            $url = $this->fetchUrl($parts[0]);
            $data = isset($parts[1]) && str_starts_with(trim($parts[1]), '[') && str_ends_with(trim($parts[1]), ']') ? $this->parsePhpArrayToJsObject(trim($parts[1])) : [];
            $headers = isset($parts[2]) && str_starts_with(trim($parts[2]), '[') && str_ends_with(trim($parts[2]), ']') ? $this->parsePhpArrayToJsObject(trim($parts[2])) : [];
            return ['url' => $url, 'method' => 'GET', 'data' => $data, 'headers' => $headers];
        }
        if ((str_starts_with($content, "'") && str_ends_with($content, "'")) || (str_starts_with($content, '"') && str_ends_with($content, '"'))) return ['url' => '`' . substr($content, 1, -1) . '`', 'method' => 'GET'];
        if (str_contains($content, '(') && str_contains($content, ')') && ! str_starts_with($content, '[')) return ['url' => '`${' . $this->expressions->compileStatement($content) . '}`', 'method' => 'GET'];
        if (str_starts_with($content, '[') && str_ends_with($content, ']')) {
            $config = $this->parsePhpArrayToJsObject($content);
            $config += ['url' => '', 'method' => 'GET', 'data' => [], 'headers' => []];
            if (is_string($config['url']) && ! str_starts_with($config['url'], '`')) $config['url'] = '`' . $config['url'] . '`';
            return $config;
        }
        return null;
    }

    /** @return array{list<string>, list<string>} */
    public function parseInit(string $code): array
    {
        $functions = []; $css = [];
        if (! Re::matchAll('/@oninit\s*\(/i', $code)) return [$functions, $css];
        foreach (Re::matchAll('/@oninit\s*\(/i', $code, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) as $m) {
            $start = $m[0][1] + strlen($m[0][0]) - 1;
            [$content] = Balanced::extractParensAt($code, $start);
            if ($content === null) continue;
            $content = trim($content);
            foreach (Re::matchAll('/<style[^>]*>(.*?)<\/style>/si', $content) as $style) $css[] = trim($style[1]);
            $script = [];
            if (str_contains(strtolower($content), '<script')) {
                $inside = false;
                foreach (explode("\n", $content) as $line) {
                    $lower = strtolower($line);
                    if (str_contains($lower, '<script')) { $inside = true; $tagEnd = strpos($line, '>', strpos($lower, '<script')); if ($tagEnd !== false) $script[] = substr($line, $tagEnd + 1); }
                    elseif (str_contains($lower, '</script>')) { $inside = false; $end = strpos($lower, '</script>'); if ($end > 0) $script[] = substr($line, 0, $end); }
                    elseif ($inside) $script[] = $line;
                }
            } else $script[] = $content;
            if (($joined = trim(implode("\n", $script))) !== '') $functions[] = $joined;
        }
        return [$functions, $css];
    }

    /** @return array{viewType:string, originalValue:string}|null */
    public function parseViewType(string $code): ?array
    {
        if (! Re::match('/@viewtype\s*\(/i', $code, $m, PREG_OFFSET_CAPTURE)) return null;
        [$content] = Balanced::extractParensAt($code, $m[0][1] + strlen($m[0][0]) - 1);
        if ($content === null || trim($content) === '') return null;
        $value = $this->extractViewTypeParameter(trim($content));
        return $value === '' ? null : ['viewType' => $this->normalizeViewType($value), 'originalValue' => $value];
    }

    public function parseBlockDirectives(string $code): string
    {
        $this->validateBlockDirectives($code);
        return $code;
    }

    public function parseEndBlockDirectives(string $code): string { return $code; }

    public function parseUseBlockDirectives(string $code): string
    {
        return Re::replaceCallback('/@(useBlock|useblock)\s*\(\s*([^,)]+)(?:\s*,\s*([^)]+))?\s*\)/', function (array $m): string {
            $name = Re::replace('/\$(\w+)/', '${1}', $this->convertPhpToJs(trim($m[2])));
            if (($m[3] ?? '') !== '') return '${this.useBlock(' . $name . ', ' . Re::replace('/\$(\w+)/', '${1}', $this->convertPhpToJs(trim($m[3]))) . ')}';
            return '${this.useBlock(' . $name . ')}';
        }, $code);
    }

    public function parseOnBlockDirectives(string $code): string
    {
        return Re::replaceCallback('/@(onBlock|onblock|onBlockChange)\s*\(\s*([^)]+)\s*\)/', function (array $m): string {
            $params = trim($m[2]);
            if (str_starts_with($params, '[') && str_ends_with($params, ']')) {
                $js = $this->convertPhpArrayLikePython($params);
                $js = Re::replace('/":/', '": ', $js);
                $js = Re::replace('/,"/', ', "', $js);
                return '${this.subscribeBlock(' . $js . ')}';
            }
            return '${this.subscribeBlock(' . Re::replace('/\$(\w+)/', '${1}', $this->convertPhpToJs($params)) . ')}';
        }, $code);
    }

    private function removeScriptTags(string $code): string { return Re::replace('/<script[^>]*>.*?<\/script>/si', '', $code); }
    private function removeVerbatimBlocks(string $code): string { return Re::replace('/@verbatim\s*.*?\s*@endverbatim/si', '', $code); }

    /** @return list<string> */
    private function balancedDirectiveContents(string $code, string $name): array
    {
        $out = [];
        foreach (Re::matchAll('/@' . preg_quote($name, '/') . '\s*\(/', $code, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) as $m) {
            [$content] = Balanced::extractParensAt($code, $m[0][1] + strlen($m[0][0]) - 1);
            if ($content !== null && trim($content) !== '') $out[] = trim($content);
        }
        return $out;
    }

    /** @return list<string> */
    private function splitVars(string $content): array { return $this->splitNested($content, true); }
    /** @return list<string> */
    private function splitAssignments(string $content): array { return $this->splitNested($content, true); }
    /** @return list<string> */
    private function splitUseStateParams(string $content): array { return $this->splitNested($content, false); }
    /** @return list<string> */
    private function splitFetchParameters(string $content): array { return $this->splitNested($content, false); }

    /** @return list<string> */
    private function splitNested(string $value, bool $trackBraces): array
    {
        $out = []; $current = ''; $paren = 0; $bracket = 0; $brace = 0; $quote = null;
        for ($i = 0; $i < strlen($value); $i++) {
            $ch = $value[$i];
            if ($quote === null && ($ch === "'" || $ch === '"')) $quote = $ch;
            elseif ($quote !== null && $ch === $quote && ($i === 0 || $value[$i - 1] !== '\\')) $quote = null;
            elseif ($quote === null && $ch === '(') $paren++;
            elseif ($quote === null && $ch === ')') $paren--;
            elseif ($quote === null && $ch === '[') $bracket++;
            elseif ($quote === null && $ch === ']') $bracket--;
            elseif ($quote === null && $trackBraces && $ch === '{') $brace++;
            elseif ($quote === null && $trackBraces && $ch === '}') $brace--;
            elseif ($quote === null && $ch === ',' && $paren === 0 && $bracket === 0 && $brace === 0) { $out[] = trim($current); $current = ''; continue; }
            $current .= $ch;
        }
        if (trim($current) !== '') $out[] = trim($current);
        return $out;
    }

    private function convertPhpExpressionWithArrays(string $value): string
    {
        if (str_contains($value, '[') && str_contains($value, ']')) {
            $value = $this->convertPhpArrayLikePython($value);
        }
        return Re::replace('/\$(\w+)/', '${1}', $this->expressions->compileStatement($value));
    }

    private function convertPhpToJs(string $value): string
    {
        $value = trim($value);
        if ((str_starts_with($value, "'") && str_ends_with($value, "'")) || (str_starts_with($value, '"') && str_ends_with($value, '"'))) return $value;
        if (Re::match('/^-?\d+$/', $value)) return $value;
        if (in_array(strtolower($value), ['true', 'false', 'null'], true)) return strtolower($value);
        if (str_starts_with($value, '[') && str_ends_with($value, ']')) return $this->convertPhpArrayLikePython($value);
        return $value;
    }

    private function convertDestructuringAssignment(string $value): ?string
    {
        $equals = strpos($value, '='); if ($equals === false) return null;
        $left = trim(substr($value, 0, $equals)); $right = trim(substr($value, $equals + 1));
        if ((str_starts_with($left, '[') && str_ends_with($left, ']')) || (str_starts_with($left, '{') && str_ends_with($left, '}'))) {
            $open = $left[0]; $close = substr($left, -1);
            $vars = array_map(static fn (string $var): string => ltrim(trim($var), '$'), explode(',', substr($left, 1, -1)));
            $left = $open . implode(', ', $vars) . $close;
        }
        return 'const ' . $left . ' = ' . $this->convertPhpExpressionWithArrays($right) . ';';
    }

    /** @param list<string> $out */
    private function appendState(array &$out, string $key, string $value): void
    {
        if ($key !== '') $out[] = 'const [' . $key . ', set' . ucfirst($key) . '] = useState(' . $value . ');';
    }

    /** @return array<string, mixed> */
    private function parsePhpArrayToJsObject(string $value): array
    {
        $json = Re::replace('/(?<!")\$(\w+)(?!")/', '${1}', $value);
        $json = str_replace(['[', ']'], ['{', '}'], $json);
        $json = Re::replace('/\s*=>\s*/', ': ', $json);
        $json = Re::replace('/\s+\.\s+/', ' + ', $json);
        $json = Re::replace("/'([^']*)'/", '"${1}"', $json);
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function fetchUrl(string $value): string
    {
        $value = trim($value);
        if ((str_starts_with($value, "'") && str_ends_with($value, "'")) || (str_starts_with($value, '"') && str_ends_with($value, '"'))) return '`' . substr($value, 1, -1) . '`';
        if (str_contains($value, '(') && str_contains($value, ')')) return '`${' . $this->expressions->compileStatement($value) . '}`';
        return '`${' . $value . '}`';
    }

    private function convertExtendsData(string $value): string
    {
        $warnings = '';
        foreach (Re::matchAll('/\$([a-zA-Z_]\w*)/', $value) as $m) {
            $warnings .= 'Warning: Undefined variable ' . $m[1] . " in Command line code on line 1\n";
        }
        if ($warnings !== '') {
            $withoutVars = Re::replace('/\$([a-zA-Z_]\w*)/', 'null', $value);
            $converted = $this->convertPhpArrayLikePython($withoutVars);
            return $warnings . $converted;
        }
        $value = $this->convertPhpArrayLikePython($value);
        return Re::replace('/\s+\.\s+/', ' + ', $value);
    }

    /** Mimic convert_php_array_with_php_r, then its quote-only fallback. */
    private function convertPhpArrayLikePython(string $value): string
    {
        $compiled = $this->expressions->compile($value);
        $decoded = json_decode($compiled, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        return Re::replace("/'([^']*)'/", '"${1}"', $value);
    }

    private function isSimpleStringLiteral(string $value): bool
    {
        $value = trim($value);
        return ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) && ! str_contains($value, '$') && ! str_contains($value, '(');
    }

    private function convertExtendsExpression(string $value): string
    {
        $value = trim($value);
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) return '`' . $this->convertPhpStringConcat(substr($value, 1, -1)) . '`';
        if (str_contains($value, '(') && str_contains($value, ')')) return $this->expressions->compileStatement($value);
        if (str_contains($value, '.') && (str_contains($value, '$') || str_contains($value, '"') || str_contains($value, "'"))) return '`' . $this->convertPhpStringConcat($value) . '`';
        return $this->expressions->compileStatement($value);
    }

    private function convertPhpStringConcat(string $value): string
    {
        $pattern = '/(\$[a-zA-Z_][a-zA-Z0-9_]*|"[^"]*"|\'[^\']*\'|[a-zA-Z_][a-zA-Z0-9_]*\([^)]*\))/';
        $parts = Re::matchAll($pattern, $value);
        $values = $parts === [] ? explode('.', $value) : array_map(static fn (array $m): string => $m[0], $parts);
        $out = [];
        foreach ($values as $part) {
            $part = trim($part);
            if (str_starts_with($part, '$')) $out[] = substr($part, 1);
            elseif (str_contains($part, '(') && str_contains($part, ')')) $out[] = $this->expressions->compileStatement($part);
            else $out[] = $part;
        }
        return '${' . implode('+', $out) . '}';
    }

    private function findFirstEquals(string $value): int { return $this->findTopLevel($value, '=', false); }
    private function findFirstColon(string $value): int { return $this->findTopLevel($value, ':', true); }

    private function findTopLevel(string $value, string $needle, bool $braces): int
    {
        $paren = 0; $bracket = 0; $brace = 0; $quote = null;
        for ($i = 0; $i < strlen($value); $i++) {
            $ch = $value[$i];
            if ($quote === null && ($ch === "'" || $ch === '"')) $quote = $ch;
            elseif ($quote !== null && $ch === $quote) $quote = null;
            elseif ($quote === null && $ch === '(') $paren++;
            elseif ($quote === null && $ch === ')') $paren--;
            elseif ($quote === null && $ch === '[') $bracket++;
            elseif ($quote === null && $ch === ']') $bracket--;
            elseif ($quote === null && $braces && $ch === '{') $brace++;
            elseif ($quote === null && $braces && $ch === '}') $brace--;
            elseif ($quote === null && $ch === $needle && $paren === 0 && $bracket === 0 && $brace === 0) return $i;
        }
        return -1;
    }

    private function extractViewTypeParameter(string $value): string
    {
        if ((str_starts_with($value, "'") && str_ends_with($value, "'")) || (str_starts_with($value, '"') && str_ends_with($value, '"'))) return substr($value, 1, -1);
        if (str_starts_with($value, '$') || (str_contains($value, '(') && str_contains($value, ')'))) return $this->expressions->compileStatement($value);
        return in_array(strtolower($value), ['true', 'false', 'null'], true) ? strtolower($value) : $value;
    }

    private function normalizeViewType(string $value): string
    {
        $value = strtolower(trim($value));
        if (in_array($value, ['html', 'document', 'html-document', 'htmldocument', 'fullpage', 'finalhtml', 'webpage'], true)) return 'html-document';
        if (in_array($value, ['layout', 'view-layout', 'view/layout'], true)) return 'layout';
        if (in_array($value, ['template', 'view-template', 'temp', 'tpl', 'viewtpl', 'view/template'], true)) return 'template';
        if (in_array($value, ['component', 'compunent'], true)) return 'component';
        return 'view';
    }

    private function validateBlockDirectives(string $code): void
    {
        $stack = [];
        foreach (explode("\n", $this->removeVerbatimBlocks($code)) as $index => $line) {
            if (Re::match('/@block\s*\(/i', $line)) {
                $name = Re::match('/@block\s*\(\s*[\'"]([^\'"]*)[\'"]/i', $line, $m) ? $m[1] : 'block_' . count($stack);
                $stack[] = [$name, $index + 1];
            }
            if (Re::match('/@endblock\b/i', $line)) {
                if ($stack === []) throw new \ValueError('Lỗi tại dòng ' . ($index + 1) . ': Tìm thấy @endblock/@endBlock nhưng không có @block tương ứng');
                array_pop($stack);
            }
        }
        if ($stack !== []) {
            $items = array_map(static fn (array $item): string => "'{$item[0]}' (dòng {$item[1]})", $stack);
            throw new \ValueError('Lỗi: Có ' . count($stack) . ' block chưa được đóng: ' . implode(', ', $items));
        }
    }
}
