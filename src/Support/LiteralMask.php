<?php

declare(strict_types=1);

namespace Saola\Compiler\Support;

/**
 * Che string literal bằng placeholder rồi khôi phục lại.
 *
 * Compiler biến đổi biểu thức bằng regex trên toàn chuỗi. Nội dung BÊN TRONG
 * string literal là dữ liệu hiển thị, không phải mã — phải che đi trước khi
 * biến đổi, khôi phục sau.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  BẤT BIẾN: khôi phục theo thứ tự index GIẢM DẦN.
 * ══════════════════════════════════════════════════════════════════════
 *
 * Nháy đơn được che trước nháy kép, nên một chuỗi nháy kép bọc chuỗi nháy đơn
 * sẽ luôn mang index CAO hơn phần nằm trong nó. Khôi phục xuôi thì placeholder
 * bên trong chỉ lộ ra sau khi con trỏ đã đi qua index của nó — và ở lại vĩnh
 * viễn trong output.
 *
 * Đó là bug có thật, từng đi vào production:
 *   resources/js/saola/web/views/modules/demo/index.ts:571
 *   "source": "&#64;if(status === __STR_LIT_0__)"
 *
 * Class này tồn tại để bất biến đó chỉ được viết MỘT LẦN. Trước đây vòng khôi
 * phục bị chép ở ba nơi và chỉ cần một chỗ quên đảo thứ tự là lỗi quay lại.
 */
final class LiteralMask
{
    /** @var list<string> */
    private array $literals = [];

    /**
     * @param string $prefix Tiền tố placeholder — phải là chuỗi không thể xuất
     *                       hiện tự nhiên trong biểu thức nguồn
     */
    public function __construct(
        private readonly string $prefix,
    ) {
    }

    /**
     * Thay mọi string literal bằng placeholder.
     *
     * Gọi được nhiều lần trên cùng một instance; index tiếp tục tăng, đúng như
     * bản Python dùng chung một danh sách cho cả hai lượt che.
     */
    public function mask(string $expr): string
    {
        $capture = function (array $m): string {
            $placeholder = $this->placeholder(count($this->literals));
            $this->literals[] = $m[0];

            return $placeholder;
        };

        // Nháy đơn TRƯỚC nháy kép — thứ tự này chính là thứ hợp thức hoá quy
        // tắc "index cao = lớp ngoài" mà unmask() dựa vào.
        $expr = Re::replaceCallback("/'[^']*'/", $capture, $expr);

        return Re::replaceCallback('/"[^"]*"/', $capture, $expr);
    }

    /**
     * Khôi phục literal, lớp ngoài trước lớp trong.
     *
     * @param (callable(string): string)|null $transform Biến đổi literal trước
     *        khi ghép lại (dùng cho chuỗi nháy kép PHP → template literal JS)
     */
    public function unmask(string $expr, ?callable $transform = null): string
    {
        for ($i = count($this->literals) - 1; $i >= 0; $i--) {
            $literal = $transform === null
                ? $this->literals[$i]
                : $transform($this->literals[$i]);

            $expr = str_replace($this->placeholder($i), $literal, $expr);
        }

        return $expr;
    }

    public function isEmpty(): bool
    {
        return $this->literals === [];
    }

    private function placeholder(int $index): string
    {
        return '__' . $this->prefix . '_' . $index . '__';
    }
}
