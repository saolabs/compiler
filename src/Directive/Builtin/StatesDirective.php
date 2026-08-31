<?php
declare(strict_types=1);
namespace Saola\Compiler\Directive\Builtin;
final class StatesDirective extends ParserBackedDirective { public function name(): string { return 'states'; } public function parse(string $source): mixed { return $this->parsers->parseStatesDirectives($source); } }
