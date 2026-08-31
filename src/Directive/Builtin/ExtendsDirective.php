<?php
declare(strict_types=1);
namespace Saola\Compiler\Directive\Builtin;
final class ExtendsDirective extends ParserBackedDirective { public function name(): string { return 'extends'; } public function parse(string $source): mixed { return $this->parsers->parseExtends($source); } }
