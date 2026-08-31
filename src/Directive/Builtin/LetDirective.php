<?php
declare(strict_types=1);
namespace Saola\Compiler\Directive\Builtin;
final class LetDirective extends ParserBackedDirective { public function name(): string { return 'let'; } public function parse(string $source): mixed { return $this->parsers->parseLetDirectives($source); } }
