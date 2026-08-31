<?php
declare(strict_types=1);
namespace Saola\Compiler\Directive\Builtin;
final class EndBlockDirective extends ParserBackedDirective { public function name(): string { return 'endBlock'; } public function parse(string $source): mixed { return $this->parsers->parseEndBlockDirectives($source); } }
