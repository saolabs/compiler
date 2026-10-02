<?php

declare(strict_types=1);

namespace Saola\Compiler\Declaration;

use Saola\Compiler\Source\SourceParts;
use Saola\Compiler\Support\Re;

/** Erase optional TypeScript annotations before the shared PHP expression pass. */
final class TypedDeclarations
{
    /** @var array<string, string> */
    public array $types = [];

    /** @var array<string, string> Native JS collection expressions avoid a lossy round trip. */
    public array $nativeComputed = [];

    /** @var array<string, true> Empty JS objects otherwise lose their shape in PHP arrays. */
    public array $emptyObjectDefaults = [];

    public function normalize(SourceParts $parts): SourceParts
    {
        $declarations = [];
        foreach ($parts->declarations as $declaration) {
            if (!Re::match('/^@(vars|props|let|const|states?|computed)\s*\(([\s\S]*)\)$/', $declaration, $m)) {
                $declarations[] = $declaration;
                continue;
            }
            $body = trim($m[2]);
            // Object defaults followed by an object type: @props({...}: {...}).
            if (str_starts_with($body, '{')) {
                $segments = self::split($body, ':', true);
                if (count($segments) === 2) {
                    [$body, $shape] = array_map('trim', $segments);
                    if (!str_starts_with($shape, '{') || !str_ends_with($shape, '}')) {
                        throw new \InvalidArgumentException('@'.$m[1].' requires an object type after object defaults.');
                    }
                    foreach (self::split(substr($shape, 1, -1), ',;', true) as $field) {
                        if (trim($field) === '') continue;
                        if (!Re::match('/^\s*([A-Za-z_]\w*)\??\s*:\s*([\s\S]+)$/', $field, $f)) {
                            throw new \InvalidArgumentException('Invalid declaration type field: '.$field);
                        }
                        $this->types[$f[1]] = trim($f[2]);
                    }
                }
                if (!in_array($m[1], ['vars', 'props', 'state', 'states'], true)) {
                    // @let/@const phá cấu trúc `{host, port} = config`: giữ nguyên văn.
                    $declarations[] = '@'.$m[1].'('.$body.')';
                    continue;
                }
                // Ép về dạng phẳng rồi đi tiếp nhánh dưới: `{a = 1}` (chuẩn TS, trong
                // <script setup>) và `{a: 1}` (gõ nhanh ngoài script) cùng một nghĩa,
                // và dạng phẳng vốn sinh ra output y hệt.
                $flat = [];
                foreach (self::split(substr(trim($body), 1, -1), ',', false) as $field) {
                    $field = trim($field);
                    if ($field === '') continue;
                    if (!Re::match('/^([A-Za-z_]\w*)\s*(?:[:=]\s*([\s\S]+))?$/', $field, $f)) {
                        throw new \InvalidArgumentException('Invalid object default in @'.$m[1].': '.$field);
                    }
                    $flat[] = isset($f[2]) ? $f[1].' = '.trim($f[2]) : $f[1];
                }
                $body = implode(', ', $flat);
            }
            $normalized = [];
            foreach (self::split($body, ',', false) as $part) {
                $part = trim($part);
                if ($part === '') continue;
                if (Re::match('/^(\$?[A-Za-z_]\w*)\s*:\s*([\s\S]+)$/', $part, $typed)) {
                    $assignment = self::split($typed[2], '=', true);
                    $type = trim(array_shift($assignment) ?? '');
                    if ($type === '') throw new \InvalidArgumentException('Empty type for '.$typed[1]);
                    $this->types[ltrim($typed[1], '$')] = $type;
                    $part = $typed[1].($assignment === [] ? '' : ' = '.implode('=', $assignment));
                }
                if (Re::match('/^\$?([A-Za-z_]\w*)\s*=\s*\{\s*\}\s*$/', $part, $empty)) $this->emptyObjectDefaults[$empty[1]] = true;
                $normalized[] = $part;
            }
            if (in_array($m[1], ['vars', 'props', 'state', 'states'], true)) {
                $declarations[] = '@'.$m[1].'('.implode(', ', $normalized).')';
            } else {
                foreach ($normalized as $part) $declarations[] = '@'.$m[1].'('.$part.')';
            }
        }
        return new SourceParts($this->orderComputed($declarations), $parts->blade, $parts->bladeWithSSR,
            $parts->script, $parts->style, $parts->cleanedContent, $parts->wrapperType);
    }

    /** Initialize derived values after inputs, in dependency order on both targets.
     * @param list<string> $declarations
     * @return list<string>
     */
    private function orderComputed(array $declarations): array
    {
        $ordinary = [];
        $computed = [];
        foreach ($declarations as $declaration) {
            if (Re::match('/^@computed\(\s*\$?([A-Za-z_]\w*)\s*=\s*([\s\S]*)\)$/', $declaration, $m)) {
                if (isset($computed[$m[1]])) throw new \InvalidArgumentException('Duplicate computed: '.$m[1]);
                $computed[$m[1]] = [$declaration, $m[2]];
                if (Re::match('/\.\s*(?:filter|map|reduce)\s*\(/', $m[2]) && !Re::match('/\$[A-Za-z_]/', $m[2])) {
                    $this->nativeComputed[$m[1]] = $m[2];
                }
            } elseif (str_starts_with($declaration, '@computed')) {
                throw new \InvalidArgumentException('@computed requires name = expression.');
            } else {
                $ordinary[] = $declaration;
            }
        }
        $visited = [];
        $visiting = [];
        $visit = function (string $name) use (&$visit, &$ordinary, &$visited, &$visiting, $computed): void {
            if (isset($visited[$name])) return;
            if (isset($visiting[$name])) throw new \InvalidArgumentException('Computed dependency cycle: '.$name);
            $visiting[$name] = true;
            $previous = '';
            foreach (token_get_all('<?php '.$computed[$name][1]) as $token) {
                if (is_array($token) && in_array($token[0], [T_STRING, T_VARIABLE], true)) {
                    $dep = ltrim($token[1], '$');
                    if (isset($computed[$dep]) && !in_array($previous, ['.', '->', '?->'], true)) $visit($dep);
                }
                if (!is_array($token) || $token[0] !== T_WHITESPACE) $previous = is_array($token) ? $token[1] : $token;
            }
            unset($visiting[$name]);
            $visited[$name] = true;
            $ordinary[] = $computed[$name][0];
        };
        foreach (array_keys($computed) as $name) $visit($name);
        return $ordinary;
    }

    /**
     * Split declaration/type syntax, respecting strings, nested shapes and generics.
     * Angle brackets only nest in a type, never in a default comparison expression.
     * @return list<string>
     */
    public static function split(string $text, string $separators, bool $inType): array
    {
        $parts = [];
        $start = 0;
        $depth = 0;
        $angles = 0;
        $quote = '';
        $type = $inType;
        $value = false;
        for ($i = 0, $length = strlen($text); $i < $length; $i++) {
            $c = $text[$i];
            if ($quote !== '') {
                if ($c === '\\') $i++;
                elseif ($c === $quote) $quote = '';
                continue;
            }
            if (str_contains('\'"`', $c)) { $quote = $c; continue; }
            if (str_contains('([{', $c)) { $depth++; continue; }
            if (str_contains(')]}', $c)) { $depth--; continue; }
            if ($depth !== 0) continue;
            if ($c === ':' && !$inType && !$value) $type = true;
            if ($type && $c === '<') { $angles++; continue; }
            if ($type && $c === '>' && $angles > 0 && ($text[$i - 1] ?? '') !== '=') { $angles--; continue; }
            if ($angles !== 0) continue;
            if ($c === '=' && ($text[$i + 1] ?? '') !== '>' && ($text[$i + 1] ?? '') !== '='
                && !str_contains('=!<>', $text[$i - 1] ?? ' ')) { $type = false; $value = true; }
            elseif ($c === '=') continue;
            if (str_contains($separators, $c)) {
                $parts[] = substr($text, $start, $i - $start);
                $start = $i + 1;
                $type = $inType;
                $value = false;
            }
        }
        $parts[] = substr($text, $start);
        return $parts;
    }
}
