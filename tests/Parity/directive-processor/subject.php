#!/usr/bin/env php
<?php
declare(strict_types=1); require __DIR__.'/../../../vendor/autoload.php';
use Saola\Compiler\Directive\DirectiveProcessor;

function mutation(DirectiveProcessor $p,string $name,string $source,?string $end=null):array{
    $stack=[];$output=[];$method='process'.ucfirst($name).'Directive';$value=$p->$method($source,$stack,$output);$endValue=null;
    if($end!==null){$endMethod='process'.ucfirst($end).'Directive';$endValue=$p->$endMethod($stack,$output);}
    return ['end'=>$endValue,'output'=>$output,'stack'=>$stack,'value'=>$value];
}
while(($line=fgets(STDIN))!==false){if(trim($line)==='')continue;$case=json_decode($line,true,512,JSON_THROW_ON_ERROR);$source=$case['source'];$p=new DirectiveProcessor();
    $result=[
        'auth'=>$p->processAuthDirective($source),'endauth'=>$p->processEndauthDirective($source),'can'=>$p->processCanDirective($source),'endcan'=>$p->processEndcanDirective($source),
        'csrf'=>$p->processCsrfDirective($source),'method'=>$p->processMethodDirective($source),'error'=>$p->processErrorDirective($source),'enderror'=>$p->processEnderrorDirective($source),
        'hassection'=>$p->processHassectionDirective($source),'endhassection'=>$p->processEndhassectionDirective($source),'unless'=>$p->processUnlessDirective($source),'endunless'=>$p->processEndunlessDirective($source),
        'json'=>$p->processJsonDirective($source),'lang'=>$p->processLangDirective($source),'choice'=>$p->processChoiceDirective($source),'exec'=>$p->processExecDirective($source),'out'=>$p->processOutDirective($source),
        'empty'=>mutation($p,'empty',$source,'endempty'),'isset'=>mutation($p,'isset',$source,'endisset'),'php'=>mutation($p,'php',$source,'endphp'),
        'let'=>mutation($p,'let',$source),'const'=>mutation($p,'const',$source),'usestate'=>mutation($p,'usestate',$source),'wrapper'=>mutation($p,'wrapper',$source,'endwrapper'),
    ];ksort($result);echo json_encode(['name'=>$case['name'],'result'=>$result],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";}
