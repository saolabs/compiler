<?php

declare(strict_types=1);

namespace Saola\Compiler\Preprocessor;

use Saola\Compiler\Support\Balanced;
use Saola\Compiler\Support\Re;

/**
 * Lượt 1 của preprocessor: quét khai báo để dựng bảng ký hiệu.
 *
 * Bảng này cho transformer biết định danh nào là biến do người dùng khai báo
 * (cần thêm `$` khi sang PHP) và định danh nào là hàm có sẵn của PHP (giữ
 * nguyên). Không có nó thì `count(items)` sẽ thành `$count($items)`.
 *
 * Port từ compiler/src/preprocessor/symbol-collector.js.
 *
 * Bản JS gộp lưu trữ và phân tích vào một class; ở đây phần lưu trữ nằm trong
 * {@see SymbolTable}. Method `_parseAssignmentList` của bản JS không ai gọi —
 * không port (docs/06-coding-standards.md §5).
 */
final class SymbolCollector
{
    public function __construct(
        private readonly SymbolTable $table = new SymbolTable(),
    ) {
    }

    public function table(): SymbolTable
    {
        return $this->table;
    }

    public function collect(string $content): SymbolTable
    {
        $this->table->reset();

        $this->collectStates($content);
        $this->collectVars($content);
        $this->collectLets($content);
        $this->collectConsts($content);
        $this->collectAssets($content);

        return $this->table;
    }

    /**
     * `@state(count = 0)` / `@states({ count: 0 })` / `@useState($count, 0)`.
     *
     * Mỗi state sinh thêm một setter `setTênState` — transformer cần biết tên
     * đó để không coi lời gọi setter là hàm lạ.
     */
    private function collectStates(string $content): void
    {
        foreach ($this->directiveBodies($content, '/@states?\s*\(/') as $inner) {
            $parseStr = trim($inner);

            if (str_starts_with($parseStr, '{') && str_ends_with($parseStr, '}')) {
                $parseStr = trim(substr($parseStr, 1, -1));
            }

            foreach (Balanced::splitTopLevel($parseStr, ',') as $part) {
                $colonIdx = strpos($part, ':');
                $eqIdx = Balanced::findAssignment($part);

                $name = '';

                if ($colonIdx !== false && ($eqIdx === -1 || $colonIdx < $eqIdx)) {
                    $name = self::stripQuotes(trim(substr($part, 0, $colonIdx)));
                } elseif ($eqIdx !== -1) {
                    $name = self::stripDollar(trim(substr($part, 0, $eqIdx)));
                }

                if ($name !== '') {
                    $this->addStateWithSetter($name, '@states');
                }
            }
        }

        // Cú pháp cũ: @useState($name, value)
        foreach ($this->directiveBodies($content, '/@useState\s*\(/') as $inner) {
            $parts = Balanced::splitTopLevel($inner, ',');

            if ($parts !== []) {
                $this->addStateWithSetter(self::stripDollar(trim($parts[0])), '@useState');
            }
        }
    }

    private function addStateWithSetter(string $name, string $source): void
    {
        $this->table->add($name, new Symbol(SymbolType::State, $source));

        $setter = 'set' . ucfirst($name);
        $this->table->add($setter, new Symbol(SymbolType::Setter, $source, stateOf: $name));
    }

    /** `@vars(a, b)` hoặc `@props({ title: '' })` / `@props(['title' => ''])`. */
    private function collectVars(string $content): void
    {
        foreach ($this->directiveBodies($content, '/@(?:vars|props)\s*\(/') as $inner) {
            $trimmed = trim($inner);

            if (str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{')) {
                $body = trim(substr($trimmed, 1, -1));

                foreach (Balanced::splitTopLevel($body, ',') as $part) {
                    $p = trim($part);

                    // 'key' => v  |  key => v  |  key: v  |  key = v
                    if (Re::match('/^[\'"]?(\w+)[\'"]?\s*(?:=>|:|(?<![=!<>])=)\s*/', $p, $m)) {
                        $this->table->add($m[1], new Symbol(SymbolType::Var, '@vars'));
                    } elseif (Re::match('/^\w+$/', $p)) {
                        $this->table->add($p, new Symbol(SymbolType::Var, '@vars'));
                    }
                }

                continue;
            }

            foreach (Balanced::splitTopLevel($inner, ',') as $part) {
                $name = self::stripDollar(trim($part));

                // `@vars(a = 1)` là dạng ĐANG DÙNG THẬT, không phải @let/@const
                // (chú thích cũ ghi ngược). Bỏ sót nên tên khai kiểu này không
                // vào bảng ký hiệu: tên thường vẫn thoát nhờ nhánh mặc định
                // thêm '$', nhưng tên trùng NO_PREFIX (`event`, `console`,
                // `Date`...) thì rơi mất '$' ⇒ PHP 8 Fatal "Undefined constant".
                // `@vars(a)` trần vốn đã đúng — đây chỉ là cho dạng '=' theo kịp.
                if (str_contains($name, '=')) {
                    $name = trim(substr($name, 0, strpos($name, '=')));
                }

                if ($name !== '' && Re::match('/^\w+$/', $name)) {
                    $this->table->add($name, new Symbol(SymbolType::Var, '@vars'));
                }
            }
        }
    }

    /**
     * `@let(total = price * 2)`.
     *
     * Dùng `strpos('=')` THÔ chứ không phải findAssignment — bản JS làm vậy,
     * nên `@let(a == b)` cắt ở dấu '=' đầu tiên. Giữ nguyên hành vi.
     */
    private function collectLets(string $content): void
    {
        foreach ($this->directiveBodies($content, '/@let\s*\(/') as $inner) {
            $eqIdx = strpos($inner, '=');

            if ($eqIdx === false) {
                continue;
            }

            $this->addAssignment(
                trim(substr($inner, 0, $eqIdx)),
                trim(substr($inner, $eqIdx + 1)),
                '@let',
                SymbolType::Local,
            );
        }
    }

    /** `@const(API = '/api')` hoặc `@const([count, setCount] = useState(0))`. */
    private function collectConsts(string $content): void
    {
        foreach ($this->directiveBodies($content, '/@const\s*\(/') as $inner) {
            $eqIdx = Balanced::findAssignment($inner);

            if ($eqIdx === -1) {
                continue;
            }

            $this->addAssignment(
                trim(substr($inner, 0, $eqIdx)),
                trim(substr($inner, $eqIdx + 1)),
                '@const',
                SymbolType::Constant,
            );
        }
    }

    private function addAssignment(string $lhs, string $rhs, string $source, SymbolType $plainType): void
    {
        if (str_starts_with($lhs, '[') || str_starts_with($lhs, '{')) {
            $this->collectDestructured($lhs, $rhs, $source);

            return;
        }

        $type = self::isFunctionExpression($rhs) ? SymbolType::Func : $plainType;

        $this->table->add(self::stripDollar($lhs), new Symbol($type, $source));
    }

    /**
     * `@asset(logo = 'images/logo.png')` / `@assets({ logo: 'images/logo.png' })`.
     *
     * Không sinh biến runtime: mọi chỗ dùng được transformer bung thẳng thành
     * `asset('<prefix>/<path>')`, chạy được cả SSR lẫn CSR.
     */
    private function collectAssets(string $content): void
    {
        foreach ($this->directiveBodies($content, '/@assets?\s*\(/') as $inner) {
            $parseStr = trim($inner);

            if (str_starts_with($parseStr, '{') && str_ends_with($parseStr, '}')) {
                $parseStr = trim(substr($parseStr, 1, -1));
            }

            foreach (Balanced::splitTopLevel($parseStr, ',') as $part) {
                $p = trim($part);

                if ($p === '') {
                    continue;
                }

                $colonIdx = strpos($p, ':');
                $eqIdx = Balanced::findAssignment($p);

                if ($eqIdx !== -1 && ($colonIdx === false || $eqIdx < $colonIdx)) {
                    $name = substr($p, 0, $eqIdx);
                    $rawValue = substr($p, $eqIdx + 1);
                } elseif ($colonIdx !== false) {
                    $name = substr($p, 0, $colonIdx);
                    $rawValue = substr($p, $colonIdx + 1);
                } else {
                    continue;
                }

                $name = self::stripDollar(self::stripQuotes(trim($name)));
                $assetPath = self::stripQuotes(trim($rawValue));

                if ($name !== '' && Re::match('/^\w+$/', $name) && $assetPath !== '') {
                    $this->table->add($name, new Symbol(SymbolType::Asset, '@asset', assetPath: $assetPath));
                }
            }
        }
    }

    /**
     * `[a, setA] = useState()` hoặc `{host, port} = config`.
     *
     * Khi vế phải là `useState(...)`, tên khớp `^set[A-Z]` được coi là setter
     * còn lại là state — đúng quy ước cặp đôi của React.
     */
    private function collectDestructured(string $lhs, string $rhs, string $source): void
    {
        $inner = substr($lhs, 1, -1);
        $isUseState = Re::match('/\buseState\s*\(/', $rhs);

        foreach (Balanced::splitTopLevel($inner, ',') as $rawName) {
            $name = self::stripDollar(trim($rawName));

            if ($name === '' || ! Re::match('/^\w+$/', $name)) {
                continue;
            }

            $type = match (true) {
                ! $isUseState => SymbolType::Local,
                Re::match('/^set[A-Z]/', $name) => SymbolType::Setter,
                default => SymbolType::State,
            };

            $this->table->add($name, new Symbol($type, $source, pattern: 'destructured'));
        }
    }

    /**
     * Nội dung trong ngoặc của mọi lần directive xuất hiện.
     *
     * @return list<string>
     */
    private function directiveBodies(string $content, string $pattern): array
    {
        $bodies = [];
        $offset = 0;
        $length = strlen($content);

        while ($offset < $length) {
            if (! Re::match($pattern, substr($content, $offset), $m, PREG_OFFSET_CAPTURE)) {
                break;
            }

            $matchStart = $offset + $m[0][1];
            $matchEnd = $matchStart + strlen($m[0][0]);

            // -1: lùi về đúng dấu '(' kết thúc phần khớp
            $inner = Balanced::extractParens($content, $matchEnd - 1);

            if ($inner !== null) {
                $bodies[] = $inner;
            }

            $offset = $matchEnd;
        }

        return $bodies;
    }

    private static function isFunctionExpression(string $value): bool
    {
        $v = trim($value);

        if ($v === '') {
            return false;
        }

        return str_starts_with($v, 'function')
            || Re::match('/^\(.*\)\s*=>/', $v)
            || Re::match('/^\w+\s*=>/', $v);
    }

    private static function stripQuotes(string $value): string
    {
        return Re::replace('/^[\'"]|[\'"]$/', '', $value);
    }

    private static function stripDollar(string $value): string
    {
        return Re::replace('/^\$/', '', $value);
    }
}
