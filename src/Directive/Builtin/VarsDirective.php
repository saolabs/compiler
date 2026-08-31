<?php
declare(strict_types=1);
namespace Saola\Compiler\Directive\Builtin;
final class VarsDirective extends ParserBackedDirective { public function name(): string { return 'vars'; } public function parse(string $source): mixed { return $this->parsers->parseVars($source); } }
