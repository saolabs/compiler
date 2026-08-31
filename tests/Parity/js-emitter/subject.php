#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__.'/../../../vendor/autoload.php';
use Saola\Compiler\Ast\Parser;
use Saola\Compiler\Emit\JsEmitter;
while(($line=fgets(STDIN))!==false){if(trim($line)==='')continue;$c=json_decode($line,true,512,JSON_THROW_ON_ERROR);$ast=(new Parser($c['states']))->parse($c['source']);$out=(new JsEmitter($c['states'],$c['declared'],$c['typescript'],$c['scopeClass']))->generate($ast,$c['hasExtends'],$c['extendsExpression'],$c['extendsData'],null,$c['prerendered']);echo json_encode(['name'=>$c['name'],'result'=>$out],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";}
