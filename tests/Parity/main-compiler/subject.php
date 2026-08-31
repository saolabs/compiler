#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__.'/../../../vendor/autoload.php';
use Saola\Compiler\Compiler\MainCompiler;
$compiler=new MainCompiler();
while(($raw=fgets(STDIN))!==false){$raw=rtrim($raw,"\r\n");if($raw==='')continue;$c=json_decode($raw,true,512,JSON_THROW_ON_ERROR);try{$value=$compiler->compileBladeToJs($c['code'],$c['view'],$c['functionName'],$c['factoryName']);$result=['ok'=>true,'base64'=>base64_encode($value)];}catch(Throwable$e){if(getenv('SAOLA_PARITY_DEBUG'))fwrite(STDERR,$e."\n");$result=['ok'=>false,'error'=>(new ReflectionClass($e))->getShortName().':'.$e->getMessage()];}echo $c['name']."\t".json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";}
