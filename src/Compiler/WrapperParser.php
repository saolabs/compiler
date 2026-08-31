<?php

declare(strict_types=1);

namespace Saola\Compiler\Compiler;

final class WrapperParser
{
    private string $wrapperFunctionContent = '';
    private string $wrapperConfigContent = '';

    public function __construct(private readonly ?string $template = null)
    {
    }

    /** @return array{string, string} */
    public function parseWrapperFile(string $filePath = 'resources/js/templates/wraper.js'): array
    {
        $this->wrapperFunctionContent = '';
        $this->wrapperConfigContent = '';

        if ($this->template !== null) {
            $this->wrapperFunctionContent = $this->betweenMarkers($this->template, '// start wrapper', '// end wrapper');
            $this->wrapperConfigContent = $this->betweenMarkers($this->template, '// start wrapper config', '// end wrapper config');
            return [$this->wrapperFunctionContent, $this->wrapperConfigContent];
        }

        $candidates = [$filePath, dirname(__DIR__, 2).'/resources/templates/wraper.js'];
        $builderRoot = dirname(__DIR__, 3).'/builder/src';
        $candidates[] = $builderRoot.'/templates/wraper.js';

        $found = null;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $found = $candidate;
                break;
            }
        }

        if ($found === null) {
            return ['', ''];
        }

        $content = file_get_contents($found);
        if ($content === false) {
            return ['', ''];
        }

        $this->wrapperFunctionContent = $this->betweenMarkers($content, '// start wrapper', '// end wrapper');
        $this->wrapperConfigContent = $this->betweenMarkers($content, '// start wrapper config', '// end wrapper config');

        return [$this->wrapperFunctionContent, $this->wrapperConfigContent];
    }

    public function getWrapperFunctionContent(): string
    {
        return $this->wrapperFunctionContent;
    }

    public function getWrapperConfigContent(): string
    {
        return $this->wrapperConfigContent;
    }

    private function betweenMarkers(string $content, string $startMarker, string $endMarker): string
    {
        $start = strpos($content, $startMarker);
        if ($start === false) {
            return '';
        }
        $newline = strpos($content, "\n", $start);
        if ($newline === false) {
            return '';
        }
        $end = strpos($content, $endMarker);

        return $end === false ? '' : trim(substr($content, $newline + 1, $end - $newline - 1));
    }
}
