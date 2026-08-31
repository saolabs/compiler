<?php

declare(strict_types=1);

namespace Saola\Compiler\Compiler;

use Saola\Compiler\Hydration\HydrateIdGenerator;

/** The active main compiler uses the AST emitter for render; this class owns the remaining function wrappers. */
final class FunctionGenerators
{
    public function __construct(private readonly bool $isTypescript = false)
    {
    }

    public function generateLoadServerDataFunction(): string
    {
        return 'function(__$spaViewData$__ = {}) {}';
    }

    /** @param list<array<string,mixed>>|null $sectionsInfo @param array<string,mixed>|null $conditionalContent */
    public function generatePrerenderFunction(
        bool $hasAwait,
        bool $hasFetch,
        string $varsLine,
        string $viewIdLine,
        string $templateContent,
        ?string $extendedView = null,
        ?string $extendsExpression = null,
        ?string $extendsData = null,
        ?array $sectionsInfo = null,
        ?array $conditionalContent = null,
        bool $hasPrerender = true,
    ): string {
        if (!$hasPrerender || (!$hasAwait && !$hasFetch)) {
            return "function() {\n    return null;\n}";
        }

        // The structured renderer contract returns a Wrapper skeleton while data is pending.
        // Static sections are registered before extending a layout.
        $actions = [];
        foreach ($sectionsInfo ?? [] as $section) {
            if (!empty($section['useVars'])) {
                continue;
            }
            $name = (string) ($section['name'] ?? '');
            if ($name === '') continue;
            $actions[] = "this.section('{$name}', { type: 'static', contentType: 'html', stateKeys: [] }, () => '');";
        }

        if ($extendedView !== null || $extendsExpression !== null) {
            $layout = $extendedView !== null ? "__layout__ + '{$extendedView}'" : $extendsExpression;
            $data = ($extendsData !== null && $extendsData !== '') ? $extendsData : '{}';
            $body = $actions === [] ? '' : '    '.implode("\n    ", $actions)."\n";
            return "function() {\n{$body}    this.superViewPath = {$layout};\n    return this.extendView(this.superViewPath, {$data});\n}";
        }

        return self::prerenderSkeleton();
    }

    /**
     * Khung prerender khi view CHƯA có dữ liệu (`@await` / `@fetch`).
     *
     * Port nguyên văn `_generate_prerender_skeleton_function` bên Python. Bản
     * PHP trước đây là một bản viết tay xấp xỉ: id `e0-div` thay vì `pr-div-1`,
     * class `one-preloader` thay vì `data-preloader`, thiếu hai dòng khai báo
     * `parentElement`/`parentReactive`, và text `'loading'` thay vì nhánh
     * `this.__text ? ... : 'Loading...'`.
     *
     * Lệch này nằm im vì KHÔNG view nào — thật lẫn test — dùng được `@fetch`:
     * `SourceSplitter` cắt cụt `@fetch(` làm hỏng template trước khi tới đây,
     * nên cả hai bản đều sinh ra rác giống nhau và cổng parity vẫn xanh. Sửa
     * chỗ cắt cụt xong thì lệch này mới lộ ra.
     *
     * KHÔNG có biến thể TypeScript: bản Python luôn phát `(parentElement)`,
     * việc gắn kiểu do placeholder `[TYPE:...]` của view template lo.
     */
    private static function prerenderSkeleton(): string
    {
        // Generator MỚI mỗi lần, đúng như bản Python — luôn ra `pr-div-1`
        $elementId = 'pr-'.(new HydrateIdGenerator())->nextElement('div');

        return "function() {\n"
            ."            let parentElement = this.parentElement;\n"
            ."            let parentReactive = null;\n"
            ."            return this.wrapper((parentElement) => [\n"
            ."                this.html('{$elementId}', 'div', parentElement, "
            ."{ classes: [{ type: 'static', value: 'data-preloader' }], "
            ."attributes: [{ name: 'ref', value: __VIEW_ID__ }, "
            ."{ name: 'data-view-name', value: __VIEW_PATH__ }] }, (parentElement) => [\n"
            ."                    this.text(this.__text ? this.__text('loading') : 'Loading...')\n"
            ."                ])\n"
            ."            ]);\n"
            ."            }";
    }
}
