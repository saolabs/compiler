<?php

declare(strict_types=1);

namespace Saola\Compiler\Emit;

use Saola\Compiler\Ast\BlockOutlet;
use Saola\Compiler\Ast\BlockSection;
use Saola\Compiler\Ast\ChildrenNode;
use Saola\Compiler\Ast\EchoNode;
use Saola\Compiler\Ast\ExecNode;
use Saola\Compiler\Ast\ForBlock;
use Saola\Compiler\Ast\ForeachBlock;
use Saola\Compiler\Ast\HtmlElement;
use Saola\Compiler\Ast\IfBlock;
use Saola\Compiler\Ast\ImportIncludeNode;
use Saola\Compiler\Support\Re;
use Saola\Compiler\Ast\IncludeNode;
use Saola\Compiler\Ast\LongSectionNode;
use Saola\Compiler\Ast\Node;
use Saola\Compiler\Ast\RootNode;
use Saola\Compiler\Ast\SectionNode;
use Saola\Compiler\Ast\SwitchBlock;
use Saola\Compiler\Ast\TextNode;
use Saola\Compiler\Ast\WhileBlock;
use Saola\Compiler\Ast\YieldNode;
use Saola\Compiler\Hydration\HydrateId;
use Saola\Compiler\Hydration\HydrateIdGenerator;
use Saola\Compiler\Hydration\IdMode;

/** Structured JavaScript render emitter; port of sao2js/render_generator.py. */
final class JsEmitter
{
    /** @var array<string, true> */ private array $stateVariables = [];
    /** @var array<string, true> */ private array $declaredVariables = [];
    /** @var list<array{string,string}> */ private array $loopScopes = [];
    /** @var array<string, true> */ private array $whileForVariables = [];
    /** @var list<array<string,true>> */ private array $declaredScopeStack = [];
    /** @var array<string, true> */ private array $prerenderedSections = [];
    /** @var array<string, string> config tĩnh → tên hằng, xem hoistStaticConfig() */ private array $hoistedConfigs = [];
    private bool $inWhileOrFor = false;
    private HydrateIdGenerator $ids;

    /** @param iterable<string> $stateVariables @param iterable<string> $declaredVariables */
    public function __construct(
        iterable $stateVariables = [],
        iterable $declaredVariables = [],
        private readonly bool $isTypescript = false,
        private readonly string $scopeClass = '',
        private readonly IdMode $idMode = IdMode::Terse,
    )
    {
        foreach ($stateVariables as $name) $this->stateVariables[(string) $name] = true;
        foreach ($declaredVariables as $name) $this->declaredVariables[(string) $name] = true;
        $this->ids = new HydrateIdGenerator();
    }

    /**
     * @param iterable<string>|null $prerenderedSections
     * @param bool $onlyPrerendered Đảo bộ lọc: emit ĐÚNG các section trong
     *   `$prerenderedSections` thay vì bỏ chúng. Đây là thân của `prerender()`
     *   cho trang `@await`/`@fetch` — phần tĩnh bị render() loại ra phải được
     *   sinh Ở ĐÂY bằng chính các generator này, nếu không nội dung tới được
     *   Blade mà không tới được JS (SSR hiện, CSR mất).
     */
    public function generate(RootNode $root, bool $hasExtends = false, ?string $extendsExpression = null, ?string $extendsData = null, ?array $blockSections = null, ?iterable $prerenderedSections = null, bool $onlyPrerendered = false): string
    {
        $this->ids->reset(); $this->loopScopes = []; $this->inWhileOrFor = false; $this->whileForVariables = [];
        $this->hoistedConfigs = [];
        $this->prerenderedSections = [];
        foreach ($prerenderedSections ?? [] as $name) $this->prerenderedSections[(string) $name] = true;
        $this->declaredScopeStack = [$this->declaredVariables + $this->stateVariables + ['parentElement' => true, 'parentReactive' => true]];
        $lines = ['let parentElement = this.parentElement;', 'let parentReactive = null;'];
        if ($hasExtends) {
            foreach ($root->children as $node) {
                if ($node instanceof BlockSection) { if ($this->wantsSection($node->name, $onlyPrerendered)) $lines[] = $this->genBlockSection($node, ''); }
                elseif ($node instanceof SectionNode) { if ($this->wantsSection($node->name, $onlyPrerendered)) $lines[] = $this->genSection($node, ''); }
                elseif ($node instanceof LongSectionNode) { if ($this->wantsSection($node->name, $onlyPrerendered)) $lines[] = $this->genLongSection($node, ''); }
            }
            if ($extendsExpression !== null && $extendsExpression !== '') {
                $lines[] = 'this.superViewPath = ' . $extendsExpression . ';';
                $lines[] = 'return this.extendView(this.superViewPath, ' . (($extendsData !== null && $extendsData !== '') ? $extendsData : '{}') . ');';
            } else $lines[] = 'return this.extendView(this.superViewPath);';
        } else {
            $arrow = $this->arrowParent();
            if ($this->hasExecNodes($root->children)) {
                $this->pushDeclaredScope(['parentElement']);
                $code = $this->genChildrenImperative($root->children, '        ', '__execArr');
                $this->popDeclaredScope();
                $lines[] = 'return this.wrapper(' . $arrow . ' {';
                $lines[] = '    const __execArr = [];'; $lines[] = $code; $lines[] = '    return __execArr;'; $lines[] = '});';
            } else {
                $lines[] = 'return this.wrapper(' . $arrow . ' [';
                $lines[] = $this->genChildrenList($root->children, '    ');
                $lines[] = ']);';
            }
        }
        // Hằng config tĩnh đứng ngay sau hai dòng `let` mở đầu thân hàm.
        $hoisted = [];
        foreach ($this->hoistedConfigs as $options => $name) $hoisted[] = 'const ' . $name . ' = ' . $options . ';';
        array_splice($lines, 2, 0, $hoisted);
        return implode("\n", $lines);
    }

    /**
     * Config KHÔNG chạy mã (chỉ class/attr/style/prop tĩnh) được đưa lên thành
     * hằng ở đầu thân `render()`, dùng chung cho mọi lượt gọi factory con.
     *
     * Hai lợi ích, cả hai đều đo được ở `@foreach` 1000 dòng:
     *   - không cấp phát lại `{ classes: [{ type: 'static', … }] }` cho mỗi
     *     element ở mỗi lượt render;
     *   - client nhận ra ĐÚNG object cũ (`Html.updateConfig` so identity) và bỏ
     *     qua hẳn việc gỡ/gắn lại — đây là hợp đồng với runtime, đổi dạng phát
     *     ở đây phải xem lại phía đó.
     *
     * Nhận diện bảo thủ: bỏ hết chuỗi nháy rồi phần còn lại chỉ được gồm khung
     * object và vài từ khoá. Có factory, event, template literal hay bất kỳ
     * định danh nào khác ⇒ giữ nguyên tại chỗ.
     */
    private function hoistStaticConfig(string $options): string
    {
        if ($options === '{}') return $options;
        $bare = preg_replace('/"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'/s', '', $options);
        if ($bare === null || preg_match('/^(?:[\s{}\[\]:,]|\b(?:classes|attrs|styles|props|type|value|true|false|null)\b|-?\d+(?:\.\d+)?)*$/', $bare) !== 1) return $options;
        return $this->hoistedConfigs[$options] ??= '__sc' . (count($this->hoistedConfigs) + 1);
    }

    /** Tên runtime mà thân vòng lặp được dùng mà vẫn coi là "thuần theo item + state". */
    private const MEMO_SAFE = ['this'=>1,'true'=>1,'false'=>1,'null'=>1,'undefined'=>1,'typeof'=>1,'new'=>1,'return'=>1,'void'=>1,'in'=>1,'of'=>1,'instanceof'=>1,'parentElement'=>1,'parentReactive'=>1,'App'=>1,'__VIEW_ID__'=>1,'__loopIdentity'=>1,'String'=>1,'Number'=>1,'Boolean'=>1,'Math'=>1,'JSON'=>1,'Array'=>1,'Object'=>1,'parseInt'=>1,'parseFloat'=>1,'isNaN'=>1,'event'=>1,'any'=>1];

    /** Hàm dựng cây của runtime mà thân vòng lặp được gọi qua `this.` mà vẫn coi là thuần. */
    private const MEMO_RUNTIME = ['html'=>1,'text'=>1,'output'=>1,'reactive'=>1,'include'=>1,'wrapattr'=>1,'decodeTextContent'=>1,'__reactive'=>1,'__foreach'=>1,'__for'=>1,'__while'=>1,'__include'=>1,'__attr'=>1,'__subscribe'=>1,'__text'=>1,'__output'=>1,'__children'=>1,'__classBinding'=>1,'__styleBinding'=>1,'__addEventConfig'=>1];

    /**
     * Mức "bỏ qua dòng không đổi" cho một `@foreach` — tham số cuối của
     * `__foreach`, hợp đồng với `ViewController.__foreach` phía client:
     *
     *   0  luôn chạy lại callback (hành vi trước 11/2026);
     *   1  thân chỉ phụ thuộc item + state → client giữ nguyên dòng khi item
     *      cùng reference và không state/data/mutation nào đổi;
     *   2  như 1 nhưng thân còn dùng vị trí (`loop`, index, key) → thêm điều
     *      kiện cùng index và cùng tổng số phần tử.
     *
     * DANH SÁCH TRẮNG, không phải danh sách đen: mọi định danh trần trong mã
     * đã sinh phải là tham số vòng lặp, state, hoặc tên runtime đã biết. Gặp
     * bất cứ thứ gì khác — biến `let` trong script, biến data, hàm tự viết —
     * là về 0, vì runtime không biết khi nào chúng đổi. Thân dùng chính state
     * của biểu thức danh sách cũng về 0: client loại state đó khỏi phép so.
     *
     * @param list<string> $ownKeys state của biểu thức danh sách
     */
    private function foreachMemoMode(ForeachBlock $node, string $children, bool $exec, array $ownKeys): int
    {
        if ($exec || $ownKeys === []) return 0;
        $code = preg_replace('/"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'/s', '""', $children);
        if ($code === null) return 0;
        // Template literal: chữ tĩnh không phải mã, chỉ giữ các biểu thức `${…}`.
        // Không tách sạch được (template lồng nhau) thì coi như không an toàn.
        $unsafe = false;
        $code = preg_replace_callback('/`((?:[^`\\\\]|\\\\.)*)`/s', static function (array $m) use (&$unsafe): string {
            preg_match_all('/\$\{((?:[^{}]|\{[^{}]*\})*)\}/s', $m[1], $parts);
            if (count($parts[1]) !== substr_count($m[1], '${')) $unsafe = true;
            return '(' . implode(') (', $parts[1]) . ')';
        }, $code);
        if ($code === null || $unsafe) return 0;
        $safe = self::MEMO_SAFE + [$node->valueVar => 1];
        // Tham số của mọi arrow function bên trong (vòng lặp lồng, factory con).
        preg_match_all('/\(([^()]*)\)\s*=>/', $code, $arrows);
        foreach ($arrows[1] as $params) foreach (explode(',', $params) as $param) $safe[trim(explode(':', $param, 2)[0])] = 1;
        $mode = $node->keyVar !== null ? 2 : 1;
        preg_match_all('/[A-Za-z_$][\w$]*/', $code, $ids, PREG_OFFSET_CAPTURE);
        foreach ($ids[0] as [$name, $offset]) {
            $before = rtrim(substr($code, max(0, $offset - 8), min(8, $offset)));
            $prev = $before === '' ? '' : $before[strlen($before) - 1];
            if ($prev === '.') {
                // `x.thuocTinh` theo x. Riêng `this.<tên>`: chỉ các hàm dựng cây của runtime là thuần;
                // `this.view`, `this.data`, method tự viết… đọc được thứ runtime không theo dõi.
                $owner = rtrim(substr($before, 0, -1));
                if (substr($owner, -4) === 'this' && preg_match('/[\w$]this$/', $owner) !== 1 && !isset(self::MEMO_RUNTIME[$name])) {
                    if (getenv('SAOC_MEMO_DEBUG')) fwrite(STDERR, "memo=0 vì `this.{$name}`\n");
                    return 0;
                }
                continue;
            }
            $after = ltrim(substr($code, $offset + strlen($name), 8));
            if (($after[0] ?? '') === ':' && ($prev === '{' || $prev === ',')) continue;   // khoá object
            if (preg_match('/^__sc\d+$/', $name) === 1) continue;        // config tĩnh đã hoist
            if ($name === '__loop' || $name === '__loopKey' || $name === '__loopIndex') { $mode = 2; continue; }
            if (in_array($name, $ownKeys, true)) { if (getenv('SAOC_MEMO_DEBUG')) fwrite(STDERR, "memo=0 vì state của danh sách `{$name}`\n"); return 0; }
            if (isset($safe[$name]) || isset($this->stateVariables[$name])) continue;
            if (getenv('SAOC_MEMO_DEBUG')) fwrite(STDERR, "memo=0 vì `{$name}` (trước: '{$prev}')\n");
            return 0;
        }
        return $mode;
    }

    private function param(string $name): string { return $this->isTypescript ? $name . ': any' : $name; }
    private function arrowParent(): string { return '(' . $this->param('parentElement') . ') =>'; }
    private function arrowReactive(): string { return '(' . $this->param('parentReactive') . ', ' . $this->param('parentElement') . ') =>'; }

    /** @param iterable<string> $names */
    private function pushDeclaredScope(iterable $names = []): void { $scope=[]; foreach($names as $name)$scope[(string)$name]=true; $this->declaredScopeStack[]=$scope; }
    private function popDeclaredScope(): void { if($this->declaredScopeStack!==[])array_pop($this->declaredScopeStack); }
    private function isDeclared(string $name): bool { for($i=count($this->declaredScopeStack)-1;$i>=0;$i--)if(isset($this->declaredScopeStack[$i][$name]))return true; return false; }
    private function declare(string $name): void { if($this->declaredScopeStack===[])$this->declaredScopeStack=[[]]; $this->declaredScopeStack[array_key_last($this->declaredScopeStack)][$name]=true; }

    private function normalizeExecExpression(string $expression): string
    {
        $reserved=['true','false','null','undefined','this','super','if','else','for','while','switch','case','default','return','break','continue','new','delete','typeof','instanceof','in','of','void','yield','await','let','const','var','class','function','App','__STATE__','__VIEW_ID__','parentElement','parentReactive','__loop','__loopKey','__loopIndex','loopCtx'];
        $out=[];
        foreach($this->splitTopLevelSemicolons($expression) as $statement){$value=trim($statement);if($value==='')continue;if(preg_match('/^\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*(?![=])(.*)$/s',$value,$m)===1&&!in_array($m[1],$reserved,true)&&!isset($this->whileForVariables[$m[1]])&&!$this->isDeclared($m[1])){$value='let '.$value;$this->declare($m[1]);}$out[]=$value;}
        return implode('; ', $out);
    }

    /** @return list<string> */
    private function conditionAssignmentVariables(?string $expression): array
    {
        if(!$expression)return[];$reserved=['true','false','null','undefined','this','super','if','else','for','while','switch','case','default','return','break','continue','new','delete','typeof','instanceof','in','of','void','yield','await','let','const','var','class','function','App','__STATE__','__VIEW_ID__','parentElement','parentReactive','__loop','__loopKey','__loopIndex','loopCtx'];
        preg_match_all('/(?<![\w.\]])\b([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*(?![=])/',$expression,$matches);$out=[];foreach($matches[1]??[] as $name)if(!in_array($name,$reserved,true)&&!in_array($name,$out,true))$out[]=$name;return$out;
    }

    /** @param list<Node> $nodes */ private function hasExecNodes(array $nodes): bool { foreach($nodes as $node)if($node instanceof ExecNode)return true;return false; }
    /** @param list<Node> $nodes */
    private function genChildrenImperative(array $nodes,string $indent,string $arrayName='__execArr'): string { $lines=[];foreach($nodes as $node){if($node instanceof ExecNode)$lines[]=$indent.$this->normalizeExecExpression($node->jsExpr).';';elseif(($code=$this->genNode($node,$indent.'    '))!==null){$lines[]=$indent.$arrayName.'.push(';$lines[]=$code;$lines[]=$indent.');';}}return implode("\n",$lines); }
    /** @param list<Node> $nodes */
    private function genChildrenList(array $nodes,string $indent): string { $items=[];foreach($nodes as $node)if(($code=$this->genNode($node,$indent))!==null)$items[]=$code;return implode(",\n",$items); }

    private function genNode(Node $node,string $indent): ?string
    {
        return match(true){
            $node instanceof HtmlElement=>$this->genHtml($node,$indent),$node instanceof TextNode=>$this->genText($node,$indent),$node instanceof EchoNode=>$this->genEcho($node,$indent),$node instanceof IfBlock=>$this->genIf($node,$indent),$node instanceof ForeachBlock=>$this->genForeach($node,$indent),$node instanceof WhileBlock=>$this->genWhile($node,$indent),$node instanceof ForBlock=>$this->genFor($node,$indent),$node instanceof SwitchBlock=>$this->genSwitch($node,$indent),$node instanceof BlockOutlet=>$this->genBlockOutlet($node,$indent),$node instanceof YieldNode=>$this->genYield($node,$indent),$node instanceof ImportIncludeNode=>$this->genImportInclude($node,$indent),$node instanceof IncludeNode=>$this->genInclude($node,$indent),$node instanceof ChildrenNode=>$indent.'...this.__children(__ONE_CHILDREN_CONTENT__, parentElement)',$node instanceof ExecNode=>null,$node instanceof BlockSection=>isset($this->prerenderedSections[$node->name])?null:$this->genNestedBlockSection($node,$indent),$node instanceof SectionNode=>isset($this->prerenderedSections[$node->name])?null:$this->genSection($node,$indent),$node instanceof LongSectionNode=>isset($this->prerenderedSections[$node->name])?null:$this->genLongSection($node,$indent),default=>null};
    }

    private function genHtml(HtmlElement $node,string $indent): string
    {
        $id=$node->isVoid?$this->ids->nextElement($node->tag):$this->ids->pushElement($node->tag);$idString=$this->formatId($id);$options=$this->genOptions($node);
        if (in_array($node->tag, ['textarea', 'title'], true)) {
            $content = $this->genTextContent($node);
            $options = $options === '{}' ? '{ content: ' . $content . ' }'
                : substr($options, 0, -2) . ', content: ' . $content . ' }';
            $this->ids->popScope();
            return $indent . 'this.html(' . $idString . ', "' . $node->tag . '", parentElement, ' . $options . ')';
        }
        $options=$this->hoistStaticConfig($options);
        if($node->isVoid||$node->children===[]){if(!$node->isVoid)$this->ids->popScope();return $indent.'this.html('.$idString.', "'.$node->tag.'", parentElement, '.$options.')';}
        $arrow=$this->arrowParent();
        if($this->hasExecNodes($node->children)){$this->pushDeclaredScope(['parentElement']);$code=$this->genChildrenImperative($node->children,$indent.'        ');$this->popDeclaredScope();$this->ids->popScope();if($options==='{}')return $indent.'this.html('.$idString.', "'.$node->tag.'", parentElement, {},'."\n".$indent.'    '.$arrow.' {'."\n".$indent.'        const __execArr = [];'."\n".$code."\n".$indent.'        return __execArr;'."\n".$indent.'    })';return $indent.'this.html('.$idString.', "'.$node->tag.'", parentElement,'."\n".$indent.'    '.$options.','."\n".$indent.'    '.$arrow.' {'."\n".$indent.'        const __execArr = [];'."\n".$code."\n".$indent.'        return __execArr;'."\n".$indent.'    })';}
        $children=$this->genChildrenList($node->children,$indent.'    ');$this->ids->popScope();if($options==='{}')return $indent.'this.html('.$idString.', "'.$node->tag.'", parentElement, {}, '.$arrow.' ['."\n".$children."\n".$indent.'])';return $indent.'this.html('.$idString.', "'.$node->tag.'", parentElement,'."\n".$indent.'    '.$options.','."\n".$indent.'    '.$arrow.' ['."\n".$children."\n".$indent.'    ])';
    }

    private function genText(TextNode $node,string $indent): string { return $indent."this.text('".$this->jsTextLiteral($node->text)."')"; }

    /** RCDATA is owned by its element; it cannot contain Output comment nodes. */
    private function genTextContent(HtmlElement $node): string
    {
        $parts = [];
        $keys = [];
        foreach ($node->children as $child) {
            if ($child instanceof TextNode) {
                $parts[] = "'" . $this->jsTextLiteral($child->text) . "'";
            } elseif ($child instanceof EchoNode) {
                $value = 'String(' . $child->jsExpr . " ?? '')";
                if (!$child->escaped) {
                    // Raw SSR echoes still pass through the HTML RCDATA parser.
                    $value = 'this.decodeTextContent(' . $value . ')';
                }
                $parts[] = $value;
                $keys += $child->stateVars;
            } else {
                throw new \LogicException('Only text and interpolation are supported inside <' . $node->tag . '>.');
            }
        }
        $stateKeys = array_keys($keys);
        sort($stateKeys);
        return '{ factory: () => ' . ($parts === [] ? "''" : implode(' + ', $parts))
            . ', stateKeys: ' . $this->jsonList($stateKeys) . ' }';
    }
    private function genEcho(EchoNode $node,string $indent): string
    {
        $stateKeys=array_keys($node->stateVars);$wrap=$stateKeys!==[];
        if(!$wrap&&$this->inWhileOrFor){preg_match_all('/\b([a-zA-Z_]\w*)\b/',$node->jsExpr,$matches);$stateKeys=array_values(array_intersect(array_unique($matches[1]??[]),array_keys($this->whileForVariables)));$wrap=$stateKeys!==[];}
        if($node->escaped){$wrap=$wrap||$this->loopScopes!==[];if(!$wrap)return $indent."this.text(String({$node->jsExpr} ?? ''))";}
        sort($stateKeys);return $indent.'this.output('.$this->formatId($this->ids->nextOutput()).', parentElement, '.($node->escaped?'true':'false').', '.$this->jsonList($stateKeys).', '.$this->arrowParent().' '.$node->jsExpr.')';
    }

    private function genIf(IfBlock $node,string $indent): string
    {
        $id=$this->ids->pushReactive('if');$lines=[$indent.'this.reactive('.$this->formatId($id).', "if", parentReactive, parentElement, '.$this->jsonList(array_keys($node->stateVars)).', '.$this->arrowReactive().' {',$indent.'    const reactiveContents = [];'];$this->pushDeclaredScope(['parentReactive','parentElement']);$decl=[];foreach($node->branches as[, $condition])foreach($this->conditionAssignmentVariables($condition) as$name)if(!$this->isDeclared($name)){$decl[]=$name;$this->declare($name);}foreach($decl as$name)$lines[]=$indent.'    let '.$name.($this->isTypescript?': any':'').';';$case=0;
        foreach($node->branches as$i=>[, $condition,$children]){$this->ids->pushCase(++$case);$lines[]=$indent.'    '.($i===0?'if ('.$condition.') {':($condition!==null?'else if ('.$condition.') {':'else {'));$this->pushDeclaredScope(['parentReactive','parentElement']);if($this->hasExecNodes($children)){foreach($children as$child){if($child instanceof ExecNode)$lines[]=$indent.'        '.$this->normalizeExecExpression($child->jsExpr).';';elseif(($code=$this->genNode($child,$indent.'        '))!==null){$lines[]=$indent.'        reactiveContents.push(';$lines[]=$code;$lines[]=$indent.'        );';}}}else{$items=[];foreach($children as$child)if(($code=$this->genNode($child,$indent.'        '))!==null)$items[]=$code;if($items!==[]){$lines[]=$indent.'        reactiveContents.push(';$lines[]=implode(",\n",$items);$lines[]=$indent.'        );';}}$this->popDeclaredScope();$this->ids->popScope();$lines[]=$indent.'    }';}
        $lines[]=$indent.'    return reactiveContents;';$lines[]=$indent.'})';$this->popDeclaredScope();$this->ids->popScope();return implode("\n",$lines);
    }

    private function genForeach(ForeachBlock $node,string $indent): string
    {
        $id=$this->ids->pushReactive('foreach');$keys=array_keys($node->stateVars);sort($keys);$this->loopScopes[]=[$id,$node->customKeyJs??'__loopIdentity'];
        if($node->keyVar!==null)$params=$this->isTypescript?'('.$node->valueVar.': any, '.$node->keyVar.': any, __loopIndex: any, __loop: any, __loopIdentity: any)':'('.$node->valueVar.', '.$node->keyVar.', __loopIndex, __loop, __loopIdentity)';else$params=$this->isTypescript?'('.$node->valueVar.': any, __loopKey: any, __loopIndex: any, __loop: any, __loopIdentity: any)':'('.$node->valueVar.', __loopKey, __loopIndex, __loop, __loopIdentity)';
        $exec=$this->hasExecNodes($node->children);if($exec){$vars=[$node->valueVar,'__loopKey','__loopIndex','__loop','__loopIdentity'];if($node->keyVar)$vars[]=$node->keyVar;$this->pushDeclaredScope($vars);$children=$this->genChildrenImperative($node->children,$indent.'    ');$this->popDeclaredScope();}else$children=$this->genChildrenList($node->children,$indent.'        ');array_pop($this->loopScopes);$this->ids->popScope();$keyFn=$node->customKeyJs!==null?($this->isTypescript?', ('.$node->valueVar.': any) => '.$node->customKeyJs:', ('.$node->valueVar.') => '.$node->customKeyJs):', undefined';$keyFn .= ', true, '.json_encode($id).', '.$this->foreachMemoMode($node, $children, $exec, $keys);
        if($exec){if($keys!==[])return $indent.'this.reactive('.$this->formatId($id).', "foreach", parentReactive, parentElement, '.$this->jsonList($keys).', '.$this->arrowReactive().' {'."\n".$indent.'    return this.__foreach('.$node->arrayJs.', '.$params.' => {'."\n".$indent.'        const __execArr = [];'."\n".$children."\n".$indent.'        return __execArr;'."\n".$indent.'    }'.$keyFn.')'."\n".$indent.'})';return $indent.'...this.__foreach('.$node->arrayJs.', '.$params.' => {'."\n".$indent.'    const __execArr = [];'."\n".$children."\n".$indent.'    return __execArr;'."\n".$indent.'}'.$keyFn.')';}
        if($keys!==[])return $indent.'this.reactive('.$this->formatId($id).', "foreach", parentReactive, parentElement, '.$this->jsonList($keys).', '.$this->arrowReactive().' {'."\n".$indent.'    return this.__foreach('.$node->arrayJs.', '.$params.' => ['."\n".$children."\n".$indent.'    ]'.$keyFn.')'."\n".$indent.'})';return $indent.'...this.__foreach('.$node->arrayJs.', '.$params.' => ['."\n".$children."\n".$indent.']'.$keyFn.')';
    }

    private function genWhile(WhileBlock $node,string $indent): string
    {
        $id=$this->ids->pushReactive('while');$loop=$node->loopVar??'i';$this->loopScopes[]=[$id,$node->customKeyJs??$loop];$previous=$this->inWhileOrFor;$previousVars=$this->whileForVariables;$this->inWhileOrFor=true;$this->whileForVariables[$loop]=true;$this->pushDeclaredScope([$loop,'loopCtx','parentElement']);$content=[];$exec=[];foreach($node->children as$child){if($child instanceof ExecNode)$exec[]=$child;else$content[]=$child;}$items=[];foreach($content as$child)if(($code=$this->genNode($child,$indent.'            '))!==null)$items[]=$code;$this->inWhileOrFor=$previous;$this->whileForVariables=$previousVars;$this->popDeclaredScope();array_pop($this->loopScopes);$this->ids->popScope();$execLines=[];foreach($exec as$child)$execLines[]=$indent.'            '.$this->normalizeExecExpression($child->jsExpr).';';$lines=[$indent.'this.__while((loopCtx) => {'];if($node->endVal)$lines[]=$indent.'        loopCtx.setCount('.$node->endVal.');';$lines[]=$indent.'    let __whileOutput = [];';$lines[]=$indent.'    while ('.$node->conditionJs.') {';$lines[]=$indent.'        loopCtx.setCurrentTimes('.$loop.');';if($items!==[]){$lines[]=$indent.'        __whileOutput.push(';$lines[]=implode(",\n",$items);$lines[]=$indent.'        );';}if($execLines!==[])$lines[]=implode("\n",$execLines);$lines[]=$indent.'    }';$lines[]=$indent.'    return __whileOutput;';$lines[]=$indent.'}'.($node->endVal?', '.$node->endVal:'').')';return implode("\n",$lines);
    }

    private function genFor(ForBlock $node,string $indent): string
    {
        $id=$this->ids->pushReactive('for');$this->loopScopes[]=[$id,$node->customKeyJs??$node->varName];$previous=$this->inWhileOrFor;$previousVars=$this->whileForVariables;$this->inWhileOrFor=true;$this->whileForVariables[$node->varName]=true;$this->pushDeclaredScope([$node->varName,'__loop','parentElement']);$content=[];$exec=[];foreach($node->children as$child){if($child instanceof ExecNode)$exec[]=$child;else$content[]=$child;}$items=[];foreach($content as$child)if(($code=$this->genNode($child,$indent.'            '))!==null)$items[]=$code;$this->inWhileOrFor=$previous;$this->whileForVariables=$previousVars;$this->popDeclaredScope();array_pop($this->loopScopes);$this->ids->popScope();$keys=array_keys($node->stateVars);sort($keys);$loopParam=$this->isTypescript?'(__loop: any)':'(__loop)';$lines=[];if($keys!==[]){$lines[]=$indent.'this.reactive('.$this->formatId($id).', "for", parentReactive, parentElement, '.$this->jsonList($keys).', '.$this->arrowReactive().' {';$lines[]=$indent.'    return this.__for("increment", '.$node->startJs.', '.$node->endJs.', '.$loopParam.' => {';}else$lines[]=$indent.'this.__for("increment", '.$node->startJs.', '.$node->endJs.', '.$loopParam.' => {';$lines[]=$indent.'        let __forOutput = [];';$lines[]=$indent.'        for (let '.$node->varName.' = '.$node->startJs.'; '.$node->varName.' '.$node->operator.' '.$node->endJs.'; '.$node->varName.'++) {';$lines[]=$indent.'            __loop.setCurrentTimes('.$node->varName.');';if($items!==[]){$lines[]=$indent.'            __forOutput.push(';$lines[]=implode(",\n",$items);$lines[]=$indent.'            );';}foreach($exec as$child)$lines[]=$indent.'            '.$this->normalizeExecExpression($child->jsExpr).';';$lines[]=$indent.'        }';$lines[]=$indent.'        return __forOutput;';$lines[]=$indent.'    })';if($keys!==[])$lines[]=$indent.'})';return implode("\n",$lines);
    }

    private function genSwitch(SwitchBlock $node,string $indent): string
    {
        $id=$this->ids->pushReactive('switch');$keys=array_keys($node->stateVars);sort($keys);$lines=[$indent.'this.reactive('.$this->formatId($id).', "switch", parentReactive, parentElement, '.$this->jsonList($keys).', '.$this->arrowReactive().' {',$indent.'    const reactiveContents = [];',$indent.'    switch ('.$node->exprJs.') {'];$case=0;foreach($node->cases as[$value,$children]){$this->ids->pushCase(++$case);$lines[]=$indent.($value!==null?'        case '.$value.':':'        default:');$items=[];foreach($children as$child)if(($code=$this->genNode($child,$indent.'            '))!==null)$items[]=$code;$this->ids->popScope();if($items!==[]){$lines[]=$indent.'            reactiveContents.push(';$lines[]=implode(",\n",$items);$lines[]=$indent.'            );';}$lines[]=$indent.'            break;';}$lines[]=$indent.'    }';$lines[]=$indent.'    return reactiveContents;';$lines[]=$indent.'})';$this->ids->popScope();return implode("\n",$lines);
    }

    /** render() lấy phần KHÔNG prerender; prerender() lấy đúng phần còn lại. */
    private function wantsSection(string $name, bool $onlyPrerendered): bool { return isset($this->prerenderedSections[$name]) === $onlyPrerendered; }

    private function genBlockSection(BlockSection $node,string $indent): string { $this->ids->pushBlock($node->name);$children=$this->genChildrenList($node->children,$indent.'    ');$this->ids->popScope();return $indent."this.block('block-{$node->name}', '{$node->name}', ".$this->arrowParent().' ['."\n".$children."\n".$indent.']);'; }
    /**
     * `@block` chỉ có nghĩa ở cấp ngoài cùng của view có `@extends`: ở đó nó là một câu lệnh
     * đăng ký nội dung cho layout. Lồng trong thẻ hay trong vòng lặp/điều kiện, hoặc đặt ở view
     * không kế thừa, trước đây sinh ra JS sai cú pháp (`]);` giữa một mảng) và chỉ lộ lúc bundle.
     */
    private function genNestedBlockSection(BlockSection $node, string $indent): string
    {
        throw new \RuntimeException("@block('{$node->name}') phải nằm ở cấp ngoài cùng của view có @extends (viết @extends bên trong <template>); không đặt trong thẻ, @if/@foreach, hay view không kế thừa layout. Muốn một vùng layout điền vào thì dùng @blockOutlet ở layout.");
    }

    private function genBlockOutlet(BlockOutlet $node,string $indent): string { return $indent.'this.blockOutlet('.$this->formatId($this->ids->nextBlockOutlet()).', "'.$node->name.'", parentElement)'; }
    private function genYield(YieldNode $node,string $indent): string { return $indent.'this.yield('.$this->formatId($this->ids->nextYield()).', "'.$node->name.'", '.($node->defaultJs?:'null').', parentElement)'; }
    private function genSection(SectionNode $node,string $indent): string { $keys=array_keys($node->stateVars);sort($keys);return $indent."this.section('{$node->name}', { type: '".($keys!==[]?'reactive':'static')."', contentType: '{$node->contentType}', stateKeys: ".$this->jsonList($keys).' }, () => '.$node->valueJs.');'; }
    private function genLongSection(LongSectionNode $node,string $indent): string { $keys=array_keys($this->collectStateVariables($node->children));sort($keys);return $indent."this.section('{$node->name}', { type: '".($keys!==[]?'reactive':'static')."', contentType: 'html', stateKeys: ".$this->jsonList($keys).' }, '.$this->arrowParent().' ['."\n".$this->genChildrenList($node->children,$indent.'    ')."\n".$indent.']);'; }

    /** @param list<Node> $nodes @return array<string,true> */
    private function collectStateVariables(array $nodes): array { $out=[];foreach($nodes as$node){if(property_exists($node,'stateVars'))$out+=$node->stateVars;if(property_exists($node,'children'))$out+=$this->collectStateVariables($node->children);if($node instanceof IfBlock)foreach($node->branches as[,, $children])$out+=$this->collectStateVariables($children);}return$out; }
    private function genInclude(IncludeNode $node,string $indent): string { $keys=array_keys($node->stateVars);sort($keys);$data=$node->dataJs?trim($node->dataJs):'';if(str_starts_with($data,'{')&&str_ends_with($data,'}'))$data=trim(substr($data,1,-1));return $indent.'this.include('.$this->formatId($this->ids->nextComponent()).', '.$node->pathJs.', parentElement, '.$this->jsonList($keys).', '.$this->arrowParent().' ({'.$data.'})'.($node->listenersJs!==null?', '.$this->maybeTypeListeners($node->listenersJs):'').')'; }
    private function genImportInclude(ImportIncludeNode $node,string $indent): string
    {
        $has=$node->children!==[];$id=$has?$this->ids->pushComponent():$this->ids->nextComponent();$keys=array_keys($node->stateVars);sort($keys);$parts=[];foreach($node->dataPairs as[$key,$value])$parts[]='"'.$key.'": '.$value;if($has){$arrow=$this->arrowParent();if($this->hasExecNodes($node->children)){$this->pushDeclaredScope(['parentElement']);$code=$this->genChildrenImperative($node->children,$indent.'            ');$this->popDeclaredScope();$parts[]='__ONE_CHILDREN_CONTENT__: '.$arrow.' {'."\n".$indent.'        const __execArr = [];'."\n".$code."\n".$indent.'        return __execArr;'."\n".$indent.'    }';}else$parts[]='__ONE_CHILDREN_CONTENT__: '.$arrow.' ['."\n".$this->genChildrenList($node->children,$indent.'        ')."\n".$indent.'    ]';$this->ids->popScope();}$listeners=$node->listenersJs!==null?', '.$this->maybeTypeListeners($node->listenersJs):'';if($parts!==[]){$inner=implode(",\n",array_map(fn(string$p):string=>$indent.'        '.$p,$parts));return $indent.'this.include('.$this->formatId($id).', '.$node->pathJs.', parentElement, '.$this->jsonList($keys).', '.$this->arrowParent().' ({'."\n".$inner."\n".$indent.'    })'.$listeners.')';}return $indent.'this.include('.$this->formatId($id).', '.$node->pathJs.', parentElement, '.$this->jsonList($keys).', '.$this->arrowParent().' ({})'.$listeners.')';
    }

    private function genOptions(HtmlElement $node): string
    {
        $parts=[];if(($value=$this->genClasses($node))!==null)$parts[]='classes: '.$value;if(($value=$this->genAttrs($node))!==null)$parts[]='attrs: '.$value;if(($value=$this->genStyles($node))!==null)$parts[]='styles: '.$value;if(($value=$this->genProps($node))!==null)$parts[]='props: '.$value;if(($value=$this->genEvents($node))!==null)$parts[]='events: '.$value;if($node->eventModifiers!==[]){$mods=[];foreach($node->eventModifiers as$name=>$values)if($values!==[])$mods[]=$name.': '.$this->jsonList($values);if($mods!==[])$parts[]='eventModifiers: { '.implode(', ',$mods).' }';}if($node->bindKey)$parts[]="bind: { key: '{$node->bindKey}' }";if($node->transitionName)$parts[]="transition: { name: '{$node->transitionName}' }";return $parts===[]?'{}':'{ '.implode(', ',$parts).' }';
    }
    private function genClasses(HtmlElement $node): ?string { $items=[];if($this->scopeClass!=='')$items[]='{ type: \'static\', value: "'.$this->scopeClass.'" }';foreach($node->staticClasses as$class)$items[]='{ type: \'static\', value: "'.$class.'" }';foreach($node->bindingClasses as$class=>$info){$keys=array_keys($info['state_vars']??[]);sort($keys);$items[]='{ type: \'binding\', value: "'.$class.'", factory: () => '.$info['js'].', stateKeys: '.$this->jsonList($keys).' }';}foreach($node->dynamicClasses as$info){$keys=array_keys($info['state_vars']??[]);sort($keys);$items[]='{ type: \'dynamic\', factory: () => `'.$info['js'].'`, stateKeys: '.$this->jsonList($keys).' }';}return$items===[]?null:'['.implode(', ',$items).']'; }

    /**
     * Giá trị attr là MỘT nội suy phủ trọn chuỗi (`${expr}`) thì trả `expr`.
     *
     * `:disabled="n < 1"` được preprocessor chuẩn hoá thành
     * `disabled="{{ n < 1 }}"`, tới đây `js` là `${n < 1}`. Bọc lại thành
     * template literal cho ra CHUỖI "false" — mà `disabled="false"` trong DOM
     * vẫn là disabled. Blade thì emit `'disabled' => $n < 1` (boolean thật),
     * nên SSR bật nút còn CSR tắt nút: đúng lớp lệch SSR/CSR.
     *
     * Chỉ gỡ khi nội suy phủ TRỌN chuỗi. Trộn text (`tr${n}sau`) vẫn phải là
     * template literal — đó mới thật sự là chuỗi.
     */
    private static function wholeInterpolation(string $js): ?string
    {
        if (! str_starts_with($js, '${') || ! str_ends_with($js, '}')) {
            return null;
        }

        $depth = 0;
        $length = strlen($js);

        for ($i = 1; $i < $length; $i++) {
            if ($js[$i] === '{') {
                $depth++;
            } elseif ($js[$i] === '}') {
                $depth--;

                // Đóng trước ký tự cuối ⇒ còn text phía sau ⇒ là chuỗi trộn
                if ($depth === 0) {
                    return $i === $length - 1 ? substr($js, 2, $length - 3) : null;
                }
            }
        }

        return null;
    }

    private function genAttrs(HtmlElement $node): ?string { $items=[];foreach($node->staticAttrs as$name=>$value)$items[]='"'.$name.'": { type: \'static\', value: '.($value===true?'true':'"'.str_replace('"','\\"',(string)$value).'"').' }';foreach($node->bindingAttrs as$name=>$info){$keys=array_keys($info['state_vars']??[]);sort($keys);$js=$info['js'];$whole=self::wholeInterpolation($js);$raw=$whole??(str_contains($js,'${')?'`'.$js.'`':$js);$expr=$raw;$factory='() => '.$raw;$yield=($info['is_yield']??false)?", yieldName: '".$info['yield_name']."'":'';$items[]='"'.$name.'": { type: \'binding\', value: '.$expr.', factory: '.$factory.', stateKeys: '.$this->jsonList($keys).$yield.' }';}return$items===[]?null:'{ '.implode(', ',$items).' }'; }
    private function genStyles(HtmlElement $node): ?string { $items=[];foreach($node->styles as$name=>$info){$keys=array_keys($info['state_vars']??[]);sort($keys);$php=trim($info['php']??'');$constant=$keys===[]&&strlen($php)>=2&&$php[0]===$php[strlen($php)-1]&&($php[0]==="'"||$php[0]==='"')&&!str_contains(substr($php,1,-1),$php[0]);if($constant){$items[]='"'.$name.'": { type: \'static\', value: "'.str_replace('"','\\"',substr($php,1,-1)).'" }';continue;}$js=$info['js'];$expr=str_contains($js,'${')?'`'.$js.'`':$js;$factory=str_contains($js,'${')?'() => `'.$js.'`':'() => '.$js;$items[]='"'.$name.'": { type: \'binding\', value: '.$expr.', factory: '.$factory.', stateKeys: '.$this->jsonList($keys).' }';}return$items===[]?null:'{ '.implode(', ',$items).' }'; }
    private function genProps(HtmlElement $node): ?string { $items=[];foreach($node->bindingProps as$name=>$info){$keys=array_keys($info['state_vars']??[]);sort($keys);$items[]='"'.$name.'": { type: \'binding\', factory: () => '.$info['js'].', stateKeys: '.$this->jsonList($keys).' }';}return$items===[]?null:'{ '.implode(', ',$items).' }'; }
    private function genEvents(HtmlElement $node): ?string
    {
        $items = [];
        foreach ($node->events as $name => $handlers) {
            $processed = [];
            foreach ($handlers as $handler) {
                $handler = trim($handler);
                if ($this->isTypescript) $handler = $this->typeEventCallbacks($handler);
                if ((str_starts_with($handler, '{') && str_contains($handler, '"handler"'))
                    || str_contains($handler, '=>')) {
                    $processed[] = $handler;
                    continue;
                }
                $handler = preg_replace('/@event\b/i', 'event', $handler) ?? $handler;
                $processed[] = ($this->isTypescript ? '(event: any) =>' : '(event) =>') . ' ' . $handler;
            }
            $items[] = $name . ': [' . implode(', ', $processed) . ']';
        }
        return $items === [] ? null : '{ ' . implode(', ', $items) . ' }';
    }

    /**
     * Cắt danh sách tham số theo dấu ',' Ở MỨC NGOÀI.
     *
     * `{id, tag}` là MỘT tham số gỡ rối; cắt thô bằng explode(',') sẽ ra
     * `{id` và `tag}` rồi chú kiểu vào giữa pattern.
     *
     * @return list<string>
     */
    private static function splitParams(string $params): array
    {
        $out = []; $buf = ''; $depth = 0;
        for ($i = 0, $n = strlen($params); $i < $n; $i++) {
            $ch = $params[$i];
            if (str_contains('{[', $ch)) $depth++;
            elseif (str_contains('}]', $ch)) $depth--;
            elseif ($ch === ',' && $depth === 0) { $out[] = $buf; $buf = ''; continue; }
            $buf .= $ch;
        }
        if (trim($buf) !== '') $out[] = $buf;

        return $out;
    }

    /** Vị trí ký tự ở mức ngoài của một tham số, false nếu không có. */
    private static function topLevelColon(string $param): int|false { return self::topLevelChar($param, ':'); }
    private static function topLevelEquals(string $param): int|false { return self::topLevelChar($param, '='); }

    private static function topLevelChar(string $param, string $needle): int|false
    {
        $depth = 0;
        for ($i = 0, $n = strlen($param); $i < $n; $i++) {
            $ch = $param[$i];
            if (str_contains('{[(', $ch)) $depth++;
            elseif (str_contains('}])', $ch)) $depth--;
            elseif ($ch === $needle && $depth === 0) return $i;
        }

        return false;
    }

    /** Listener của `@include` đi thẳng từ Parser nên phải chú kiểu ở đây. */
    private function maybeTypeListeners(string $listeners): string
    {
        return $this->isTypescript ? $this->typeEventCallbacks($listeners) : $listeners;
    }

    /**
     * Chú `: any` cho tham số arrow — cả callback compiler sinh lẫn arrow
     * NGƯỜI DÙNG viết trong handler.
     *
     * Bản cũ chỉ nhận đúng `(event) =>`, nên `@click((a, b) => f(a, b))` hay
     * `on$pair: ({id, tag}) => …` ra tham số không kiểu và `tsc --strict` của
     * chính dự án chặn lại (TS7006/TS7031). Không lộ ra sớm hơn vì arrow có
     * tham số vốn đang bị bọc thêm một lớp nên chưa ai viết được.
     *
     * Chạy SAU khi biểu thức đã dịch xong.
     */
    private function typeEventCallbacks(string $handler): string
    {
        // Bỏ qua chuỗi/comment: "(event) =>" trong đó là dữ liệu, không phải code.
        $pattern = <<<'REGEX'
~(?:"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|`(?:\\.|[^`\\])*`|/\*[\s\S]*?\*/|//[^\r\n]*)(*SKIP)(*F)|\(([^()]*)\)\s*=>|(?<![\w$.])([A-Za-z_$][\w$]*)\s*=>~
REGEX;

        return Re::replaceCallback(
            $pattern,
            static function (array $m): string {
                if (($m[2] ?? '') !== '') {
                    return '(' . $m[2] . ': any) =>';
                }
                if (trim($m[1] ?? '') === '') {
                    return '() =>';
                }

                $typed = [];
                foreach (self::splitParams($m[1]) as $param) {
                    $param = trim($param);
                    if ($param === '' || self::topLevelColon($param) !== false) {
                        // Đã có chú kiểu — không đụng.
                        $typed[] = $param;
                        continue;
                    }
                    if (str_starts_with($param, '...')) {
                        $typed[] = $param . ': any[]';
                        continue;
                    }
                    // `a = 1` → `a: any = 1`; `{id, tag}` → `{id, tag}: any`
                    $eq = self::topLevelEquals($param);
                    $typed[] = $eq === false
                        ? $param . ': any'
                        : rtrim(substr($param, 0, $eq)) . ': any = ' . ltrim(substr($param, $eq + 1));
                }

                return '(' . implode(', ', $typed) . ') =>';
            },
            $handler,
        );
    }

    private function formatId(string $base): string { $parts=[];foreach($this->loopScopes as[, $expr])$parts[]='${'.$expr.'}';return '`'.HydrateId::hash($base, $this->idMode).($parts!==[]?'-'.implode('-',$parts):'').'`'; }
    /** @param list<string> $values */ private function jsonList(array $values): string { sort($values);return '['.implode(', ',array_map(static fn(string$value):string=>'"'.$value.'"',$values)).']'; }
    private function jsTextLiteral(string $text): string { $decoded=html_entity_decode($text,ENT_QUOTES|ENT_HTML5,'UTF-8');return str_replace(["\\","'","\r","\n"],["\\\\","\\'",'',"\\n"],$decoded); }
    /** @return list<string> */ private function splitTopLevelSemicolons(string $value): array { $out=[];$buf='';$depth=0;$quote=null;for($i=0;$i<strlen($value);$i++){$ch=$value[$i];if($quote!==null){$buf.=$ch;if($ch==='\\'&&$i+1<strlen($value))$buf.=$value[++$i];elseif($ch===$quote)$quote=null;continue;}if($ch==="'"||$ch==='"'){$quote=$ch;$buf.=$ch;}elseif(str_contains('([{',$ch)){$depth++;$buf.=$ch;}elseif(str_contains(')]}',$ch)){$depth--;$buf.=$ch;}elseif($ch===';'&&$depth===0){$out[]=$buf;$buf='';}else$buf.=$ch;}if(trim($buf)!=='')$out[]=$buf;return$out; }
}
