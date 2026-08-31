<?php
declare(strict_types=1);
namespace Saola\Compiler\Directive\Builtin;
final class PropsDirective extends ParserBackedDirective { public function name(): string { return 'props'; } public function parse(string $source): mixed { return $this->parsers->parseProps($source); } }
