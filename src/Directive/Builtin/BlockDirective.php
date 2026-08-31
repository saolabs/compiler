<?php
declare(strict_types=1);
namespace Saola\Compiler\Directive\Builtin;
final class BlockDirective extends ParserBackedDirective { public function name(): string { return 'block'; } public function parse(string $source): mixed { return $this->parsers->parseBlockDirectives($source); } }
