<?php
declare(strict_types=1);
namespace Saola\Compiler\Directive\Builtin;
final class InitDirective extends ParserBackedDirective { public function name(): string { return 'onInit'; } public function parse(string $source): mixed { return $this->parsers->parseInit($source); } }
