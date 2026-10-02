<?php

declare(strict_types=1);

namespace Saola\Compiler\Hydration;

/**
 * Một scope trong cây id hydrate.
 *
 * Mỗi scope giữ bộ đếm RIÊNG cho từng loại con. Đó là lý do id rút gọn không
 * va chạm: trong một scope, số thứ tự đã đủ phân biệt cho mỗi loại, nên chỉ
 * cần thêm một ký tự đánh dấu loại là đủ.
 *
 * Port từ compiler/src/common/hydrate_id.py — class HydrateIdScope.
 */
final class HydrateIdScope
{
    private int $elementCounter = 0;

    private int $reactiveCounter = 0;

    private int $outputCounter = 0;

    private int $componentCounter = 0;

    private int $yieldCounter = 0;

    private int $blockOutletCounter = 0;

    /**
     * @param string      $prefix       Tiền tố id của scope này (vd "block-content", "div-1")
     * @param string|null $loopVar      Biểu thức chỉ số vòng lặp phía JS (vd "__loopIndex")
     * @param string|null $loopVarBlade Biểu thức chỉ số vòng lặp phía Blade (vd "$loop->index")
     */
    public function __construct(
        public readonly string $prefix = '',
        public readonly ?string $loopVar = null,
        public readonly ?string $loopVarBlade = null,
    ) {
    }

    /** Id cho HTML element kế tiếp: "tag-N". */
    public function nextElementId(string $tagName): string
    {
        $this->elementCounter++;

        return $this->withPrefix($tagName . '-' . $this->elementCounter);
    }

    /**
     * Id cho khối reactive kế tiếp.
     *
     * Điều kiện (if/switch) mang tiền tố "rc-"; vòng lặp (foreach/for/while)
     * thì không. Khác biệt này là hợp đồng với runtime, không phải tuỳ ý.
     */
    public function nextReactiveId(string $reactiveType): string
    {
        $this->reactiveCounter++;

        $segment = in_array($reactiveType, ['if', 'switch'], true)
            ? 'rc-' . $reactiveType . '-' . $this->reactiveCounter
            : $reactiveType . '-' . $this->reactiveCounter;

        return $this->withPrefix($segment);
    }

    /** Id cho biểu thức output {{ }} kế tiếp: "output-N". */
    public function nextOutputId(): string
    {
        $this->outputCounter++;

        return $this->withPrefix('output-' . $this->outputCounter);
    }

    /** Id cho @include kế tiếp: "component-N". */
    public function nextComponentId(): string
    {
        $this->componentCounter++;

        return $this->withPrefix('component-' . $this->componentCounter);
    }

    /**
     * Id cho @useBlock/@blockOutlet kế tiếp: "block-outlet-N".
     *
     * Trước đây KHÔNG có bộ đếm, với giả định "mỗi scope chỉ có một block
     * outlet". Giả định đó sai: layout có hai `@useBlock` sinh ra hai id
     * TRÙNG nhau, marker thứ hai không tồn tại trong DOM nên outlet thứ hai
     * không bao giờ mount. Chưa ai gặp vì mọi layout trong repo đúng một
     * outlet (2026-09-03).
     *
     * Thêm số ở đây KHÔNG làm lệch SSR/CSR: Blade và JS cùng đi qua đúng bộ
     * sinh này, nên hai bên dịch chuyển cùng nhau — cổng marker-sync là chỗ
     * chứng minh điều đó.
     */
    public function nextBlockOutletId(): string
    {
        $this->blockOutletCounter++;

        return $this->withPrefix('block-outlet-' . $this->blockOutletCounter);
    }

    /** Id cho @yield kế tiếp: "yield-N". */
    public function nextYieldId(): string
    {
        $this->yieldCounter++;

        return $this->withPrefix('yield-' . $this->yieldCounter);
    }

    private function withPrefix(string $segment): string
    {
        return $this->prefix === ''
            ? $segment
            : $this->prefix . '-' . $segment;
    }
}
