<?php
declare(strict_types=1);
namespace Saola\Compiler\Directive\Builtin;
final class FetchDirective extends ParserBackedDirective { public function name(): string { return 'fetch'; } public function parse(string $source): mixed { return $this->parsers->parseFetch($source); } }
