<?php

declare(strict_types=1);

namespace Saola\Compiler\Support;

use RuntimeException;

/**
 * Ném khi một lệnh preg_* thất bại.
 *
 * Tồn tại vì preg_* của PHP trả về null/false khi lỗi và KHÔNG ném gì cả —
 * khác hẳn module `re` của Python vốn ném exception. Nếu không bọc lại, một
 * pattern hỏng sẽ đi tiếp trong pipeline dưới dạng null và chỉ lộ ra ở tận
 * output cuối cùng.
 */
final class RegexException extends RuntimeException
{
}
