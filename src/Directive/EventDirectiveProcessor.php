<?php

declare(strict_types=1);

namespace Saola\Compiler\Directive;

use Saola\Compiler\Expr\ExpressionCompiler;
use Saola\Compiler\Support\Re;

/** Event DSL used by the AST parser; port of process_event_items and dependencies. */
final class EventDirectiveProcessor
{
    /** @var array<string, true> */
    private array $stateVariables = [];

    /** @param iterable<string> $stateVariables */
    public function __construct(
        iterable $stateVariables = [],
        private readonly ExpressionCompiler $expressions = new ExpressionCompiler(),
    ) {
        foreach ($stateVariables as $name) {
            $this->stateVariables[$name] = true;
        }
    }

    /** @return list<string> */
    public function processEventItems(string $expression): array
    {
        $items = [];
        foreach ($this->splitByComma(trim($expression)) as $part) {
            $part = trim($part);
            if ($part === '') continue;

            if (Re::match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*\((.*)\)$/s', $part, $m)) {
                if ($this->isUseStateFunctionName($m[1])) {
                    $items[] = $this->processExpressionToArrow($part);
                } else {
                    $params = $this->parseHandlerParameters(trim($m[2]));
                    $items[] = '{"handler":"' . $m[1] . '","params":[' . implode(',', $params) . ']}';
                }
                continue;
            }

            if (Re::match('/^\$([a-zA-Z_][a-zA-Z0-9_]*)\s*\((.*)\)$/s', $part, $m)) {
                if ($this->isUseStateFunctionName($m[1])) {
                    $items[] = $this->processExpressionToArrow($part);
                } else {
                    $params = $this->parseHandlerParameters(trim($m[2]));
                    $items[] = '{"handler":"' . $m[1] . '","params":[' . implode(',', $params) . ']}';
                }
                continue;
            }

            if (Re::match('/^([a-zA-Z_][a-zA-Z0-9_]*)$/', $part, $m) && ! $this->isUseStateFunctionName($m[1])) {
                $items[] = '{"handler":"' . $m[1] . '","params":[]}';
                continue;
            }

            foreach ($this->splitExpressionsBySemicolon($part) as $expr) {
                $items[] = $this->processExpressionToArrow($expr);
            }
        }

        return $items;
    }

    public function processEventDirective(string $eventType, string $expression): string
    {
        $items = $this->processEventItems($expression);
        if ($items === []) return '';
        return '${this.__addEventConfig("' . $eventType . '", [' . implode(',', $items) . '])}';
    }

    private function isUseStateFunctionName(string $name): bool
    {
        if (isset($this->stateVariables[$name])) return true;
        if (str_starts_with($name, 'set') && strlen($name) > 3) {
            $stateName = strtolower($name[3]) . substr($name, 4);
            return isset($this->stateVariables[$stateName]);
        }
        return false;
    }

    /** @return list<string> */
    private function parseHandlerParameters(string $params): array
    {
        if (trim($params) === '') return [];
        $out = [];
        foreach ($this->splitByComma($params) as $param) {
            $param = trim($param);
            if ($this->isFunctionCallInParam($param)) {
                if (Re::match('/@(?:event|Event|EVENT)\b|\$(?:event|Event|EVENT)\b/', $param)) {
                    $out[] = '"@EVENT"';
                    continue;
                }
                $nested = $this->convertPhpVariableToJs($param);
                $nested = $this->processEventInString($nested);
                $nested = $this->convertPhpArrayToJsObject($nested);
                $nested = Re::replace('/(?<!")@EVENT(?!")/', '"@EVENT"', $nested);
                $out[] = $this->arrowPrefix($nested) . $this->arrowBody($nested);
                continue;
            }
            $out[] = $this->processParameter($param, true);
        }
        return $out;
    }

    private function isFunctionCallInParam(string $param): bool
    {
        return Re::match('/^\$?[a-zA-Z_][a-zA-Z0-9_]*\s*\(/s', trim($param));
    }

    /** @return array{handler:string, params:list<string>}|null */
    private function parseFunctionCallInParam(string $param): ?array
    {
        if (! Re::match('/^(\$?[a-zA-Z_][a-zA-Z0-9_]*)\s*\((.*)\)$/s', trim($param), $m)) return null;
        $name = ltrim($m[1], '$');
        if (str_starts_with($m[1], '$') && isset($this->stateVariables[$name])) $name = 'set' . ucfirst($name);
        return ['handler' => $name, 'params' => $this->splitByComma(trim($m[2]))];
    }

    private function processParameter(string $param, bool $paramsContext = false): string
    {
        if (str_starts_with(trim($param), '{"handler"')) return $param;
        if (Re::match('/\$(?:event|Event|EVENT)\s*\./i', $param)) {
            $result = Re::replace('/\$(?:event|Event|EVENT)(?![a-zA-Z])/i', 'event', $param);
            $result = $this->convertPhpArrayToJsObject($this->convertPhpVariableToJs($result));
            return '(event) => ' . $this->arrowBody($result);
        }

        $param = Re::replace('/\$(?:event|Event|EVENT)(?![a-zA-Z])/i', '@EVENT', $param);
        $param = Re::replace('/@(?:event|Event|EVENT)(?![a-zA-Z])/i', '@EVENT', $param);
        if (trim($param) === '@EVENT') return '"@EVENT"';

        $param = $this->processEventInString($this->convertPhpVariableToJs($param));
        foreach (['@attr' => '#ATTR', '@prop' => '#PROP', '@val' => '#VALUE', '@value' => '#VALUE'] as $directive => $prefix) {
            $param = $this->processAttrPropInString($param, $directive, $prefix);
        }
        $param = $this->convertPhpArrayToJsObject($param);

        if (str_contains($param, '=>')) return Re::replace('/(?<!")@(?:EVENT|event|Event)(?!")/i', '"@EVENT"', $param);
        $param = Re::replace('/(?<!")@(?:EVENT|event|Event)(?!")/i', '"@EVENT"', $param);
        if ($paramsContext) {
            $quoted = (str_starts_with($param, '"') && str_ends_with($param, '"')) || (str_starts_with($param, "'") && str_ends_with($param, "'"));
            $directive = str_starts_with($param, '"#') || str_starts_with($param, "'#");
            if (! $quoted && ! $directive && $this->isValidJsIdentifier($param)) return $this->arrowPrefix($param) . $this->arrowBody($param);
        }
        if ($this->looksLikeExpression($param)) return '(event) => ' . $this->arrowBody($param);

        // Tham chiếu event mà KHÔNG bọc closure ⇒ chạy lúc render, `event` chưa có
        if ($this->referencesEvent($param)) return '(event) => ' . $this->arrowBody($param);

        return $param;
    }

    private function processExpressionToArrow(string $expr): string
    {
        $original = trim($expr);
        $js = $this->processEventInString($this->convertPhpVariableToJs($original));
        foreach (['@attr' => '#ATTR', '@prop' => '#PROP', '@val' => '#VALUE', '@value' => '#VALUE'] as $directive => $prefix) {
            $js = $this->processAttrPropInString($js, $directive, $prefix);
        }
        $js = $this->convertPhpArrayToJsObject($js);

        if (Re::match('/^\s*(?:\(\s*(?:[a-zA-Z_]\w*\s*(?:,\s*[a-zA-Z_]\w*\s*)*)?\)|[a-zA-Z_]\w*)\s*=>/', $original)) {
            $js = $this->expressions->helpers()->resolveUserMethodCalls($js);
            return Re::replace('/(?<!")@(?:EVENT|event|Event)(?!")/', '"@EVENT"', $js);
        }

        if (Re::match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*\((.*)\)$/s', $original, $m) && isset($this->stateVariables[$m[1]])) {
            return '(event) => ' . $this->setterName($m[1]) . '(' . $this->processSetterParams($m[2]) . ')';
        }
        if (Re::match('/^\$([a-zA-Z_][a-zA-Z0-9_]*)\s*\((.*)\)$/s', $original, $m)) {
            if ($this->isUseStateFunctionName($m[1])) return '(event) => ' . $this->setterName($m[1]) . '(' . $this->processSetterParams($m[2]) . ')';
            return '(event) => ' . $this->arrowBody($js);
        }
        if (Re::match('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', $original, $m) && isset($this->stateVariables[$m[1]])) {
            $setter = $this->setterName($m[1]);
            if (str_contains($js, '++')) return '(event) => ' . $setter . '(' . str_replace('++', '', $js) . ' + 1)';
            if (str_contains($js, '+=')) {
                [$var, $value] = array_map('trim', explode('+=', $js, 2));
                return '(event) => ' . $setter . "({$var} + {$value})";
            }
            if (str_contains($js, '=') && ! str_starts_with($js, '=')) {
                [$var, $value] = array_map('trim', explode('=', $js, 2));
                return '(event) => ' . $this->setterName($var) . "({$value})";
            }
            return '(event) => ' . $setter . '(' . $js . ')';
        }
        return $this->arrowPrefix($js) . $this->arrowBody($js);
    }

    private function processSetterParams(string $params): string
    {
        $out = [];
        foreach ($this->splitByComma(trim($params)) as $param) {
            $param = trim($param);
            if ($this->isFunctionCallInParam($param) && ($handler = $this->parseFunctionCallInParam($param)) !== null) {
                $processed = [];
                foreach ($handler['params'] as $value) $processed[] = $this->processParameter(trim($value), true);
                $out[] = '{"handler":"' . $handler['handler'] . '","params":[' . implode(',', $processed) . ']}';
                continue;
            }
            $value = $this->processEventInString($this->convertPhpVariableToJs($param));
            foreach (['@attr' => '#ATTR', '@prop' => '#PROP', '@val' => '#VALUE', '@value' => '#VALUE'] as $directive => $prefix) $value = $this->processAttrPropInString($value, $directive, $prefix);
            $value = $this->convertPhpArrayToJsObject($value);
            $value = Re::replace('/(?<!")@EVENT(?!")/', '"@EVENT"', $value);
            $out[] = str_replace('"@EVENT"', 'event', $value);
        }
        return implode(', ', $out);
    }

    private function setterName(string $name): string
    {
        return str_starts_with($name, 'set') && strlen($name) > 3 ? $name : 'set' . ucfirst($name);
    }

    private function convertPhpVariableToJs(string $param): string
    {
        return Re::replace('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', '${1}', $param);
    }

    private function processEventInString(string $param): string
    {
        if (str_contains($param, '=>') || Re::match('/\(\s*event\s*\)/i', $param)) return $param;
        $param = Re::replace('/@(?:Event|EVENT|event)(?![a-zA-Z])/i', '@EVENT', $param);
        return Re::replace('/\$(?:Event|EVENT|event)(?![a-zA-Z])/i', '@EVENT', $param);
    }

    private function processAttrPropInString(string $param, string $directive, string $prefix): string
    {
        return Re::replaceCallback('/' . preg_quote($directive, '/') . '\s*\(\s*([^)]*)\s*\)/i', static function (array $m) use ($prefix): string {
            $value = trim($m[1]);
            return $value === '' ? '"' . $prefix . '"' : '"' . $prefix . ':' . trim($value, "\"'") . '"';
        }, $param);
    }

    private function convertPhpArrayToJsObject(string $param): string
    {
        $trimmed = trim($param);
        if ($this->isPhpArrayLiteral($trimmed)) return $this->expressions->compile($trimmed);
        $param = str_replace(['->', '::'], '.', $param);
        return $this->renameLoopIdentifier($param);
    }

    private function isPhpArrayLiteral(string $expr): bool
    {
        if (strlen($expr) < 2 || $expr[0] !== '[' || ! str_ends_with($expr, ']')) return false;
        $depth = 0; $quote = null;
        for ($i = 1, $end = strlen($expr) - 1; $i < $end; $i++) {
            $ch = $expr[$i];
            if ($quote !== null) {
                if ($ch === '\\') { $i++; continue; }
                if ($ch === $quote) $quote = null;
            } elseif ($ch === "'" || $ch === '"') $quote = $ch;
            elseif (str_contains('([{', $ch)) $depth++;
            elseif (str_contains(')]}', $ch) && --$depth < 0) return false;
            elseif ($ch === '=' && $depth === 0 && ($expr[$i + 1] ?? '') === '>') return true;
        }
        return false;
    }

    private function renameLoopIdentifier(string $expr): string
    {
        $out = ''; $start = 0; $quote = null;
        for ($i = 0; $i < strlen($expr); $i++) {
            $ch = $expr[$i];
            if ($quote === null && ($ch === "'" || $ch === '"' || $ch === '`')) {
                $out .= Re::replace('/(?<![\w.$])loop(?![\w$])/', '__loop', substr($expr, $start, $i - $start));
                $quote = $ch; $start = $i;
            } elseif ($quote !== null && $ch === '\\') $i++;
            elseif ($quote !== null && $ch === $quote) {
                $out .= substr($expr, $start, $i - $start + 1); $quote = null; $start = $i + 1;
            }
        }
        return $out . ($quote === null ? Re::replace('/(?<![\w.$])loop(?![\w$])/', '__loop', substr($expr, $start)) : substr($expr, $start));
    }

    /**
     * Biểu thức có tham chiếu tới `event` không?
     *
     * Runtime gọi `params.map(p => typeof p === 'function' ? p(event) : p)`
     * (ViewController.ts) — tức là NÓ TRUYỀN event vào. Nhưng compiler lại sinh
     * `() => Number(event.target.value)`: closure bỏ qua tham số, `event` bên
     * trong thành biến TỰ DO ⇒ ReferenceError lúc bấm.
     *
     * Tệ hơn, `doThing(event.target.value)` không được bọc closure nào cả:
     * `looksLikeExpression()` không coi truy cập thuộc tính là biểu thức, nên
     * nó bị nhúng thẳng và chạy LÚC RENDER, khi `event` chưa hề tồn tại.
     *
     * Các nhánh sentinel cũ chỉ nhận `$event`/`@event` — cú pháp PHP cũ. Với
     * Saola Syntax hiện đại (`event`, dạng 5/5 view thật đang dùng) không nhánh
     * nào khớp. Đây là luật thống nhất cho cả bốn chỗ sinh param.
     *
     * Ranh giới từ, không phải substring: `preventDefault` CÓ chứa "event"
     * (p-r-"event"-... ) nhưng không hề tham chiếu biến event.
     */
    private function referencesEvent(string $js): bool
    {
        return Re::match('/(?<![a-zA-Z0-9_$])(?:event|@EVENT)(?![a-zA-Z0-9_])/i', $js);
    }

    private function arrowPrefix(string $js): string
    {
        return $this->referencesEvent($js) ? '(event) => ' : '() => ';
    }

    private function looksLikeExpression(string $value): bool
    {
        $value = trim($value);
        if (str_contains($value, '=>') || $value === '@EVENT' || $value === '"@EVENT"') return false;
        if (Re::match('/^-?\d+$/', $value) || in_array(strtolower($value), ['true', 'false', 'null'], true) || $this->isValidJsIdentifier($value)) return false;
        return (str_contains($value, '(') && str_contains($value, ')')) || strpbrk($value, '+-*/%><=!&|?:') !== false || str_contains($value, ' ');
    }

    private function isValidJsIdentifier(string $value): bool
    {
        return Re::match('/^[a-zA-Z_$][a-zA-Z0-9_$]*$/', $value);
    }

    private function arrowBody(string $body): string
    {
        return str_starts_with(ltrim($body), '{') ? '(' . $body . ')' : $body;
    }

    /** @return list<string> */
    private function splitExpressionsBySemicolon(string $expr): array
    {
        return $this->splitTopLevel($expr, ';');
    }

    /** @return list<string> */
    public function splitByComma(string $expression): array
    {
        return $this->splitTopLevel($expression, ',');
    }

    /** @return list<string> */
    private function splitTopLevel(string $value, string $delimiter): array
    {
        if (trim($value) === '') return [];
        if (! str_contains($value, $delimiter)) return [$value];
        $out = []; $current = ''; $paren = 0; $bracket = 0; $quote = null;
        for ($i = 0; $i < strlen($value); $i++) {
            $ch = $value[$i];
            if ($quote === null && ($ch === "'" || $ch === '"')) $quote = $ch;
            elseif ($quote !== null && $ch === $quote && ($i === 0 || $value[$i - 1] !== '\\')) $quote = null;
            elseif ($quote === null && $ch === '(') $paren++;
            elseif ($quote === null && $ch === ')') $paren--;
            elseif ($quote === null && $ch === '[') $bracket++;
            elseif ($quote === null && $ch === ']') $bracket--;
            elseif ($quote === null && $ch === $delimiter && $paren === 0 && $bracket === 0) {
                if (trim($current) !== '') $out[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        if (trim($current) !== '') $out[] = trim($current);
        return $out !== [] ? $out : [$value];
    }
}
