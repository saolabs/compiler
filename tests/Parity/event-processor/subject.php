#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__.'/../../../vendor/autoload.php';
use Saola\Compiler\Directive\EventDirectiveProcessor;
while(($line=fgets(STDIN))!==false){if(trim($line)==='')continue;$c=json_decode($line,true,512,JSON_THROW_ON_ERROR);$h=new EventDirectiveProcessor($c['states']);$r=['directive'=>$h->processEventDirective($c['event'],$c['expression']),'items'=>$h->processEventItems($c['expression']),'split'=>$h->splitByComma($c['expression'])];echo json_encode(['name'=>$c['name'],'result'=>$r],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";}
