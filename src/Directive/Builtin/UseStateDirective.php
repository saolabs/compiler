<?php
declare(strict_types=1);
namespace Saola\Compiler\Directive\Builtin;
final class UseStateDirective extends ParserBackedDirective { public function name(): string { return 'useState'; } public function parse(string $source): mixed { return $this->parsers->parseUseStateDirectives($source); } }
