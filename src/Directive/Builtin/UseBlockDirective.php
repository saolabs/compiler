<?php
declare(strict_types=1);
namespace Saola\Compiler\Directive\Builtin;
final class UseBlockDirective extends ParserBackedDirective { public function name(): string { return 'useBlock'; } public function parse(string $source): mixed { return $this->parsers->parseUseBlockDirectives($source); } }
