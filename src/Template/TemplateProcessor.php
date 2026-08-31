<?php

declare(strict_types=1);

namespace Saola\Compiler\Template;

use Saola\Compiler\Directive\ClassBindingHandler;
use Saola\Compiler\Directive\ConditionalHandlers;
use Saola\Compiler\Directive\DirectiveProcessor;
use Saola\Compiler\Directive\EventDirectiveProcessor;
use Saola\Compiler\Directive\LoopHandlers;
use Saola\Compiler\Directive\SectionHandlers;
use Saola\Compiler\Expr\ExpressionCompiler;
use Saola\Compiler\Support\Balanced;

/** Orchestrator port of sao2js/template_processor.py. */
final class TemplateProcessor
{
    /** EchoProcessor mirrors the Python object's public legacy counter. */
    public int $reactiveCounter = 0;

    /** @var array<string, true> */
    private array $stateVariables = [];

    private ReactiveScopeManager $scopes;
    private ConditionalHandlers $conditionals;
    private LoopHandlers $loops;
    private SectionHandlers $sections;
    private TemplateProcessors $templates;
    private DirectiveProcessor $directives;
    private EventDirectiveProcessor $events;
    private EchoProcessor $echoes;
    private ClassBindingHandler $classes;

    /** @param iterable<string> $stateVariables */
    public function __construct(iterable $stateVariables = [], private readonly bool $isTypescript = false, private readonly ExpressionCompiler $expressions = new ExpressionCompiler())
    {
        foreach ($stateVariables as $name) $this->stateVariables[(string) $name] = true;
        $this->scopes = new ReactiveScopeManager();
        $this->conditionals = new ConditionalHandlers(array_keys($this->stateVariables), $this->scopes, $isTypescript, $expressions);
        $this->loops = new LoopHandlers(array_keys($this->stateVariables), $this->scopes, $isTypescript, $expressions);
        $this->sections = new SectionHandlers($expressions);
        $this->templates = new TemplateProcessors($expressions, new DirectiveProcessor($expressions));
        $this->directives = new DirectiveProcessor($expressions);
        $this->events = new EventDirectiveProcessor(array_keys($this->stateVariables), $expressions);
        $this->echoes = new EchoProcessor(array_keys($this->stateVariables), $isTypescript, $this, $expressions);
        $this->classes = new ClassBindingHandler(array_keys($this->stateVariables), $expressions);
    }

    /** @return array{0: string, 1: list<mixed>} */
    public function processTemplate(string $bladeCode): array
    {
        $this->scopes->reset();
        $this->reactiveCounter = 0;
        $bladeCode = $this->removePageDirectives($bladeCode);
        $bladeCode = preg_replace('/@extends\s*\([^)]*\)/s', '', $bladeCode) ?? $bladeCode;
        $bladeCode = preg_replace('/@vars\s*\([^)]*\)/s', '', $bladeCode) ?? $bladeCode;
        foreach (['props', 'useState', 'states', 'fetch'] as $directive) $bladeCode = $this->removeBalancedDirective($bladeCode, $directive);
        $bladeCode = preg_replace('/@await\s*\([^)]*\)/s', '', $bladeCode) ?? $bladeCode;
        $bladeCode = $this->resolveImportIncludes($bladeCode);
        $bladeCode = ChildrenSlot::replaceForLegacyJs($bladeCode);
        $bladeCode = $this->processMultilineIncludes($bladeCode);
        $bladeCode = preg_replace('/@oninit.*?@endoninit/is', '', $bladeCode) ?? $bladeCode;

        $preprocessed = [];
        foreach ($this->splitLines($bladeCode) as $line) {
            $preprocessed[] = $this->isAttributeDirective($line) ? $this->processInlineDirective($line) : $line;
        }
        $bladeCode = implode("\n", $preprocessed);
        $bladeCode = $this->echoes->processEchoExpressions($bladeCode);

        $lines = $this->splitLines($bladeCode);
        $output = []; $sections = []; $stack = [];
        $skipUntil = null; $removeMarkers = false; $inPre = false;

        for ($i = 0, $count = count($lines); $i < $count;) {
            $original = $lines[$i];
            if (str_contains($original, '<pre>') || str_contains($original, '<pre ')) $inPre = true;
            if (str_contains($original, '</pre>')) $inPre = false;
            $line = $inPre ? rtrim($original) : trim($original);
            if ($line === '') {
                if ($this->enclosingLoopType($stack) === null) $output[] = '';
                $i++; continue;
            }

            if ($skipUntil !== null) {
                if ($skipUntil === '@endserverside' && $this->startsWithAny($line, ['@endserverside','@endServerSide','@endSSR','@endSsr','@EndSSR','@EndSsr','@endssr'])) {
                    $skipUntil = null; $removeMarkers = false; $i++; continue;
                }
                if ($skipUntil === '@endclientside') {
                    if ($this->startsWithAny($line, ['@endclientside','@endClientSide','@endcsr','@endCSR','@endCsr','@endusecsr','@endUseCSR','@endUseCsr'])) {
                        $skipUntil = null; $removeMarkers = false; $i++; continue;
                    }
                    if ($removeMarkers) $output[] = $this->templates->processTemplateLine($line);
                } elseif (str_starts_with($line, $skipUntil)) {
                    $skipUntil = null; $removeMarkers = false;
                }
                $i++; continue;
            }

            if ($this->isIncompleteViewDirective($line)) {
                [$joined, $used] = $this->joinMultilineDirective($lines, $i);
                $processed = $this->templates->processTemplateLine($joined);
                if ($processed !== '') $output[] = $processed;
                $i += $used; continue;
            }
            if ($this->isIncompleteEventDirective($line)) {
                [$joined, $used] = $this->joinMultilineDirective($lines, $i);
                $processed = $this->processLineDirectives($joined, $stack, $output, $sections);
                if (is_string($processed) && $processed !== '') $output[] = $processed;
                $i += $used; continue;
            }

            $processed = $this->processLineDirectives($line, $stack, $output, $sections);
            if ($processed !== false && $processed !== null) {
                if ($processed === 'skip_until_@endserverside') $skipUntil = '@endserverside';
                elseif ($processed === 'remove_directive_markers_until_@endclientside') { $skipUntil = '@endclientside'; $removeMarkers = true; }
                elseif ($processed !== true) $this->appendProcessed((string) $processed, $stack, $output);
                $i++; continue;
            }

            if ($stack !== [] && ($stack[array_key_last($stack)][0] ?? null) === 'php') {
                $processedLine = '    ' . $this->expressions->compileStatement($line);
            } else {
                $processedLine = $this->templates->processTemplateLine($line);
                $loop = $this->enclosingLoopType($stack);
                if ($loop !== null) {
                    if (trim($processedLine) === '') { $i++; continue; }
                    if (str_starts_with($line, '@php')) {
                        $php = []; $j = $i + 1;
                        while ($j < $count && ! str_starts_with(trim($lines[$j]), '@endphp')) { if (trim($lines[$j]) !== '') $php[] = trim($lines[$j]); $j++; }
                        if ($j < $count) { $processedLine = $php === [] ? '' : '${App.Helper.execute(() => {' . "\n    " . implode("\n", array_map(fn (string $value): string => $this->expressions->compileStatement($value), $php)) . "\n})}"; $i = $j; }
                    }
                    $this->appendLoopContent($this->resolveAllPlaceholders($processedLine), $loop, $output);
                    $i++; continue;
                }
            }

            $output[] = $this->resolveAllPlaceholders($processedLine);
            $i++;
        }

        $template = implode("\n", array_values(array_filter($output, 'is_string')));
        return [$this->classes->processClassDirective($template), $sections];
    }

    /** @param list<array<int, mixed>> $stack @param list<string> $output @param list<mixed> $sections */
    private function processLineDirectives(string $line, array &$stack, array &$output, array &$sections): string|bool|null
    {
        if (str_contains($line, '@class')) $line = $this->classes->processClassDirective($line);
        $event = $this->processEventDirectives($line);
        if ($event !== null) return $this->templates->processTemplateLine($event);
        if (($value = $this->templates->processServersideDirective($line)) !== false) return $value;
        if (($value = $this->templates->processClientsideDirective($line)) !== false) return $value;
        if (str_starts_with($line, '@let') && $this->directives->processLetDirective($line, $stack, $output)) return true;
        if (str_starts_with($line, '@const') && $this->directives->processConstDirective($line, $stack, $output)) return true;
        foreach ([
            'processAuthDirective','processEndauthDirective','processCanDirective','processEndcanDirective',
            'processCsrfDirective','processMethodDirective','processErrorDirective','processEnderrorDirective',
            'processHassectionDirective','processEndhassectionDirective',
        ] as $method) if (($value = $this->directives->{$method}($line)) !== null) return $value;
        if ($this->directives->processEmptyDirective($line, $stack, $output)) return true;
        if ($this->directives->processIssetDirective($line, $stack, $output)) return true;
        foreach (['processUnlessDirective','processEndunlessDirective'] as $method) if (($value = $this->directives->{$method}($line)) !== null) return $value;
        if (str_starts_with($line, '@endempty')) return $this->directives->processEndemptyDirective($stack, $output);
        if (str_starts_with($line, '@endisset')) return $this->directives->processEndissetDirective($stack, $output);
        if (str_starts_with($line, '@php') && $this->enclosingLoopType($stack) === null) return $this->directives->processPhpDirective($line, $stack, $output);
        if (str_starts_with($line, '@endphp') && $this->enclosingLoopType($stack) === null) return $this->directives->processEndphpDirective($stack, $output);
        foreach (['processJsonDirective','processLangDirective','processChoiceDirective','processExecDirective','processOutDirective'] as $method) if (($value = $this->directives->{$method}($line)) !== null) return $value;
        if (str_starts_with($line, '@section')) return $this->sections->processSectionDirective($line, $stack, $output, $sections);
        if (str_starts_with($line, '@endsection')) return $this->sections->processEndsectionDirective($stack, $output, $sections);
        if (str_starts_with($line, '@block')) return $this->sections->processBlockDirective($line, $stack, $output, $sections);
        if (str_starts_with($line, '@endblock') || str_starts_with($line, '@endBlock')) return $this->sections->processEndblockDirective($stack, $output, $sections);
        if (str_starts_with($line, '@if')) return $this->conditionals->processIfDirective($line, $stack, $output, $this->isAttributeDirective($line));
        if (str_starts_with($line, '@elseif')) return $this->conditionals->processElseifDirective($line, $stack, $output);
        if (str_starts_with($line, '@else')) return $this->conditionals->processElseDirective($line, $stack, $output);
        if (str_starts_with($line, '@endif')) return $this->conditionals->processEndifDirective($stack, $output);
        if (str_starts_with($line, '@foreach')) return $this->loops->processForeachDirective($line, $stack, $output, $this->isAttributeDirective($line));
        if (str_starts_with($line, '@key')) { if (preg_match('/@key\s*\((.*?)\)/s', $line, $m) === 1) $this->scopes->setCurrentLoopVariable(trim($m[1])); return true; }
        if (str_starts_with($line, '@endforeach')) return $this->loops->processEndforeachDirective($stack, $output);
        if (str_starts_with($line, '@for')) return $this->loops->processForDirective($line, $stack, $output, $this->isAttributeDirective($line));
        if (str_starts_with($line, '@endfor')) return $this->loops->processEndforDirective($stack, $output);
        if (str_starts_with($line, '@while')) return $this->loops->processWhileDirective($line, $stack, $output, $this->isAttributeDirective($line));
        if (str_starts_with($line, '@endwhile')) return $this->loops->processEndwhileDirective($stack, $output);
        if (str_starts_with($line, '@switch')) return $this->conditionals->processSwitchDirective($line, $stack, $output, $this->isAttributeDirective($line));
        if (str_starts_with($line, '@case')) return $this->conditionals->processCaseDirective($line, $stack, $output);
        if (str_starts_with($line, '@default')) return $this->conditionals->processDefaultDirective($line, $stack, $output);
        if (str_starts_with($line, '@break')) return $this->conditionals->processBreakDirective($line, $stack, $output);
        if (str_starts_with($line, '@endswitch')) return $this->conditionals->processEndswitchDirective($stack, $output);
        $lower = strtolower($line);
        if (str_starts_with($lower, '@wrapper') || str_starts_with($lower, '@wrap')) return $this->directives->processWrapperDirective($line, $stack, $output);
        if (str_starts_with($lower, '@endwrapper') || str_starts_with($lower, '@endwrap')) return $this->directives->processEndwrapperDirective($stack, $output);
        return false;
    }

    private function processEventDirectives(string $line): ?string
    {
        $types = ['click','dblclick','mousedown','mouseup','mouseover','mouseout','mousemove','mouseenter','mouseleave','wheel','auxclick','keydown','keyup','keypress','input','change','submit','reset','invalid','search','focus','blur','focusin','focusout','select','selectstart','selectionchange','touchstart','touchmove','touchend','touchcancel','dragstart','drag','dragend','dragenter','dragleave','dragover','drop','play','pause','ended','loadstart','loadeddata','loadedmetadata','canplay','canplaythrough','waiting','seeking','seeked','ratechange','durationchange','volumechange','suspend','stalled','progress','emptied','encrypted','wakeup','load','unload','beforeunload','resize','scroll','orientationchange','visibilitychange','pagehide','pageshow','popstate','hashchange','online','offline','DOMContentLoaded','readystatechange','error','abort','contextmenu','animationstart','animationend','animationiteration','transitionstart','transitionend','transitionrun','transitioncancel','pointerdown','pointerup','pointermove','pointerover','pointerout','pointerenter','pointerleave','pointercancel','gotpointercapture','lostpointercapture','fullscreenchange','fullscreenerror','copy','cut','paste','gamepadconnected','gamepaddisconnected','batterychargingchange','batterylevelchange','deviceorientation','devicemotion','devicelight','deviceproximity','webglcontextlost','webglcontextrestored'];
        $changed = false;
        foreach ($types as $type) {
            $pattern = '/@(?:on)?' . preg_quote($type, '/') . '\s*\(/i';
            while (preg_match($pattern, $line, $m, PREG_OFFSET_CAPTURE) === 1) {
                $start = $m[0][1]; $open = $start + strlen($m[0][0]) - 1;
                [$content, $end] = Balanced::extractParensAt($line, $open);
                if ($content === null) break;
                $line = substr($line, 0, $start) . $this->events->processEventDirective($type, $content) . substr($line, $end);
                $changed = true;
            }
        }
        return $changed ? $line : null;
    }

    private function appendProcessed(string $processed, array $stack, array &$output): void
    {
        $processed = $this->resolveAllPlaceholders($processed);
        $loop = $this->enclosingLoopType($stack);
        if ($loop === null) $output[] = $processed;
        else $this->appendLoopContent($processed, $loop, $output);
    }

    /** @param list<string> $output */
    private function appendLoopContent(string $line, string $loop, array &$output): void
    {
        $variable = '__' . $loop . 'OutputContent__';
        $last = $output === [] ? null : $output[array_key_last($output)];
        if (is_string($last) && str_starts_with($last, $variable . ' += `') && ! str_ends_with($last, '`;')) {
            $output[array_key_last($output)] = rtrim($last, '`') . "\n" . $line . '`;';
        } else $output[] = $variable . ' += `' . $line . '`;';
    }

    private function resolveAllPlaceholders(string $line): string
    {
        foreach ([['/__RC_OUTPUT_PH_\d+__/', 'output'], ['/__RC_INCLUDE_PH_\d+__/', 'include']] as [$pattern, $type]) {
            $line = preg_replace_callback($pattern, fn (): string => $this->scopes->makeRcId($this->scopes->generateChildId($type)), $line) ?? $line;
        }
        return $line;
    }

    /** @param list<array<int, mixed>> $stack */
    private function enclosingLoopType(array $stack): ?string
    {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            $type = $stack[$i][0] ?? null;
            if ($type === 'for' || $type === 'while') return $type;
            if ($type === 'foreach') return null;
        }
        return null;
    }

    private function isAttributeDirective(string $line): bool
    {
        $position = null;
        foreach (['if','foreach','for','while','switch'] as $directive) if (preg_match('/@' . $directive . '\b/', $line, $m, PREG_OFFSET_CAPTURE) === 1) { $position = $m[0][1]; break; }
        if ($position === null) return false;
        $open = strrpos(substr($line, 0, $position), '<');
        return $open !== false && ! str_contains(substr($line, $open, $position - $open), '>');
    }

    private function processInlineDirective(string $line): string
    {
        $line = preg_replace_callback('/@if\s*\((.*?)\)\s*(.*?)\s*@endif/s', fn (array $m): string => '${this.__execute(() => { if(' . $this->expressions->compileStatement(trim($m[1])) . '){ return `' . trim($m[2]) . '`; } return \'\'; })}', $line) ?? $line;
        $line = preg_replace_callback('/@foreach\s*\(\s*\\?\$(\w+)\s+as\s+(?:\\?\$(\w+)\s*=>\s*)?\\?\$(\w+)\s*\)\s*(.*?)@endforeach/s', static function (array $m): string {
            $callback = ($m[2] ?? '') !== '' ? "({$m[3]}, {$m[2]})" : "({$m[3]})";
            return '${this.__foreach(' . $m[1] . ', ' . $callback . ' => `' . trim($m[4]) . "`).join(' ')}";
        }, $line) ?? $line;
        $line = preg_replace_callback('/@for\s*\(\s*\\?\$(\w+)\s*=\s*([^;]+);\s*\\?\$\1\s*([<>=!]+)\s*([^;]+);\s*\\?\$\1\s*\+\+\s*\)\s*(.*?)@endfor/s', fn (array $m): string => '${this.__execute(() => { let __output = \'\'; for(let ' . $m[1] . ' = ' . $this->expressions->compileStatement(trim($m[2])) . '; ' . $m[1] . ' ' . $m[3] . ' ' . $this->expressions->compileStatement(trim($m[4])) . '; ' . $m[1] . '++) { __output += `' . trim($m[5]) . '`; } return __output; })}', $line) ?? $line;
        $loopParam = $this->isTypescript ? '(__loop: any)' : '(__loop)';
        return preg_replace_callback('/@while\s*\((.*?)\)\s*(.*?)@endwhile/s', fn (array $m): string => '${this.__while(' . $loopParam . ' => { let __output = \'\'; let __iterations = 0; while(' . $this->expressions->compileStatement(trim($m[1])) . ' && __iterations < 10000) { __loop.next(); __output += `' . trim($m[2]) . '`; __iterations++; } return __output; })}', $line) ?? $line;
    }

    private function processMultilineIncludes(string $code): string
    {
        $code = preg_replace_callback('/@include\s*\(\s*([^,\'\"][^)]*?)\s*,\s*(\[[^\]]*\]|\{[^}]*\}|[^)]*)\s*\)/s', fn (array $m): string => $this->includeOutput($this->expressions->compileStatement(trim($m[1])), trim($m[2])), $code) ?? $code;
        $code = preg_replace_callback('/@include\s*\(\s*[\'\"]([^\'\"]*)[\'\"]\s*,\s*(\[[^\]]*\]|\{[^}]*\}|[^)]*)\s*\)/s', fn (array $m): string => $this->includeOutput("'{$m[1]}'", trim($m[2])), $code) ?? $code;
        $code = preg_replace_callback('/@include\s*\(\s*([^,\'\"][^)]*?)\s*\)/', fn (array $m): string => '${App.View.renderView(this.__include(' . $this->expressions->compileStatement(trim($m[1])) . '))}', $code) ?? $code;
        return preg_replace('/@include\s*\(\s*[\'\"]([^\'\"]*)[\'\"]\s*\)/', '${App.View.renderView(this.__include("$1", {}))}', $code) ?? $code;
    }

    private function includeOutput(string $path, string $data): string
    {
        preg_match_all('/\$(\w+)/', $data, $matches);
        $used = array_values(array_intersect($matches[1] ?? [], array_keys($this->stateVariables)));
        $dataJs = str_starts_with($data, '[') && str_ends_with($data, ']') ? $this->convertPhpArrayToObject($data) : (preg_replace('/\$(\w+)/', '$1', $data) ?? $data);
        $call = 'App.View.renderView(this.__include(' . $path . ', ' . $dataJs . '))';
        if ($used === []) return '${' . $call . '}';
        $this->reactiveCounter++;
        $param = $this->isTypescript ? '(__rc__: any)' : '(__rc__)';
        return "\${this.__reactive('include', __rc__, __RC_INCLUDE_PH_{$this->reactiveCounter}__, " . $this->pythonList($used) . ', ' . $param . ' => ' . $call . ')}';
    }

    private function convertPhpArrayToObject(string $value): string
    {
        $inside = trim(substr(trim($value), 1, -1)); $parts = [];
        foreach ($this->splitTopLevel($inside, ',') as $pair) {
            $halves = $this->splitTopLevel($pair, '=>');
            if (count($halves) >= 2) $parts[] = '"' . trim(trim(array_shift($halves)), "'\"") . '": ' . $this->expressions->compileStatement(trim(implode('=>', $halves)));
            elseif (trim($pair) !== '') $parts[] = $this->expressions->compileStatement(trim($pair));
        }
        return '{' . implode(', ', $parts) . '}';
    }

    private function resolveImportIncludes(string $code): string
    {
        for ($iteration = 0; $iteration < 100 && preg_match('/@importInclude\s*\(/', $code, $match, PREG_OFFSET_CAPTURE) === 1; $iteration++) {
            $start = $match[0][1]; $open = $start + strlen($match[0][0]) - 1;
            [$args, $argsEnd] = Balanced::extractParensAt($code, $open);
            if ($args === null) break;
            $rest = substr($code, $argsEnd); $depth = 1; $pos = 0; $childrenEnd = null;
            while ($pos < strlen($rest) && preg_match('/@importInclude\s*\(|@endImportInclude/', $rest, $token, PREG_OFFSET_CAPTURE, $pos) === 1) {
                $tokenText = $token[0][0]; $tokenPos = $token[0][1];
                if (str_starts_with($tokenText, '@importInclude')) $depth++; else $depth--;
                if ($depth === 0) { $childrenEnd = $tokenPos; break; }
                $pos = $tokenPos + strlen($tokenText);
            }
            if ($childrenEnd === null) break;
            $children = trim(substr($rest, 0, $childrenEnd));
            $children = $this->processMultilineIncludes($this->resolveImportIncludes($children));
            $argsParts = $this->splitTopLevel(trim($args), ',');
            if (count($argsParts) === 1) { $path = $argsParts[0]; $data = null; }
            else { array_shift($argsParts); $path = array_shift($argsParts) ?? "''"; $data = $argsParts === [] ? null : implode(',', $argsParts); }
            $dataParts = [];
            if ($data !== null) foreach ($this->splitTopLevel(trim(trim($data), '[]'), ',') as $pair) {
                $halves = $this->splitTopLevel($pair, '=>');
                if (count($halves) >= 2) $dataParts[] = '"' . trim(trim(array_shift($halves)), "'\"") . '": ' . $this->expressions->compileStatement(trim(implode('=>', $halves)));
            }
            $dataParts[] = '"' . ChildrenSlot::DATA_NAME . '": `' . trim($children) . '`';
            $call = 'App.View.renderView(this.__include(' . $this->expressions->compileStatement(trim($path)) . ', {' . implode(', ', $dataParts) . '}))';
            preg_match_all('/\$(\w+)/', $args, $vars);
            $used = array_values(array_unique(array_intersect($vars[1] ?? [], array_keys($this->stateVariables)))); sort($used);
            if ($used === []) $replacement = '${' . $call . '}';
            else { $this->reactiveCounter++; $param = $this->isTypescript ? '(__rc__: any)' : '(__rc__)'; $replacement = "\${this.__reactive('include', __rc__, __RC_INCLUDE_PH_{$this->reactiveCounter}__, " . $this->pythonList($used) . ', ' . $param . ' => ' . $call . ')}'; }
            $totalEnd = $argsEnd + $childrenEnd + strlen('@endImportInclude');
            $code = substr($code, 0, $start) . $replacement . substr($code, $totalEnd);
        }
        return $code;
    }

    private function removeBalancedDirective(string $code, string $directive): string
    {
        while (preg_match('/@' . preg_quote($directive, '/') . '\s*\(/', $code, $m, PREG_OFFSET_CAPTURE) === 1) {
            $start = $m[0][1]; $open = $start + strlen($m[0][0]) - 1; [, $end] = Balanced::extractParensAt($code, $open);
            if ($end <= $open) break;
            $code = substr($code, 0, $start) . substr($code, $end);
        }
        return $code;
    }

    private function removePageDirectives(string $code): string
    {
        foreach (['pageStart','pageOpen','pageEnd','pageClose','docStart','docEnd'] as $name) {
            $code = preg_replace('/@' . $name . '\b\s*\n?/im', '', $code) ?? $code;
            $code = preg_replace('/^\s*@' . $name . '\b\s*$/im', '', $code) ?? $code;
        }
        return $code;
    }

    private function isIncompleteEventDirective(string $line): bool
    {
        if (preg_match('/@(?:on)?(?:click|change|submit|focus|blur|input|keydown|keyup|keypress|mousedown|mouseup|mouseover|mouseout|mousemove|mouseenter|mouseleave|dblclick|contextmenu|wheel|scroll|resize|load|unload|beforeunload|error|abort|select|selectstart|selectionchange)\s*\(/i', $line) !== 1) return false;
        return ! $this->parenthesesBalanced($line);
    }

    private function isIncompleteViewDirective(string $line): bool
    {
        $lower = strtolower(trim($line));
        return (str_starts_with($lower, '@view(') || str_starts_with($lower, '@template(')) && ! $this->parenthesesBalanced($line);
    }

    /** @param list<string> $lines @return array{string, int} */
    private function joinMultilineDirective(array $lines, int $start): array
    {
        $result = trim($lines[$start]); $used = 1;
        for ($i = $start + 1; $i < count($lines); $i++) { $result .= ' ' . trim($lines[$i]); $used++; if ($this->parenthesesBalanced($result)) break; }
        return [$result, $used];
    }

    private function parenthesesBalanced(string $line): bool
    {
        $depth = 0; $quote = null;
        for ($i = 0; $i < strlen($line); $i++) { $ch = $line[$i]; if ($quote !== null) { if ($ch === '\\') $i++; elseif ($ch === $quote) $quote = null; } elseif ($ch === "'" || $ch === '"') $quote = $ch; elseif ($ch === '(') $depth++; elseif ($ch === ')') $depth--; }
        return $depth === 0;
    }

    /** @return list<string> */
    private function splitLines(string $value): array
    {
        if ($value === '') return [];
        $lines = preg_split('/\r\n|\n|\r/', $value) ?: [];
        if (preg_match('/(?:\r\n|\n|\r)$/', $value) === 1) array_pop($lines);
        return $lines;
    }

    /** @return list<string> */
    private function splitTopLevel(string $value, string $delimiter): array
    {
        $out = []; $buffer = ''; $paren = 0; $bracket = 0; $brace = 0; $quote = null; $length = strlen($value); $dlen = strlen($delimiter);
        for ($i = 0; $i < $length;) { $ch = $value[$i]; if ($quote !== null) { $buffer .= $ch; if ($ch === '\\' && $i + 1 < $length) $buffer .= $value[++$i]; elseif ($ch === $quote) $quote = null; $i++; continue; } if ($ch === "'" || $ch === '"') { $quote = $ch; $buffer .= $ch; $i++; continue; } if ($ch === '(') $paren++; elseif ($ch === ')') $paren--; elseif ($ch === '[') $bracket++; elseif ($ch === ']') $bracket--; elseif ($ch === '{') $brace++; elseif ($ch === '}') $brace--; if ($paren === 0 && $bracket === 0 && $brace === 0 && substr($value, $i, $dlen) === $delimiter) { $out[] = $buffer; $buffer = ''; $i += $dlen; continue; } $buffer .= $ch; $i++; }
        if ($buffer !== '') $out[] = $buffer;
        return $out;
    }

    /** @param list<string> $values */
    private function pythonList(array $values): string
    {
        return '[' . implode(', ', array_map(static fn (string $value): string => "'{$value}'", $values)) . ']';
    }

    /** @param list<string> $prefixes */
    private function startsWithAny(string $line, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) if (str_starts_with($line, $prefix)) return true;
        return false;
    }
}
