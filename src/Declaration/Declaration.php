<?php

declare(strict_types=1);

namespace Saola\Compiler\Declaration;

/**
 * Một khai báo tìm được trong nguồn, kèm vị trí để giữ đúng thứ tự.
 *
 * `$variables` cố ý là mảng thô chứ không phải object có kiểu: mỗi loại khai
 * báo sinh ra một BỘ KHOÁ KHÁC NHAU (`@computed` có `valuePhp`, `@const` có
 * `isUseState`, phá cấu trúc có `names` thay vì `name`...). Đó là hợp đồng dữ
 * liệu với sao2js, và dựng cây class cho bản ghi không đồng nhất chỉ làm khó
 * việc đối chiếu chứ không làm rõ thêm điều gì.
 *
 * @phpstan-type VariableRecord array<string, mixed>
 */
final class Declaration
{
    /**
     * @param 'vars'|'props'|'let'|'const'|'useState'|'states'|'computed' $type
     * @param int $position Vị trí BYTE trong nguồn ĐÃ LỌC (bỏ script + verbatim)
     * @param list<array<string, mixed>> $variables
     */
    public function __construct(
        public readonly string $type,
        public readonly int $position,
        public readonly string $content,
        public readonly array $variables,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'position' => $this->position,
            'content' => $this->content,
            'variables' => $this->variables,
        ];
    }
}
