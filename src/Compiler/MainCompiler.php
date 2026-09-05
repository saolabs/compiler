<?php

declare(strict_types=1);

namespace Saola\Compiler\Compiler;

use Saola\Compiler\Ast\Parser as AstParser;
use Saola\Compiler\Declaration\DeclarationTracker;
use Saola\Compiler\Directive\BindingDirectiveService;
use Saola\Compiler\Directive\DirectiveParsers;
use Saola\Compiler\Directive\ShowDirectiveHandler;
use Saola\Compiler\Directive\StyleDirectiveHandler;
use Saola\Compiler\Emit\JsEmitter;
use Saola\Compiler\Expr\ExpressionCompiler;
use Saola\Compiler\Hydration\IdMode;
use Saola\Compiler\Style\ScopedStyle;
use Saola\Compiler\Support\Balanced;
use Saola\Compiler\Template\ChildrenSlot;
use Saola\Compiler\Template\ImportParser;
use Saola\Compiler\Template\ImportTagResolver;
use Saola\Compiler\Template\TemplateAnalyzer;
use Saola\Compiler\Template\TemplateProcessor;
use Saola\Compiler\Template\TemplateStructure;

/** Pure-PHP orchestration port of sao2js/main_compiler.py::BladeCompiler. */
final class MainCompiler
{
    private readonly ExpressionCompiler $expressions;
    private readonly DirectiveParsers $parsers;
    private readonly CompilerUtils $utils;
    private readonly WrapperParser $wrapperParser;
    private readonly RegisterParser $registerParser;
    private readonly DeclarationTracker $declarations;
    private readonly TemplateAnalyzer $analyzer;
    private readonly BindingDirectiveService $bindings;
    private string $viewTemplate;
    private bool $isTypescript = false;
    private string $scopeClass = '';
    /** @var array<string,true> */ private array $dataVarNames = [];

    public function __construct(
        ?string $viewTemplate = null,
        private readonly IdMode $idMode = IdMode::Terse,
        ?string $wrapperTemplate = null,
    )
    {
        $this->expressions = new ExpressionCompiler();
        $this->parsers = new DirectiveParsers($this->expressions);
        $this->utils = new CompilerUtils();
        $this->wrapperParser = new WrapperParser($wrapperTemplate);
        $this->registerParser = new RegisterParser();
        $this->declarations = new DeclarationTracker($this->expressions);
        $this->analyzer = new TemplateAnalyzer();
        $this->bindings = new BindingDirectiveService();
        $path = dirname(__DIR__, 2).'/resources/templates/view.js';
        if (!is_file($path)) {
            $path = dirname(__DIR__, 3).'/builder/src/templates/view.js';
        }
        $loaded = $viewTemplate ?? (is_file($path) ? file_get_contents($path) : false);
        if (!is_string($loaded)) {
            throw new \RuntimeException('Could not load compiler view.js template.');
        }
        if (str_ends_with($loaded, "\n")) {
            $loaded = substr($loaded, 0, -1);
        }
        $this->viewTemplate = $loaded;
    }

    public function convertViewPathToFunctionName(string $viewPath): string
    {
        return $viewPath;
    }

    /**
     * Cảnh báo gom được trong lượt compile vừa rồi (tên hàm lạ — xem
     * {@see \Saola\Compiler\Expr\HelperResolver}).
     *
     * `$this->expressions` là instance DÙNG CHUNG được inject xuống mọi
     * processor con, nên gom ở đây là gom đủ.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->expressions->helpers()->warnings();
    }

    public function compileBladeToJs(
        string $bladeCode,
        string $viewName,
        ?string $functionName = null,
        ?string $factoryFunctionName = null,
        ?bool $forceTypescript = null,
    ): string
    {
        $this->scopeClass = ScopedStyle::classFor(ScopedStyle::extract($bladeCode));
        $bladeCode = trim($bladeCode);
        $bladeCode = preg_replace('/@state\b(?=\s*\()/i', '@states', $bladeCode) ?? $bladeCode;
        $functionName ??= $this->convertViewPathToFunctionName($viewName);
        $factoryFunctionName ??= $functionName;

        [$bladeCode, $verbatimBlocks] = $this->protectVerbatim($bladeCode);
        $bladeCode = preg_replace('/@(?:serverside|serverSide|ssr|SSR|useSSR|useSsr)\b[\s\S]*?@end(?:serverside|serverSide|ServerSide|SSR|Ssr|ssr|useSSR|useSsr)\b/i', '', $bladeCode) ?? $bladeCode;
        [$bladeCode, $setupBlocks] = $this->protectScriptSetup($bladeCode);
        // KHÔNG escape backtick toàn cục ở đây. Bản cũ đổi mọi ` thành \` cho ngữ
        // cảnh template literal, nhưng nó sai cả hai đầu:
        //   - văn xuôi: text đi tiếp vào jsTextLiteral (chuỗi nháy đơn), nơi \ lại
        //     thành \\ → người đọc thấy dấu \ thừa (`.sao` hiện ra \`.sao\`);
        //   - biểu thức: template literal lồng trong id `${...}` là JS HỢP LỆ, còn
        //     bản escape thì SyntaxError — hỏng đúng ca nó định bảo vệ.
        // @verbatim và <script setup> vốn đã được bóc ra trước đó nên không liên quan.
        foreach ($setupBlocks as $placeholder => $content) $bladeCode = str_replace($placeholder, $content, $bladeCode);

        $this->registerParser->reset();
        [$wrapperFunction, $wrapperConfigContent] = $this->wrapperParser->parseWrapperFile();
        $bladeCode = preg_replace('/{{--.*?--}}/s', '', $bladeCode) ?? $bladeCode;
        $hasAwait = str_contains($bladeCode, '@await') && (str_contains($bladeCode, '@await(') || preg_match('/@await\s*$/m', $bladeCode) === 1 || preg_match('/@await\s+/', $bladeCode) === 1);
        $hasFetch = str_contains($bladeCode, '@fetch(');

        $registerData = null;
        $assetPattern = '/<script\b[^>]*>|<style\b[^>]*>|<link\b(?=[^>]*\brel\s*=\s*["\'][^"\']*\bstylesheet\b[^"\']*["\'])[^>]*>/i';
        if (preg_match('/<script\s+setup[^>]*>(.*?)<\/script>/is', $bladeCode) === 1 || preg_match($assetPattern, $bladeCode) === 1) {
            $registerData = $this->registerParser->parseRegisterContent($bladeCode, $viewName);
        }
        $this->expressions->setUserMethods(array_keys($this->registerParser->getUserMethodNames()), $viewName);
        $this->isTypescript = $forceTypescript ?? (($registerData['setupLang'] ?? null) === 'typescript');

        $tracked = array_map(static fn ($declaration): array => $declaration->toArray(), $this->declarations->parseAll($bladeCode));
        if (ChildrenSlot::has($bladeCode)) {
            $child = ['name' => '__ONE_CHILDREN_CONTENT__', 'hasDefault' => true, 'value' => "''"];
            $found = false;
            foreach ($tracked as &$declaration) {
                if (in_array($declaration['type'], ['vars', 'props'], true)) {
                    $names = array_column($declaration['variables'], 'name');
                    if (!in_array('__ONE_CHILDREN_CONTENT__', $names, true)) $declaration['variables'][] = $child;
                    $found = true; break;
                }
            }
            unset($declaration);
            if (!$found) array_unshift($tracked, ['type'=>'vars','variables'=>[$child]]);
        }
        $dataDeclarations = $tracked;

        [$wrapperDeclarations, $variableList, $stateDeclarations] = $this->generateWrapperDeclarations($tracked);
        [$extendedView, $extendsExpression, $extendsData] = $this->parsers->parseExtends($bladeCode);
        $varsDeclaration = $this->parsers->parseVars($bladeCode);
        $propsDeclaration = $this->parsers->parseProps($bladeCode);
        if ($propsDeclaration !== '') {
            if ($varsDeclaration !== '' && preg_match('/let\s*\{(.*?)\}\s*=/s', $varsDeclaration, $vm) === 1 && preg_match('/let\s*\{(.*?)\}\s*=/s', $propsDeclaration, $pm) === 1) {
                $varsDeclaration = 'let {'.trim($vm[1]).', '.trim($pm[1]).'} = $$$DATA$$$ || {};';
            } elseif ($varsDeclaration === '') $varsDeclaration = $propsDeclaration;
        }
        if (ChildrenSlot::has($bladeCode)) {
            $varsDeclaration = $varsDeclaration !== ''
                ? (preg_replace('/(let\s*\{)(.*?)(\}\s*=\s*\$\$\$DATA\$\$\$)/s', '$1$2, __ONE_CHILDREN_CONTENT__ = \'\'$3', $varsDeclaration) ?? $varsDeclaration)
                : 'let {__ONE_CHILDREN_CONTENT__ = \'\'} = $$$DATA$$$ || {};';
        }
        $letDeclarations = $this->parsers->parseLetDirectives($bladeCode);
        $constDeclarations = $this->parsers->parseConstDirectives($bladeCode);
        $useStateDeclarations = $this->parsers->parseUseStateDirectives($bladeCode);
        $statesDeclarations = $this->parsers->parseStatesDirectives($bladeCode);
        if ($statesDeclarations !== '') $useStateDeclarations .= ($useStateDeclarations !== '' ? "\n" : '').$statesDeclarations;

        $stateVariables = $this->extractUseStateVariables($useStateDeclarations, $tracked);
        foreach ($variableList as $name) $stateVariables[$name] = true;
        foreach ($tracked as $declaration) if ($declaration['type'] === 'computed') foreach ($declaration['variables'] as $var) $stateVariables[$var['name']] = true;

        $bladeCode = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $bladeCode) ?? $bladeCode;
        $bladeCode = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $bladeCode) ?? $bladeCode;
        $bladeCode = preg_replace('/<link\b(?=[^>]*\brel\s*=\s*["\'][^"\']*\bstylesheet\b[^"\']*["\'])[^>]*>/i', '', $bladeCode) ?? $bladeCode;
        $bladeForAst = $bladeCode;

        $legacyCode = $this->parsers->parseBlockDirectives($bladeCode);
        $legacyCode = $this->parsers->parseEndBlockDirectives($legacyCode);
        $legacyCode = $this->parsers->parseUseBlockDirectives($legacyCode);
        $legacyCode = $this->parsers->parseOnBlockDirectives($legacyCode);
        $fetchConfig = $hasFetch ? $this->parsers->parseFetch($legacyCode) : null;
        [$initFunctions] = $this->parsers->parseInit($legacyCode);
        $viewTypeData = $this->parsers->parseViewType($legacyCode);
        foreach (['setup','import','imports','scope','scoped'] as $type) $legacyCode = preg_replace('/<script\s+'.$type.'[^>]*>.*?<\/script>/is', '', $legacyCode) ?? $legacyCode;
        $legacyCode = preg_replace('/@viewtype\s*\([^)]*\)/i', '', $legacyCode) ?? $legacyCode;
        $legacyCode = preg_replace('/@await\s*(?:\([^)]*\))?\s*/i', '', $legacyCode) ?? $legacyCode;
        $legacyCode = preg_replace('/@fetch\s*\([^)]*\)\s*/i', '', $legacyCode) ?? $legacyCode;
        $legacyCode = preg_replace(['/<blade\b[^>]*>/i','/<\/blade>/i'], '', $legacyCode) ?? $legacyCode;

        $legacyUnresolved = $legacyCode;
        $imports = new ImportParser();
        $componentImports = $imports->parseImports($legacyCode);
        TemplateStructure::validate($legacyCode, $componentImports);
        $legacyCode = $imports->removeImports($legacyCode);
        if ($componentImports !== []) $legacyCode = (new ImportTagResolver($componentImports, 'js'))->resolveTags($legacyCode);
        $templateProcessor = new TemplateProcessor($this->stringKeys($stateVariables), $this->isTypescript, $this->expressions);
        [$templateContent, $sections] = $templateProcessor->processTemplate($legacyCode);
        if ($sections === [] && (str_contains($legacyUnresolved, '@block') || str_contains($legacyUnresolved, '@section'))) {
            [, $sections] = (new TemplateProcessor($this->stringKeys($stateVariables), $this->isTypescript, $this->expressions))->processTemplate($legacyUnresolved);
        }
        $templateContent = $this->restoreVerbatimInTemplate($templateContent, $verbatimBlocks);

        $wrapperConfig = $this->extractWrapperConfig($templateContent);
        if ($wrapperConfig !== null) {
            [$inner, $outerBeforeRaw, $outerAfterRaw] = $this->extractWrapperInnerContent($templateContent);
            $templateContent = $inner;
            $outerBefore = $this->filterDirectivesOnly($outerBeforeRaw);
            $outerAfter = $this->filterDirectivesOnly($outerAfterRaw);
            $templateContent = preg_replace('/__WRAPPER_END__\s*/', '', $templateContent) ?? $templateContent;
            $templateContent = $this->removeWrapperConfigMarker($templateContent);
        } else {
            $outerBefore = $outerAfter = '';
        }

        $sectionsInfo = $this->analyzer->analyzeSectionsInfo($sections, $varsDeclaration, $hasAwait, $hasFetch, $this->stringKeys($stateVariables), $legacyCode);
        $conditionalContent = $this->analyzer->analyzeConditionalStructures($templateContent, $varsDeclaration, $hasAwait, $hasFetch);
        $hasPrerender = $this->calculatePrerenderNeed($hasAwait, $hasFetch, $varsDeclaration, $sectionsInfo, $legacyCode, $useStateDeclarations, $letDeclarations, $constDeclarations);
        $templateContent = $this->bindings->processAllBindingDirectives($templateContent);
        $templateContent = (new StyleDirectiveHandler($this->stringKeys($stateVariables), $this->expressions))->processStyleDirective($templateContent);
        $templateContent = (new ShowDirectiveHandler($this->stringKeys($stateVariables), $this->expressions))->processShowDirective($templateContent);

        [$renderFunction, $prerenderStaticBody] = $this->generateStructuredRender($bladeForAst, $stateVariables, $variableList, $constDeclarations, $extendedView, $extendsExpression, $extendsData, $sectionsInfo, $hasPrerender, $hasAwait, $hasFetch);
        $prerender = (new FunctionGenerators($this->isTypescript))->generatePrerenderFunction($hasAwait, $hasFetch, $varsDeclaration === '' ? '' : '    '.$varsDeclaration."\n", "    \n", $templateContent, $extendedView, $extendsExpression, $extendsData, $sectionsInfo, $conditionalContent, $hasPrerender, $prerenderStaticBody);

        return $this->buildView(
            $viewName, $functionName, $factoryFunctionName, $registerData, $dataDeclarations,
            $wrapperFunction, $wrapperDeclarations, $wrapperConfigContent, $wrapperConfig,
            $stateDeclarations, $variableList, $sections, $sectionsInfo, $templateContent,
            $renderFunction, $prerender, $fetchConfig, $viewTypeData, $varsDeclaration,
            $hasAwait, $hasFetch, $hasPrerender, $extendedView, $extendsExpression, $extendsData,
            $verbatimBlocks,
        );
    }

    /** @return array{string,array<string,string>} */
    private function protectVerbatim(string $code): array
    {
        $blocks=[]; $counter=0;
        $code = preg_replace_callback('/@verbatim\s*(.*?)\s*@endverbatim/is', static function(array $m) use (&$blocks,&$counter): string { $p='__VERBATIM_BLOCK_'.$counter++.'__';$blocks[$p]=$m[1];return$p; }, $code) ?? $code;
        return [$code,$blocks];
    }

    /** @return array{string,array<string,string>} */
    private function protectScriptSetup(string $code): array
    {
        $blocks=[];$counter=0;
        $code=preg_replace_callback('/<script\s+setup[^>]*>.*?<\/script>/is',static function(array$m)use(&$blocks,&$counter):string{$p='__SCRIPT_SETUP_BLOCK_'.$counter++.'__';$blocks[$p]=$m[0];return$p;},$code)??$code;
        return [$code,$blocks];
    }

    /** @param array<string,string> $blocks */
    private function restoreVerbatimInTemplate(string $template, array $blocks): string
    {
        foreach($blocks as $placeholder=>$content){$template=str_replace("'{$placeholder}'","'".$this->jsTextLiteral($content)."'",$template);$template=str_replace($placeholder,$this->escapeTemplateContent($content),$template);}return$template;
    }

    private function escapeTemplateContent(string $content): string
    {
        $content=str_replace(['\\`','\\${'],['__ESCAPED_BACKTICK__','__ESCAPED_DOLLAR_BRACE__'],$content);
        $content=str_replace(['`','${'],['\\`','\\${'],$content);
        return str_replace(['__ESCAPED_BACKTICK__','__ESCAPED_DOLLAR_BRACE__'],['\\\\`','\\\\${'],$content);
    }

    private function jsTextLiteral(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return str_replace(["\\","'","\r","\n"],["\\\\","\\'","","\\n"],$text);
    }

    /** @param list<array<string,mixed>> $tracked @return array<string,true> */
    private function extractUseStateVariables(string $source, array $tracked): array
    {
        $out=[];preg_match_all('/const\s+\[([^,]+),\s*([^\]]+)\]/',$source,$matches,PREG_SET_ORDER);
        foreach($matches as$m)foreach([trim($m[1]),trim($m[2])]as$name)if(preg_match('/^[A-Za-z0-9]+$/',$name)===1)$out[$name]=true;
        foreach($tracked as$decl)if(in_array($decl['type'],['useState','states','const','let'],true))foreach($decl['variables']as$var)if(!empty($var['isUseState']))foreach($var['names']??[]as$name)if(preg_match('/^[A-Za-z0-9]+$/',(string)$name)===1)$out[(string)$name]=true;
        return$out;
    }

    /** @param array<string,true> $stateVariables @param list<string> $variableList @param list<array<string,mixed>> $sectionsInfo */
    /**
     * @return array{string,?string} [thân `render()`, thân `prerender()` cho phần
     *   tĩnh khi có `@await`/`@fetch` — null nếu không có phần nào bị tách ra]
     */
    private function generateStructuredRender(string $code, array $stateVariables, array $variableList, string $constDeclarations, ?string $extendedView, ?string $extendsExpression, ?string $extendsData, array $sectionsInfo, bool $hasPrerender, bool $hasAwait, bool $hasFetch): array
    {
        foreach(['setup','import','imports','scope','scoped']as$type)$code=preg_replace('/<script\s+'.$type.'[^>]*>.*?<\/script>/is','',$code)??$code;
        $code=preg_replace('/@viewtype\s*\([^)]*\)/i','',$code)??$code;
        $code=preg_replace('/@await\s*(?:\([^)]*\))?\s*/i','',$code)??$code;
        $code=preg_replace('/@fetch\s*\([^)]*\)\s*/i','',$code)??$code;
        $code=preg_replace('/@init\s*\n.*?@endinit/is','',$code)??$code;

        $wrappers=$this->findWrappers($code);$ranges=[];
        foreach(['@vars','@data','@props','@let','@const','@useState','@states','@computed']as$directive){
            $offset=0;$pattern='/'.preg_quote($directive,'/').'\s*\(/';
            while(preg_match($pattern,$code,$m,PREG_OFFSET_CAPTURE,$offset)===1){$start=$m[0][1];$open=$start+strlen($m[0][0])-1;[$content,$end]=Balanced::extractParensAt($code,$open);if($content===null){$offset=$open+1;continue;}$inside=false;foreach($wrappers as[$a,$b])if($a<=$start&&$start<$b){$inside=true;break;}if(!$inside)$ranges[]=[$start,$end];$offset=$end;}
        }
        usort($ranges,static fn($a,$b)=>$b[0]<=>$a[0]);foreach($ranges as[$start,$end])$code=substr($code,0,$start).substr($code,$end);
        $code=self::stripOutermostWrapperTags($code,['blade','template']);
        $code=preg_replace('/<style\b[^>]*>.*?<\/style>/is','',$code)??$code;
        $imports=new ImportParser();$mapping=$imports->parseImports($code);$code=$imports->removeImports($code);if($mapping!==[])$code=(new ImportTagResolver($mapping,'js'))->resolveTags($code);
        $code=preg_replace('/@extends\s*\([^)]*\)/i','',$code)??$code;

        $root=(new AstParser($this->stringKeys($stateVariables),$this->expressions))->parse($code);
        $declared=$this->extractDeclaredTemplateVariables($variableList,$constDeclarations,$stateVariables);
        $pre=[];if($hasPrerender&&($hasAwait||$hasFetch))foreach($sectionsInfo as$section)if(empty($section['useVars']))$pre[]=$section['name'];
        $hasExtends=$extendedView!==null||$extendsExpression!==null;
        $expression=$extendedView!==null?"__layout__ + '{$extendedView}'":$extendsExpression;
        $emitter=fn():JsEmitter=>new JsEmitter($this->stringKeys($stateVariables),$this->stringKeys($declared),$this->isTypescript,$this->scopeClass,$this->idMode);
        $body=$emitter()->generate($root,$hasExtends,$expression,$extendsData,null,$pre);
        $lines=array_map(static fn(string$line):string=>trim($line)===''?'':'    '.$line,explode("\n",$body));
        // Phần tĩnh bị `$pre` loại khỏi render() phải được sinh lại ở đây bằng
        // chính các generator đó — trước kia FunctionGenerators tự viết tay
        // `this.section(name, ..., () => '')` nên nội dung block/section tĩnh
        // biến mất khỏi JS.
        $preBody=($pre!==[]&&$hasExtends)?$emitter()->generate($root,$hasExtends,$expression,$extendsData,null,$pre,true):null;
        return ["function () {\n".implode("\n",$lines)."\n}",$preBody];
    }

    /** @return list<array{int,int}> */
    private function findWrappers(string $code): array
    {
        $out=[];foreach(['template','blade','sao:blade']as$tag){$offset=0;$openPattern='/<'.preg_quote($tag,'/').'\b[^>]*>/i';$closePattern='/<\/'.preg_quote($tag,'/').'>/i';while(preg_match($openPattern,$code,$open,PREG_OFFSET_CAPTURE,$offset)===1){$start=$open[0][1];$search=$start+strlen($open[0][0]);$depth=1;$end=null;while($search<strlen($code)&&$depth>0){$hasOpen=preg_match($openPattern,$code,$nextOpen,PREG_OFFSET_CAPTURE,$search)===1;$hasClose=preg_match($closePattern,$code,$nextClose,PREG_OFFSET_CAPTURE,$search)===1;if(!$hasClose)break;$openPos=$hasOpen?$nextOpen[0][1]:PHP_INT_MAX;$closePos=$nextClose[0][1];if($openPos<$closePos){$depth++;$search=$openPos+strlen($nextOpen[0][0]);}else{$depth--;if($depth===0)$end=$closePos+strlen($nextClose[0][0]);$search=$closePos+strlen($nextClose[0][0]);}}if($end!==null){$out[]=[$start,$end];$offset=$end;}else$offset=$start+strlen($open[0][0]);}}return$out;
    }

    /** @param list<string> $variableList @param array<string,true> $stateVariables @return array<string,true> */
    private function extractDeclaredTemplateVariables(array $variableList,string $constDeclarations,array $stateVariables):array
    {
        $out=$stateVariables;foreach($variableList as$name)$out[$name]=true;foreach(explode("\n",$constDeclarations)as$line){$line=trim($line);if(!str_starts_with($line,'const '))continue;if(preg_match('/^const\s*\{([^}]*)\}\s*=.*$/',$line,$m)===1)foreach(explode(',',$m[1])as$part){$name=trim(explode(':',$part,2)[0]);if(preg_match('/^[A-Za-z_]\w*$/',$name)===1)$out[$name]=true;}elseif(preg_match('/^const\s*\[([^\]]*)\]\s*=.*$/',$line,$m)===1)foreach(explode(',',$m[1])as$part){$name=trim($part);if(preg_match('/^[A-Za-z_]\w*$/',$name)===1)$out[$name]=true;}elseif(preg_match('/^const\s+([A-Za-z_]\w*)\s*=.*$/',$line,$m)===1)$out[$m[1]]=true;}return$out;
    }

    private function extractWrapperConfig(string $content):?string
    {
        if(preg_match('/__WRAPPER_CONFIG__\s*=\s*/',$content,$m,PREG_OFFSET_CAPTURE)!==1)return null;$start=$m[0][1]+strlen($m[0][0]);if(($content[$start]??'')!=='{')return null;$end=$this->balancedBraceEnd($content,$start);if($end===null)return null;if(($content[$end]??'')===';')$end++;return substr($content,$start,$end-$start-(($content[$end-1]??'')===';'?1:0));
    }

    /** @return array{string,string,string} */
    private function extractWrapperInnerContent(string $content):array
    {
        if(preg_match('/__WRAPPER_CONFIG__\s*=\s*/',$content,$m,PREG_OFFSET_CAPTURE)!==1)return[$content,'',''];$start=$m[0][1];$configEnd=$m[0][1]+strlen($m[0][0]);if(($content[$configEnd]??'')==='{'){$end=$this->balancedBraceEnd($content,$configEnd);if($end!==null){$configEnd=$end;if(($content[$configEnd]??'')===';')$configEnd++;}}while(isset($content[$configEnd])&&str_contains(" \n\r\t",$content[$configEnd]))$configEnd++;$before=trim(substr($content,0,$start));$marker=strpos($content,'__WRAPPER_END__',$configEnd);if($marker===false)return[substr($content,$configEnd),$before,''];return[substr($content,$configEnd,$marker-$configEnd),$before,trim(substr($content,$marker+strlen('__WRAPPER_END__')))];
    }

    private function removeWrapperConfigMarker(string $content):string
    {
        if(preg_match('/__WRAPPER_CONFIG__\s*=\s*/',$content,$m,PREG_OFFSET_CAPTURE)!==1)return$content;$start=$m[0][1];$open=$start+strlen($m[0][0]);$end=$this->balancedBraceEnd($content,$open);if($end===null)return$content;if(($content[$end]??'')===';')$end++;while(isset($content[$end])&&str_contains(" \n\r\t",$content[$end]))$end++;return substr($content,0,$start).substr($content,$end);
    }

    private function balancedBraceEnd(string $content,int $start):?int
    {
        $depth=0;$quote=null;$length=strlen($content);for($i=$start;$i<$length;$i++){$ch=$content[$i];if(($ch==='"'||$ch==="'")&&($i===0||$content[$i-1]!=='\\')){$quote=$quote===null?$ch:($quote===$ch?null:$quote);}if($quote===null){if($ch==='{')$depth++;elseif($ch==='}'&&--$depth===0)return$i+1;}}return null;
    }

    private function filterDirectivesOnly(string $content):string
    {
        if(trim($content)==='')return'';$parts=[];$offset=0;$pattern='/\$\{(?:App\.Helper\.execute|App\.View\.section|this\.__section|this\.__useBlock|App\.View\.useBlock|this\.__subscribeBlock)\(/';while(preg_match($pattern,$content,$m,PREG_OFFSET_CAPTURE,$offset)===1){$start=$m[0][1];$depth=0;$quote=null;$found=false;for($i=$start+2;$i<strlen($content);$i++){$ch=$content[$i];if($ch==='\\'&&$quote!==null){$i++;continue;}if(in_array($ch,['"',"'",'`'],true)&&($i===0||$content[$i-1]!=='\\'))$quote=$quote===null?$ch:($quote===$ch?null:$quote);if($quote===null){if($ch==='{')$depth++;elseif($ch==='}'){if($depth===0){$parts[]=substr($content,$start,$i-$start+1);$offset=$i+1;$found=true;break;}$depth--;}}}if(!$found)$offset=$start+1;}return implode("\n",$parts);
    }

    /** @param list<array<string,mixed>> $sectionsInfo */
    private function calculatePrerenderNeed(bool$await,bool$fetch,string$vars,array$sectionsInfo,string$source,string$states,string$lets,string$consts):bool
    {
        if(!$await&&!$fetch)return false;$names=[];if(preg_match('/\{([^}]+)\}/',$vars,$m)===1)foreach(explode(',',$m[1])as$part){$name=trim(explode('=',str_replace('$','',$part),2)[0]);if($name!=='')$names[]=$name;}$scan=preg_replace('/@(?:vars|useState|states|let|const|props|computed)\s*\([^)]*\)/i','',$source)??$source;$uses=false;foreach($names as$name)if(preg_match('/\b'.preg_quote($name,'/').'\b/',$scan)===1||preg_match('/\$'.preg_quote($name,'/').'(\b|[^A-Za-z0-9_])/',$scan)===1){$uses=true;break;}$declares=false;foreach([$states,$lets,$consts]as$declaration)foreach($names as$name)if(str_contains($declaration,$name)){$declares=true;break 2;}$sectionUses=false;foreach($sectionsInfo as$section)if(!empty($section['useVars'])){$sectionUses=true;break;}return$uses||$declares||$sectionUses;
    }

    /** @param list<array<string,mixed>> $declarations @return array{string,list<string>,list<array<string,string>>} */
    private function generateWrapperDeclarations(array $declarations):array
    {
        $lines=[$this->isTypescript?'    const __UPDATE_DATA_TRAIT__: any = {};':'    const __UPDATE_DATA_TRAIT__ = {};'];$variables=[];$traits=[];$states=[];$known=$this->collectDeclaredNames($declarations);
        foreach($declarations as$declaration){$type=$declaration['type'];foreach($declaration['variables']as$var){
            if(in_array($type,['vars','props'],true))continue;
            if(in_array($type,['let','const','useState','states'],true)){
                if(!empty($var['isDestructuring'])){
                    if(!empty($var['isUseState'])){if(($info=$this->extractStateInfo($var))!==null){$states[]=$info;array_push($lines,...$this->generateStateRegistrationLines($info));}}
                    elseif(in_array($type,['let','const'],true)){$open=($var['destructuringType']??'array')==='array'?'[':'{';$close=$open==='['?']':'}';$lines[]='    '.($type==='const'?'const':'let').' '.$open.implode(', ',$var['names']).$close.' = '.$var['value'].';';}
                }elseif($type==='let')$lines[]='    let '.$var['name'].(!empty($var['hasDefault'])?' = '.$var['value']:'').';';
                elseif($type==='const'&&!empty($var['hasDefault']))$lines[]='    const '.$var['name'].' = '.$var['value'].';';
                continue;
            }
            if($type==='computed'){$name=$var['name'];preg_match_all('/\$?([A-Za-z_]\w*)/',$var['valuePhp']??'',$m);$deps=[];foreach($m[1]??[]as$dep)if(isset($known[$dep])&&$dep!==$name)$deps[$dep]=true;$deps=array_keys($deps);sort($deps);$depsJson=json_encode($deps,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$lines[]="    let {$name};";$lines[]="    const get\${$name} = __STATE__.__.computed('{$name}', () => {$var['value']}, {$depsJson});";$lines[]="    {$name} = get\${$name}();";if($deps!==[])$lines[]="    __STATE__.__.subscribe(['{$name}'], () => { {$name} = get\${$name}(); });";}
        }
        if(in_array($type,['vars','props'],true)){
            $parts=[];foreach($declaration['variables']as$var){$name=$var['name'];$parts[]=$name.(!empty($var['hasDefault'])?' = '.$var['value']:'');$traits[]=$this->isTypescript?"    __UPDATE_DATA_TRAIT__.{$name} = (__next: any) => { {$name} = __next; updateStateByKey('{$name}', __next); };":"    __UPDATE_DATA_TRAIT__.{$name} = __next => { {$name} = __next; updateStateByKey('{$name}', __next); };";$variables[]=$name;}if($parts!==[])$lines[]='    let {'.implode(', ',$parts).'} = __data__;';foreach($declaration['variables']as$var)$lines[]="    __STATE__.__.register('{$var['name']}', {$var['name']});";}
        }
        array_push($lines,...$traits);$quoted=array_map(static fn($v)=>'"'.$v.'"',$variables);$lines[]='    const __VARIABLE_LIST__'.($this->isTypescript?': any':'').' = ['.implode(', ',$quoted).'];';$this->dataVarNames=array_fill_keys($variables,true);return[implode("\n",$lines),$variables,$states];
    }

    /** @param list<array<string,mixed>> $declarations @return array<string,true> */
    private function collectDeclaredNames(array$declarations):array
    {
        $out=[];foreach($declarations as$declaration)foreach($declaration['variables']as$var){if(!empty($var['isDestructuring'])){if(!empty($var['isUseState'])){if(($info=$this->extractStateInfo($var))!==null)$out[$info['stateKey']]=true;}else foreach($var['names']??[]as$name)$out[$name]=true;}elseif(!empty($var['name']))$out[$var['name']]=true;}return$out;
    }

    /** @param array<string,mixed> $var @return array{stateKey:string,setterName:string,initialValue:string}|null */
    private function extractStateInfo(array$var):?array
    {
        $names=$var['names']??[];if(count($names)<2)return null;$value=(string)($var['value']??'');$initial=preg_match('/useState\s*\(\s*(.+)\s*\)/s',$value,$m)===1?$m[1]:'null';return['stateKey'=>$names[0],'setterName'=>$names[1],'initialValue'=>$initial];
    }

    /** @param array{stateKey:string,setterName:string,initialValue:string} $state @return list<string> */
    private function generateStateRegistrationLines(array$state):array
    {
        $key=$state['stateKey'];$setter=$state['setterName'];$seed=$state['initialValue']!==''?$state['initialValue']:'null';$type=$this->isTypescript?': any':'';return[
            "    const set\${$key} = __STATE__.__.register('{$key}');",
            "    let {$key}{$type} = {$seed};",
            "    const {$setter} = (state{$type}) => {",
            "        {$key} = state;","        set\${$key}(state);","    };",
            "    __STATE__.__.setters.{$setter} = {$setter};","    __STATE__.__.setters.{$key} = {$setter};",
            "    const update\${$key} = (value{$type}) => {","        if(__STATE__.__.canUpdateStateByKey){","            updateStateByKey('{$key}', value);","            {$key} = value;","        }","    };",
        ];
    }

    /** @param list<array<string,mixed>> $declarations */
    private function generatePropsInterface(array$declarations,string$component):string
    {
        if(!$this->isTypescript)return'';$fields=[];$seen=[];foreach($declarations as$decl)if(in_array($decl['type'],['vars','props'],true))foreach($decl['variables']as$var){$name=$var['name']??null;if(!$name||isset($seen[$name]))continue;$seen[$name]=true;$type=!empty($var['hasDefault'])?$this->inferPropType((string)($var['value']??'')):'any';$fields[]="    {$name}?: {$type};";}$body=$fields===[]?'':implode("\n",$fields)."\n";return "/**\n * Props của view — sinh tự động từ @props/@vars, không sửa tay.\n * Optional hết vì khai báo nào cũng có default.\n */\nexport interface {$component}Props {\n{$body}    /** viewId server gán khi hydrate */\n    __SSR_VIEW_ID__?: string;\n    [key: string]: any;\n}\n";
    }

    private function inferPropType(string$value):string
    {
        $v=trim($value);if($v===''||in_array($v,['null','undefined'],true))return'any';if(in_array($v,['true','false'],true))return'boolean';if(str_starts_with($v,'['))return'any[]';if(str_starts_with($v,'{'))return'Record<string, any>';if(preg_match('/^-?\d+(?:\.\d+)?$/',$v)===1)return'number';if(strlen($v)>=2&&$v[0]===$v[strlen($v)-1]&&str_contains('"\'`',$v[0]))return'string';return'any';
    }

    /** @param list<array<string,string>> $states */
    private function generateStateUpdates(array$states):string
    { $out=[];foreach($states as$state)$out[]='update$'.$state['stateKey'].'('.$state['initialValue'].');';return implode("\n            ",$out); }

    /** @param list<array<string,string>> $states */
    private function generateDataStateUpdates(array$states):string
    { $out=[];foreach($states as$state){$clean=preg_replace('/\'[^\']*\'|"[^"]*"|`[^`]*`/','',$state['initialValue'])??'';preg_match_all('/[A-Za-z_$][\p{L}\p{N}_$]*/u',$clean,$m);$deps=[];foreach($m[0]??[]as$name)if(isset($this->dataVarNames[$name]))$deps[$name]=true;if($deps===[])continue;$names=array_keys($deps);sort($names);$conds=array_map(static fn($d)=>"data.hasOwnProperty('{$d}')",$names);$out[]='if ('.implode(' || ',$conds).') { update$'.$state['stateKey'].'('.$state['initialValue'].'); }';}return implode("\n            ",$out); }

    private function convertBladeToTemplateString(string$value):string
    {
        return preg_replace_callback('/\{\{\s*([^}]+)\s*\}\}/',static function(array$m):string{$expr=trim($m[1]);foreach(['asset(','route(','config(','date(']as$prefix)if(str_starts_with($expr,$prefix))return'${App.Helper.escString(App.Helper.'.$expr.')}';return'${App.Helper.escString('.$expr.')}';},$value)??$value;
    }

    /** @param array<string,mixed>|null $registerData @param list<array<string,mixed>> $dataDeclarations @param list<array<string,string>> $stateDeclarations @param list<string> $variableList @param list<mixed> $sections @param list<array<string,mixed>> $sectionsInfo @param array<string,mixed>|null $fetchConfig @param array<string,mixed>|null $viewTypeData @param array<string,string> $verbatimBlocks */
    private function buildView(string$viewName,string$functionName,string$factoryName,?array$registerData,array$dataDeclarations,string$wrapperFunction,string$wrapperDeclarations,string$wrapperConfigContent,?string$wrapperConfig,array$stateDeclarations,array$variableList,array$sections,array$sectionsInfo,string$templateContent,string$renderFunction,string$prerender,?array$fetchConfig,?array$viewTypeData,string$varsDeclaration,bool$hasAwait,bool$hasFetch,bool$hasPrerender,?string$extendedView,?string$extendsExpression,?string$extendsData,array$verbatimBlocks):string
    {
        $sectionsMap=[];foreach($sectionsInfo as$section){$name=(string)($section['name']??'');$sectionsMap[$name]=['type'=>$section['type']??'short','preloader'=>(bool)($section['preloader']??false),'useVars'=>(bool)($section['useVars']??false),'script'=>$section['script']??'{}'];}
        $sectionParts=[];foreach($sectionsMap as$name=>$config){$body='"type":"'.$config['type'].'",'."\n            ".'"preloader":'.$this->bool($config['preloader']).','."\n            ".'"useVars":'.$this->bool($config['useVars']).','."\n            ".'"script":'.$config['script'];$sectionParts[]='        "'.$name.'":{' . "\n            {$body}\n        }";}
        $sectionsJson=$sectionParts===[]?'{}':"{\n".implode(",\n",$sectionParts)."\n    }";
        $long=[];foreach($sectionsInfo as$section){$static=$hasPrerender&&($hasAwait||$hasFetch)&&empty($section['useVars']);if(($section['type']??'short')==='long'&&!$static&&!empty($section['name']))$long[]='"'.$section['name'].'"';}
        $renderLong='['.implode(',',$long).']';
        $renderSections=[];foreach($sectionsInfo as$section)if(str_contains($templateContent,"App.View.section('".($section['name']??'')."'"))$renderSections[]=$section['name'];
        $preSections=[];if($hasPrerender)foreach($sectionsInfo as$section){if(($hasAwait||$hasFetch)&&empty($section['useVars']))$preSections[]=$section['name'];elseif(!empty($section['preloader'])||(($hasAwait||$hasFetch)&&!empty($section['useVars'])))$preSections[]=$section['name'];}
        $renderSectionsJson='['.implode(',',array_map(static fn($x)=>'"'.$x.'"',$renderSections)).']';$preSectionsJson='['.implode(',',array_map(static fn($x)=>'"'.$x.'"',$preSections)).']';

        $hasSuper=($extendedView!==null||$extendsExpression!==null)?'true':'false';$super=$extendedView!==null?"'{$extendedView}'":($extendsExpression??'null');$viewType=(string)($viewTypeData['viewType']??'view');
        $wrapperValue=$wrapperConfig??'{ enable: false, tag: null, follow: true, attributes: {} }';
        if(preg_match('/\bfollow\s*:/',$wrapperValue)===1){if(preg_match('/\bsubscribe\s*:/',$wrapperValue)===1){$wrapperValue=preg_replace('/,?\s*follow\s*:\s*(true|false|\[[^\]]*\]|"[^"]*"|\'[^\']*\'|[^,}]+)\s*,?/','',$wrapperValue)??$wrapperValue;$wrapperValue=preg_replace(['/[,]\s*,/', '/,\s*}/'],[',','}'],$wrapperValue)??$wrapperValue;}else$wrapperValue=preg_replace('/\bfollow\s*:\s*(true|false|\[[^\]]*\]|"[^"]*"|\'[^\']*\'|[^,}]+)/','subscribe: $1',$wrapperValue)??$wrapperValue;}
        $wrapperProps='';if(trim($wrapperConfigContent)!==''){$config=rtrim(trim($wrapperConfigContent),',');$wrapperProps="\n        ".str_replace("\n","\n        ",$config).',';}

        $subscribe=null;if(preg_match('/\bsubscribe\s*:\s*(true|false|\[[^\]]*\])/',$wrapperValue,$sm)===1){if(in_array($sm[1],['true','false'],true))$subscribe=$sm[1]==='true';else{$inner=trim(substr($sm[1],1,-1));$subscribe=$inner===''?[]:array_values(array_filter(array_map(static fn($x)=>trim(trim($x),'"\''),explode(',',$inner)),static fn($x)=>$x!==''));}}
        if($wrapperConfig!==null&&$subscribe===null&&!str_contains($wrapperValue,'subscribe:')){$wrapperValue=preg_replace('/^\s*\{/','{ subscribe: true,',$wrapperValue,1)??$wrapperValue;$subscribe=true;}
        if($subscribe===null)$subscribeJs=($varsDeclaration===''&&$stateDeclarations===[])?'false':'true';elseif(is_bool($subscribe))$subscribeJs=$this->bool($subscribe);else$subscribeJs=$this->json($subscribe,spaced:true);
        if($subscribe!==null){if(preg_match('/\bsubscribe\s*:/',$wrapperValue)===1)$wrapperValue=preg_replace('/subscribe\s*:\s*[^,}]+/','subscribe: '.$subscribeJs,$wrapperValue,1)??$wrapperValue;else$wrapperValue=preg_replace('/^\s*\{/','{ subscribe: '.$subscribeJs.',',$wrapperValue,1)??$wrapperValue;}

        [$scriptsLine,$stylesLine,$resourcesLine]=$this->buildAssets($registerData);
        $stateUpdates=$this->generateStateUpdates($stateDeclarations);$dataStateUpdates=$this->generateDataStateUpdates($stateDeclarations);$lock=$stateDeclarations===[]?'':'lockUpdateRealState();';
        $setupLang=$registerData['setupLang']??null;$ts=$setupLang==='typescript';$commitParams=$ts?'this: any':'';$dataParam=$ts?'data: any':'data';$updateDataParams=$ts?'this: any, data: any':$dataParam;$itemParams=$ts?'this: any, key: string, value: any':'key, value';
        $setupConfig="superView: {$super},\n        subscribe: {$subscribeJs},\n        fetch: ".($fetchConfig?$this->utils->formatFetchConfig($fetchConfig):'null').",\n        data: __data__,\n        viewId: __VIEW_ID__,\n        path: __VIEW_PATH__,{$scriptsLine},{$stylesLine},{$resourcesLine},\n        commitConstructorData: function({$commitParams}) {\n            // Then update states from data\n            {$stateUpdates}\n            // Finally lock state updates\n            {$lock}\n        },\n        updateVariableData: function({$updateDataParams}) {\n            // Update all variables first\n            for (const key in data) {\n                if (data.hasOwnProperty(key)) {\n                    // Call updateVariableItemData directly from config\n                    if (typeof this.config.updateVariableItemData === 'function') {\n                        this.config.updateVariableItemData.call(this, key, data[key]);\n                    }\n                }\n            }\n            // Re-derive CHỈ state phụ thuộc data — state literal của instance KHÔNG reset\n            {$dataStateUpdates}\n            // Finally lock state updates\n            {$lock}\n        },\n        updateVariableItemData: function({$itemParams}) {\n            this.data[key] = value;\n            if (typeof __UPDATE_DATA_TRAIT__[key] === \"function\") {\n                __UPDATE_DATA_TRAIT__[key](value);\n            }\n        },\n        prerender: {$prerender},\n        render: {$renderFunction}";

        $config="hasSuperView: {$hasSuper},\n    viewType: '{$viewType}',\n    sections: {$sectionsJson},\n    wrapperConfig: {$wrapperValue},{$wrapperProps}\n    hasAwaitData: ".$this->bool($hasAwait).",\n    hasFetchData: ".$this->bool($hasFetch).",\n    usesVars: ".$this->bool($varsDeclaration!=='').",\n    hasSections: ".$this->bool($sections!==[]).",\n    hasSectionPreload: ".$this->bool($this->anyPreloader($sectionsInfo)).",\n    hasPrerender: ".$this->bool($hasPrerender).",\n    renderLongSections: {$renderLong},\n    renderSections: {$renderSectionsJson},\n    prerenderSections: {$preSectionsJson}";

        $out=$this->viewTemplate;$namespace='';$viewParts=explode('.',$viewName);if(count($viewParts)>1){array_pop($viewParts);$namespace=implode('.',$viewParts).'.';}
        $out=str_replace(['[COMPONENT_NAME]','[FACTORY_FUNCTION_NAME]',"const __VIEW_PATH__ = 'admin.pages.users';","const __VIEW_NAMESPACE__ = 'admin.pages.';","const __VIEW_TYPE__ = 'view';",'[VIEW_CONFIG_PLACEHOLDER]'],[$functionName,$factoryName,"const __VIEW_PATH__ = '{$viewName}';","const __VIEW_NAMESPACE__ = '{$namespace}';","const __VIEW_TYPE__ = '{$viewType}';",$config],$out);
        $raw=($wrapperFunction!==''?$wrapperFunction."\n":'').($wrapperDeclarations!==''?$wrapperDeclarations."\n":'');$wrapperContent=$this->indentNonEmpty($raw,'    ');
        $out=str_replace('[COMPONENT_DECLARE_VARIABLES_AND_STATES]',$wrapperContent,$out);
        $interface=$this->generatePropsInterface($dataDeclarations,$functionName);$out=$interface!==''?str_replace('[COMPONENT_PROPS_INTERFACE]',$interface,$out):str_replace(["[COMPONENT_PROPS_INTERFACE]\n",'[COMPONENT_PROPS_INTERFACE]'],'',$out);
        $lifecycle=(string)($registerData['lifecycle']??'');$user=trim($lifecycle);if(str_starts_with($user,'{')&&str_ends_with($user,'}'))$user=trim(substr($user,1,-1));$user=$this->reindentBase($user,4,12);
        $out=str_replace('[USER_DEFINED_PROPERTIES_PLACEHOLDER]',$user,$out);$out=str_replace('[VIEW_SETUP_CONFIG_PLACEHOLDER]',$this->reindentBase($setupConfig,8,12),$out);

        [$imports,$contents]=$this->splitSetupScript((string)($registerData['setupContent']??''));$out=trim($imports)!==''?str_replace('[COMPONENT_IMPORTS]',$imports,$out):str_replace(["[COMPONENT_IMPORTS]\n",'[COMPONENT_IMPORTS]'],'',$out);$out=trim($contents)!==''?str_replace('[COMPONENT_SCRIPT_CONTENTS]',$contents,$out):str_replace(["[COMPONENT_SCRIPT_CONTENTS]\n",'[COMPONENT_SCRIPT_CONTENTS]'],'',$out);
        foreach($verbatimBlocks as$placeholder=>$content){$out=str_replace("'{$placeholder}'","'".$this->jsTextLiteral($content)."'",$out);$out=str_replace($placeholder,$this->escapeTemplateContent($content),$out);}
        return $this->isTypescript?$this->processTypeMarkers($out):$this->removeTypeMarkers($out);
    }

    /** @param list<array<string,mixed>> $sections */ private function anyPreloader(array$sections):bool{foreach($sections as$section)if(!empty($section['preloader']))return true;return false;}
    private function bool(bool$value):string{return$value?'true':'false';}
    /** @param array<array-key,mixed> $map @return list<string> */ private function stringKeys(array$map):array{return array_map(static fn($key)=>(string)$key,array_keys($map));}
    private function json(mixed$value,bool$spaced=false):string{$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);return$spaced?str_replace([':',','],[': ', ', '],$json):$json;}

    /** @param array<string,mixed>|null $data @return array{string,string,string} */
    private function buildAssets(?array$data):array
    {
        $resources=[];foreach($data['resources']??[]as$r){$attrs=[];$templates=false;foreach($r['attrs']as$key=>$value){if(is_string($value)&&str_contains($value,'{{')&&str_contains($value,'}}')){$attrs[$key]='`'.$this->convertBladeToTemplateString($value).'`';$templates=true;}else$attrs[$key]=$value;}if($templates){$parts=[];foreach($attrs as$key=>$value)$parts[]='"'.$key.'":'.(is_string($value)&&str_starts_with($value,'`')&&str_ends_with($value,'`')?$value:'"'.$this->pyString($value).'"');$attrsJs='{'.implode(',',$parts).'}';}else$attrsJs=$this->utils->formatAttrs($r['attrs']);$resources[]='{"tag":"'.$r['tag'].'","uuid":"'.$r['uuid'].'","attrs":'.$attrsJs.'}';}$resourcesLine="\n        resources: [".implode(',',$resources).']';
        $scripts=[];foreach($data['scripts']??[]as$s){$parts=['"type":"'.$s['type'].'"'];if($s['type']==='code')$parts[]='"content":'.$this->json($s['content']);else{$src=$s['src'];$parts[]='"src":'.(str_contains($src,'{{')&&str_contains($src,'}}')?'`'.$this->convertBladeToTemplateString($src).'`':'"'.$src.'"');}if(!empty($s['id']))$parts[]='"id":"'.$s['id'].'"';if(!empty($s['className']))$parts[]='"className":"'.$s['className'].'"';if(!empty($s['attributes']))$parts[]='"attributes":'.$this->utils->formatAttributesToJson($s['attributes']);$scripts[]='{'.implode(',',$parts).'}';}$scriptsLine="\n        scripts: [".implode(',',$scripts).']';
        $styles=[];foreach($data['styles']??[]as$s){$parts=['"type":"'.$s['type'].'"'];if($s['type']==='code'){$content=$s['content'];if(!empty($s['scoped']))$content=ScopedStyle::apply($content,$this->scopeClass);$parts[]='"content":"'.str_replace(['"',"\n"],['\\"','\\n'],$content).'"';}else{$href=$s['href'];$parts[]='"href":'.(str_contains($href,'{{')&&str_contains($href,'}}')?'`'.$this->convertBladeToTemplateString($href).'`':'"'.$href.'"');}if(!empty($s['id']))$parts[]='"id":"'.$s['id'].'"';if(!empty($s['className']))$parts[]='"className":"'.$s['className'].'"';if(!empty($s['attributes']))$parts[]='"attributes":'.$this->utils->formatAttributesToJson($s['attributes']);$styles[]='{'.implode(',',$parts).'}';}$stylesLine="\n        styles: [".implode(',',$styles).']';return[$scriptsLine,$stylesLine,$resourcesLine];
    }

    private function pyString(mixed$value):string{return is_bool($value)?($value?'True':'False'):(is_null($value)?'None':(string)$value);}
    private function indentNonEmpty(string$text,string$prefix):string{$out=[];foreach(explode("\n",$text)as$line)$out[]=trim($line)===''?$line:$prefix.$line;return implode("\n",$out);}
    private function reindentBase(string$text,int$from,int$to):string{$out=[];foreach(explode("\n",$text)as$line){if(trim($line)===''){$out[]='';continue;}$leading=strlen($line)-strlen(ltrim($line));$extra=max(0,$leading-$from);$out[]=str_repeat(' ',$to+$extra).ltrim($line);}return implode("\n",$out);}

    /** @return array{string,string} */
    private function splitSetupScript(string$setup):array
    {
        if(trim($setup)==='')return['',''];$lines=explode("\n",$setup."\n\n");$imports=[];$other=[];for($i=0,$n=count($lines);$i<$n;){$line=$lines[$i];$s=trim($line);if(str_starts_with($s,'export default')){if(str_contains($line,'{')){$depth=substr_count($line,'{')-substr_count($line,'}');$i++;while($i<$n&&$depth>0){$depth+=substr_count($lines[$i],'{')-substr_count($lines[$i],'}');$i++;}continue;}$i++;continue;}if(str_starts_with($s,'export interface ')||str_starts_with($s,'interface ')||str_starts_with($s,'export type ')||(str_starts_with($s,'type ')&&str_contains($s,'='))){$imports[]=$line;if(str_contains($line,'{')&&!str_ends_with($s,'}')){$depth=substr_count($line,'{')-substr_count($line,'}');$i++;while($i<$n&&$depth>0){$imports[]=$lines[$i];$depth+=substr_count($lines[$i],'{')-substr_count($lines[$i],'}');$i++;}continue;}if(!str_contains($line,'{')){$i++;continue;}}elseif(str_starts_with($s,'import ')||(str_starts_with($s,'export ')&&!str_starts_with($s,'export default')))$imports[]=$line;elseif($s===''){if($imports!==[]&&$other===[])$imports[]=$line;else$other[]=$line;}else$other[]=$line;$i++;}return[implode("\n",$imports),implode("\n",$other)];
    }

    private function processTypeMarkers(string$code):string{$code=preg_replace('/:\[TYPE:([^\]]+)\]/',': $1',$code)??$code;return preg_replace('/as \[TYPE:([^\]]+)\]/','as $1',$code)??$code;}
    private function removeTypeMarkers(string$code):string{$code=preg_replace('/:\[TYPE:[^\]]+\]/','',$code)??$code;return preg_replace('/\s+as \[TYPE:[^\]]+\]/','',$code)??$code;}

    /**
     * Xoá thẻ bọc NGOÀI CÙNG, giữ nguyên thẻ cùng tên lồng bên trong.
     *
     * Trước đây dùng regex toàn cục `/<\/?template\b[^>]*>/i` nên nó xoá LUÔN
     * `<template>` lồng — mà `<template>` lồng là element HTML thật, sao2blade
     * vẫn cấp hydrate id cho nó. Kết quả: blade có `<template>`=e2 và
     * `<span>`=e21 còn sao2js chỉ thấy `<span>`=e2 ⇒ lệch id, không hydrate được.
     *
     * Chỉ xoá CẶP thẻ khớp nhau theo độ sâu; thẻ đóng lạc không có thẻ mở thì
     * để nguyên (input hỏng — không tự đoán ý).
     *
     * @param list<string> $tags
     */
    private static function stripOutermostWrapperTags(string $code, array $tags): string
    {
        $pairs = [];

        foreach ($tags as $tag) {
            $openPattern = '/<' . preg_quote($tag, '/') . '\b[^>]*>/i';
            $closePattern = '/<\/' . preg_quote($tag, '/') . '\s*>/i';
            $offset = 0;

            while (preg_match($openPattern, $code, $open, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $openStart = $open[0][1];
                $openLength = strlen($open[0][0]);
                $depth = 1;
                $search = $openStart + $openLength;
                $closeStart = null;
                $closeLength = 0;

                while ($depth > 0 && $search < strlen($code)) {
                    $hasOpen = preg_match($openPattern, $code, $nextOpen, PREG_OFFSET_CAPTURE, $search) === 1;
                    $hasClose = preg_match($closePattern, $code, $nextClose, PREG_OFFSET_CAPTURE, $search) === 1;

                    if (! $hasClose) {
                        break;
                    }

                    if ($hasOpen && $nextOpen[0][1] < $nextClose[0][1]) {
                        $depth++;
                        $search = $nextOpen[0][1] + strlen($nextOpen[0][0]);
                        continue;
                    }

                    $depth--;
                    $closeStart = $nextClose[0][1];
                    $closeLength = strlen($nextClose[0][0]);
                    $search = $closeStart + $closeLength;
                }

                if ($depth !== 0 || $closeStart === null) {
                    // Không có thẻ đóng khớp — bỏ qua thẻ mở này, tìm tiếp
                    $offset = $openStart + $openLength;
                    continue;
                }

                $pairs[] = [$openStart, $openLength];
                $pairs[] = [$closeStart, $closeLength];

                // Nhảy qua TRỌN cặp vừa khớp. Không nhảy thì sau khi xoá cặp
                // ngoài, thẻ lồng bên trong lại thành "ngoài cùng" và bị xoá
                // theo — đúng cái bug đang sửa.
                $offset = $closeStart + $closeLength;
            }
        }

        // Xoá từ cuối về đầu để offset đã ghi không bị dịch
        usort($pairs, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        foreach ($pairs as [$start, $length]) {
            $code = substr($code, 0, $start) . substr($code, $start + $length);
        }

        return $code;
    }
}
