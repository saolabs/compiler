<?php

declare(strict_types=1);

namespace Saola\Compiler\Hydration;

use Saola\Compiler\Support\PyStr;
use Saola\Compiler\Support\Re;

/**
 * Mã hoá hydrate id — cổng port của compiler/src/common/hydrate_id.py.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  BẤT BIẾN: đây phải là HÀM THUẦN của $baseId.
 * ══════════════════════════════════════════════════════════════════════
 *
 * Bản Python chạy sao2blade và sao2js thành hai tiến trình riêng, mỗi bên tự
 * duyệt cây `.sao` một lần. Chúng chỉ sinh ra cùng một dãy id vì phép mã hoá
 * này thuần: cùng $baseId thì luôn ra cùng kết quả, bất kể ai gọi, gọi lúc nào,
 * đã gọi bao nhiêu lần trước đó.
 *
 * Không được thêm bộ đếm, cache, hay bất kỳ trạng thái nào phụ thuộc thứ tự
 * gọi vào class này. Làm vậy là SSR và CSR lệch id, và triệu chứng chỉ hiện ra
 * ở trình duyệt dưới dạng DOM nhân đôi sau hydrate.
 *
 * @see docs/01-architecture.md §6 — bất biến I2
 */
final class HydrateId
{
    /**
     * element/reactive/output/component/yield/block-outlet dùng BỘ ĐẾM RIÊNG
     * trong cùng một scope, nên số thứ tự một mình không đủ phân biệt — phải
     * giữ lại một ký tự đánh dấu loại.
     *
     * Thứ tự trong mảng có ý nghĩa: khớp theo đúng trình tự này.
     *
     * @var list<array{string, string}>
     */
    private const KIND = [
        ['/^rc-(?:if|switch)-(\d+)$/', 'r'],
        ['/^(?:foreach|for|while)-(\d+)$/', 'l'],
        ['/^case_(\d+)$/', 'k'],
        ['/^output-(\d+)$/', 'o'],
        ['/^component-(\d+)$/', 'c'],
        ['/^yield-(\d+)$/', 'y'],
        ['/^block-outlet$/', 'b'],
    ];

    private const TERSE = '/([erlkocy])(\d+)|(b)/';

    private const TERSE_HEAD = '/^B[a-z0-9_]+?(?=[erlkocy]\d|b(?![a-z]))/';

    private function __construct()
    {
    }

    /**
     * Mã hoá $baseId thành id hydrate cuối cùng.
     */
    public static function hash(string $baseId, IdMode $mode = IdMode::Terse): string
    {
        return match ($mode) {
            IdMode::Raw => $baseId,
            IdMode::Terse => self::terse($baseId),
            IdMode::Compact => self::compact($baseId),
            IdMode::Md5 => substr(md5($baseId), 0, 8),
        };
    }

    /**
     * Rút gọn id cấu trúc, vẫn thuần và không va chạm.
     *
     *   'div-1-h1-2'         → 'e1e2'
     *   'rc-if-1-case_1-p-2' → 'r1k1e2'
     *
     * Tên tag bị bỏ đi được vì mỗi scope chỉ có MỘT bộ đếm element, nên số thứ
     * tự đã đủ phân biệt.
     */
    public static function compact(string $baseId): string
    {
        $parts = explode('-', $baseId);
        $count = count($parts);
        $out = [];
        $i = 0;

        while ($i < $count) {
            $matched = false;

            foreach (self::KIND as [$pattern, $code]) {
                // Thử khớp segment dài dần rồi ngắn dần: 'rc-if-1' phải được
                // nhìn như một segment, không phải 'rc' + 'if' + '1'.
                for ($take = $count - $i; $take > 0; $take--) {
                    $segment = implode('-', array_slice($parts, $i, $take));

                    if (Re::match($pattern, $segment, $m)) {
                        $out[] = $code . ($m[1] ?? '');
                        $i += $take;
                        $matched = true;
                        break;
                    }
                }

                if ($matched) {
                    break;
                }
            }

            if ($matched) {
                continue;
            }

            // block-<name>
            if ($parts[$i] === 'block' && $i + 1 < $count) {
                $out[] = 'B' . $parts[$i + 1];
                $i += 2;
                continue;
            }

            // <tag>-<n> → e<n>
            if ($i + 1 < $count && PyStr::isDigit($parts[$i + 1])) {
                $out[] = 'e' . $parts[$i + 1];
                $i += 2;
                continue;
            }

            $out[] = $parts[$i];
            $i++;
        }

        return implode('', $out);
    }

    /**
     * compact + bỏ chữ 'e' ở element một chữ số.
     *
     *   'Bworkspacee2e3r1k2l1' → 'Bworkspace23r1k2l1'
     *
     * Vẫn là đơn ánh: chữ số trần LUÔN là một bậc element; chỉ số >= 10 đóng
     * bằng '_' nên không nhập nhằng với chuỗi chữ số đứng sau ('r1'+'e2'+'e3'
     * → 'r123' còn 'r12'+'e3' → 'r12_3').
     *
     * block-outlet là 'b' KHÔNG kèm chữ số — bỏ sót nó sẽ làm 'e1' và 'e1b'
     * cùng ra '1' (đã gặp thật khi kiểm 4.030 id).
     */
    public static function terse(string $baseId): string
    {
        $compact = self::compact($baseId);
        $out = [];
        $offset = 0;

        if (Re::match(self::TERSE_HEAD, $compact, $head)) {
            $out[] = $head[0];
            $offset = strlen($head[0]);
        }

        foreach (Re::matchAll(self::TERSE, substr($compact, $offset)) as $m) {
            if (($m[3] ?? '') !== '') {
                $out[] = 'b';
                continue;
            }

            $kind = $m[1];
            $number = $m[2];

            // Segment ĐẦU TIÊN giữ nguyên chữ cái: id còn được dùng trực tiếp
            // làm class CSS (Html.ts phía CSR gọi classList.add(id)), mà class
            // mở đầu bằng chữ số là selector không hợp lệ.
            if ($kind === 'e' && strlen($number) === 1 && $out !== []) {
                $out[] = $number;
            } elseif (strlen($number) === 1) {
                $out[] = $kind . $number;
            } else {
                $out[] = $kind . $number . '_';
            }
        }

        return implode('', $out);
    }

}
