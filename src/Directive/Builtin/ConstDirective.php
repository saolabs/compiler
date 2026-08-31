<?php
declare(strict_types=1);
namespace Saola\Compiler\Directive\Builtin;
final class ConstDirective extends ParserBackedDirective { public function name(): string { return 'const'; } public function parse(string $source): mixed { return $this->parsers->parseConstDirectives($source); } }
