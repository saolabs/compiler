<?php

declare(strict_types=1);

namespace Saola\Compiler\Template;

/** Port của sao2js/template_analyzer.py. */
final class TemplateAnalyzer
{
    private const JS_FUNCTION_PREFIX = 'App.Helper';

    /**
     * @param list<string> $sections
     * @param iterable<string>|null $stateVariables
     * @return list<array{name: string, type: string, useVars: bool, preloader: bool, htmlContent?: string}>
     */
    public function analyzeSectionsInfo(
        array $sections,
        ?string $varsDeclaration,
        bool $hasAwait,
        bool $hasFetch,
        ?iterable $stateVariables = null,
        ?string $bladeCode = null,
    ): array {
        if ($sections === []) {
            return [];
        }

        $varNames = $this->extractVarNames($varsDeclaration);
        if ($stateVariables !== null) {
            foreach ($stateVariables as $name) {
                if (! in_array($name, $varNames, true)) {
                    $varNames[] = $name;
                }
            }
        }

        $byName = [];
        foreach ($sections as $section) {
            $prefix = preg_quote(self::JS_FUNCTION_PREFIX, '~');
            if (preg_match('~(?:' . $prefix . '\.section|this\.__section|this\.__block)\([\'\"]([^\'\"]+)[\'\"]~', $section, $match) !== 1) {
                continue;
            }

            $name = $match[1];
            $type = str_contains($section, '`') ? 'long' : 'short';
            $useVars = false;
            if ($varNames !== []) {
                $stripped = preg_replace('~\'[^\']*\'|"[^"]*"~', '', $section) ?? $section;
                foreach ($varNames as $varName) {
                    if (preg_match('~\b' . preg_quote($varName, '~') . '\b~', $stripped) === 1) {
                        $useVars = true;
                        break;
                    }
                }
            }

            $htmlContent = null;
            if ($bladeCode !== null && $bladeCode !== '') {
                $pattern = '~@block\([\'\"]' . preg_quote($name, '~') . '[\'\"][^)]*\)\s*(.*?)\s*@endblock~s';
                if (preg_match($pattern, $bladeCode, $block) === 1) {
                    $htmlContent = trim($block[1]);
                    $htmlContent = preg_replace('~@startMarker\([^)]*\)\s*~', '', $htmlContent) ?? $htmlContent;
                    $htmlContent = preg_replace('~@endMarker\([^)]*\)\s*~', '', $htmlContent) ?? $htmlContent;
                    $htmlContent = trim($htmlContent);
                }
            }

            $data = [
                'name' => $name,
                'type' => $type,
                'useVars' => $useVars,
                'preloader' => $useVars && ($hasAwait || $hasFetch),
            ];
            if ($htmlContent !== null && $htmlContent !== '') {
                $data['htmlContent'] = $htmlContent;
            }

            if (! isset($byName[$name]) || ($useVars && ! $byName[$name]['useVars'])) {
                $byName[$name] = $data;
            }
        }

        return array_values($byName);
    }

    /** @return array{has_conditional_with_vars: bool, conditional_content: string} */
    public function analyzeConditionalStructures(
        string $templateContent,
        ?string $varsDeclaration,
        bool $hasAwait,
        bool $hasFetch,
    ): array {
        $result = [
            'has_conditional_with_vars' => false,
            'conditional_content' => $templateContent,
        ];
        if ($varsDeclaration === null || $varsDeclaration === '' || (! $hasAwait && ! $hasFetch)) {
            return $result;
        }

        $varNames = $this->extractVarNames($varsDeclaration);
        if ($varNames === []) {
            return $result;
        }

        $conditionalPatterns = [
            '@if\s*\([^)]+\)', '@elseif\s*\([^)]+\)', '@else', '@endif',
            '@switch\s*\([^)]+\)', '@case\s*\([^)]+\)', '@default', '@endswitch',
        ];
        $hasConditionals = false;
        foreach ($conditionalPatterns as $pattern) {
            if (preg_match('~' . $pattern . '~', $templateContent) === 1) {
                $hasConditionals = true;
                break;
            }
        }
        if (! $hasConditionals) {
            return $result;
        }

        foreach ($varNames as $name) {
            $patterns = [
                '\$\{' . $name . '}',
                '\$\{' . self::JS_FUNCTION_PREFIX . '\.escString\(' . $name . '\)}',
                '\$\{' . self::JS_FUNCTION_PREFIX . '\.foreach\(' . $name,
                ', ' . $name . '\)',
                '\(' . $name . '\)',
                ', ' . $name,
                ' ' . $name,
            ];
            foreach ($patterns as $pattern) {
                if (preg_match('~' . $pattern . '~', $templateContent) === 1) {
                    $result['has_conditional_with_vars'] = true;
                    break 2;
                }
            }
        }

        return $result;
    }

    /** @return list<string> */
    private function extractVarNames(?string $declaration): array
    {
        if ($declaration === null || $declaration === '' || preg_match('/let\s*\{\s*([^}]+)\s*\}/', $declaration, $match) !== 1) {
            return [];
        }

        $names = [];
        foreach (explode(',', $match[1]) as $part) {
            $names[] = trim(explode('=', $part, 2)[0]);
        }

        return $names;
    }
}
