<?php
declare(strict_types=1);
namespace Saola\Compiler\Directive\Builtin;
final class OnBlockDirective extends ParserBackedDirective { public function name(): string { return 'onBlock'; } public function parse(string $source): mixed { return $this->parsers->parseOnBlockDirectives($source); } }
