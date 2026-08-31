<?php

declare(strict_types=1);

namespace Saola\Compiler\Hydration;

use LogicException;

/**
 * Sinh hydrate id đồng nhất cho cả Blade (SSR) lẫn JS (CSR).
 *
 * Id được suy từ VỊ TRÍ của node trong cây, không phải từ nội dung. Nhờ vậy
 * hai bộ phát mã duyệt cùng một cây sẽ nhận được cùng dãy id.
 *
 * Cách dùng:
 *
 *     $gen = new HydrateIdGenerator();
 *     $gen->pushBlock('content');          // vào scope block
 *     $divId = $gen->pushElement('div');   // "block-content-div-1", vào scope con
 *     $gen->nextOutput();                  // "block-content-div-1-output-1"
 *     $gen->popScope();
 *
 * Port từ compiler/src/common/hydrate_id.py — class HydrateIdGenerator.
 *
 * Object này CÓ trạng thái theo thiết kế (bộ đếm theo scope). Vòng đời của nó
 * gói gọn trong một lần compile — tạo mới cho mỗi view, đừng chia sẻ giữa các
 * lần compile. Xem docs/02-public-api.md §5 quy tắc 4.
 */
final class HydrateIdGenerator
{
    /** @var non-empty-list<HydrateIdScope> */
    private array $scopeStack;

    public function __construct()
    {
        $this->scopeStack = [new HydrateIdScope()];
    }

    public function currentScope(): HydrateIdScope
    {
        return $this->scopeStack[count($this->scopeStack) - 1];
    }

    /** Đặt lại về trạng thái ban đầu cho một view mới. */
    public function reset(): void
    {
        $this->scopeStack = [new HydrateIdScope()];
    }

    public function depth(): int
    {
        return count($this->scopeStack);
    }

    // ── Block ─────────────────────────────────────────────────────────

    /** Vào scope @block('name'). Trả về tiền tố của block. */
    public function pushBlock(string $blockName): string
    {
        $prefix = 'block-' . $blockName;
        $this->scopeStack[] = new HydrateIdScope(prefix: $prefix);

        return $prefix;
    }

    // ── HTML element ──────────────────────────────────────────────────

    /**
     * Cấp id cho element nhưng KHÔNG vào scope con.
     * Dùng cho element rỗng (void) hoặc element lá.
     */
    public function nextElement(string $tagName): string
    {
        return $this->currentScope()->nextElementId($tagName);
    }

    /**
     * Cấp id cho element VÀ vào scope con.
     * Dùng cho element có phần tử con.
     */
    public function pushElement(string $tagName): string
    {
        $elementId = $this->currentScope()->nextElementId($tagName);
        $this->scopeStack[] = new HydrateIdScope(prefix: $elementId);

        return $elementId;
    }

    // ── Reactive ──────────────────────────────────────────────────────

    /** Vào scope khối reactive (if/foreach/for/while/switch). */
    public function pushReactive(string $reactiveType): string
    {
        $reactiveId = $this->currentScope()->nextReactiveId($reactiveType);
        $this->scopeStack[] = new HydrateIdScope(prefix: $reactiveId);

        return $reactiveId;
    }

    /**
     * Vào scope một nhánh case trong @if/@switch.
     *
     * $caseNumber: 1 cho nhánh đầu (if/case), 2 cho else/case thứ hai, ...
     */
    public function pushCase(int $caseNumber): string
    {
        $prefix = $this->currentScope()->prefix . '-case_' . $caseNumber;
        $this->scopeStack[] = new HydrateIdScope(prefix: $prefix);

        return $prefix;
    }

    /**
     * Vào scope một lượt lặp.
     *
     * Giữ nguyên tiền tố của scope cha — chỉ số lặp được chèn vào lúc phát mã
     * chứ không nằm trong tiền tố, vì hai phía Blade và JS diễn đạt chỉ số
     * bằng cú pháp khác nhau ($loop->index vs __loopIndex).
     */
    public function pushLoopIteration(string $loopVarJs, ?string $loopVarBlade = null): string
    {
        $prefix = $this->currentScope()->prefix;

        $this->scopeStack[] = new HydrateIdScope(
            prefix: $prefix,
            loopVar: $loopVarJs,
            loopVarBlade: $loopVarBlade,
        );

        return $prefix;
    }

    // ── Output / component / outlet / yield ───────────────────────────

    /** Id cho biểu thức output {{ }}. */
    public function nextOutput(): string
    {
        return $this->currentScope()->nextOutputId();
    }

    /** Id cho @include, KHÔNG vào scope con. */
    public function nextComponent(): string
    {
        return $this->currentScope()->nextComponentId();
    }

    /** Id cho @include có children, VÀ vào scope con. */
    public function pushComponent(): string
    {
        $componentId = $this->currentScope()->nextComponentId();
        $this->scopeStack[] = new HydrateIdScope(prefix: $componentId);

        return $componentId;
    }

    /** Id cho @useBlock/@blockOutlet. */
    public function nextBlockOutlet(): string
    {
        return $this->currentScope()->nextBlockOutletId();
    }

    /** Id cho @yield. */
    public function nextYield(): string
    {
        return $this->currentScope()->nextYieldId();
    }

    // ── Quản lý scope ─────────────────────────────────────────────────

    /**
     * Rời scope hiện tại, quay về scope cha.
     *
     * Trả về null khi đang ở scope gốc — khớp hành vi Python, nơi pop_scope()
     * ở gốc là no-op trả None thay vì lỗi.
     */
    public function popScope(): ?HydrateIdScope
    {
        if (count($this->scopeStack) <= 1) {
            return null;
        }

        $scope = array_pop($this->scopeStack);

        if ($scope === null) {
            throw new LogicException('Stack scope rỗng bất thường');
        }

        return $scope;
    }

    // ── Định dạng id ──────────────────────────────────────────────────

    /**
     * Bọc id cho output JS.
     *
     * Luôn bọc backtick. Bản Python có nhánh rẽ theo dynamic_parts nhưng hai
     * nhánh trả về giá trị y hệt nhau — base id đã chứa sẵn phần tĩnh. Ở đây
     * viết thẳng kết quả thay vì chép lại nhánh chết.
     */
    public function formatJsId(string $baseId): string
    {
        return '`' . $baseId . '`';
    }

    /**
     * Sinh directive @hydrate cho output Blade.
     *
     * Dùng nháy kép khi trong stack có scope vòng lặp, vì id lúc đó chứa nội
     * suy PHP ("{$loop->index}") — nháy đơn sẽ không nội suy.
     */
    public function formatBladeHydrate(string $baseId): string
    {
        foreach ($this->scopeStack as $scope) {
            if ($scope->loopVarBlade !== null) {
                return '@hydrate("' . $baseId . '")';
            }
        }

        return "@hydrate('" . $baseId . "')";
    }
}
