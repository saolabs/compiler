<?php

declare(strict_types=1);

namespace Saola\Compiler\Hydration;

/**
 * Cách mã hoá hydrate id.
 *
 * Cổng đối chiếu: khớp `SAOLA_ID_MODE` của compiler Python
 * (compiler/src/common/hydrate_id.py). Mode PHẢI giống nhau giữa lúc compile
 * và lúc app chạy — compile ở mode này rồi hydrate ở mode khác là hỏng toàn bộ.
 */
enum IdMode: string
{
    /** Mặc định. Compact + bỏ chữ 'e' ở element một chữ số. */
    case Terse = 'terse';

    /** Id cấu trúc rút gọn: 'div-1-h1-2' → 'e1e2'. */
    case Compact = 'compact';

    /** Hành vi cũ: 8 ký tự hex đầu của md5. */
    case Md5 = 'md5';

    /** Id cấu trúc đầy đủ, dùng để debug hydration. */
    case Raw = 'raw';

    /**
     * Giá trị không nhận ra sẽ rơi về Md5.
     *
     * Đây KHÔNG phải sự dễ dãi — nó tái tạo đúng hành vi của Python, nơi
     * hydrate_hash() kiểm tra raw/terse/compact rồi mặc định md5 cho mọi giá
     * trị khác. Ném exception ở đây sẽ làm lệch parity với bản cũ.
     */
    public static function fromString(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::Md5;
    }
}
