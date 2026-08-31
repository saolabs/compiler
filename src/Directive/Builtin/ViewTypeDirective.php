<?php
declare(strict_types=1);
namespace Saola\Compiler\Directive\Builtin;
final class ViewTypeDirective extends ParserBackedDirective { public function name(): string { return 'viewType'; } public function parse(string $source): mixed { return $this->parsers->parseViewType($source); } }
