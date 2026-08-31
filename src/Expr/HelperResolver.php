<?php

declare(strict_types=1);

namespace Saola\Compiler\Expr;

use Saola\Compiler\Support\LiteralMask;
use Saola\Compiler\Support\Re;

/**
 * Phân giải lời gọi hàm trần thành helper runtime.
 *
 *     count(items)   →  App.Helper.count(items)
 *     route('home')  →  App.View.route('home')
 *     myMethod(x)    →  this.view.myMethod(x)      (nếu có trong <script setup>)
 *     Math.max(a, b) →  không đổi                  (builtin JS)
 *
 * Đây là công việc CHÍNH mà `php_to_js` còn làm với cú pháp Saola hiện đại —
 * phần dịch PHP→JS gần như đã thành pass-through. Tách riêng để ranh giới đó
 * nhìn thấy được. (Từng ghi "bỏ cú pháp PHP cũ thì xoá LegacyPhpSyntax" — SAI:
 * xem docblock của {@see PhpJsBridge}, class đó là cầu nối bắt buộc, không
 * phải đường tương thích ngược.)
 *
 * Port từ common/php_js_converter.py::_add_function_prefixes.
 */
final class HelperResolver
{
    /**
     * Tên method khai báo trong <script setup> của view ĐANG compile.
     *
     * @var array<string, true> Dùng key để tra O(1) — tương đương set của Python
     */
    private array $userMethods = [];

    private string $viewPath = '';

    /**
     * Cảnh báo đã phát, khoá theo "view\0tên" — mỗi tên chỉ cảnh báo một lần
     * cho mỗi view, tránh spam khi cùng một hàm lạ xuất hiện nhiều chỗ.
     *
     * @var array<string, true>
     */
    private array $warnedUnknown = [];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * Nạp ngữ cảnh của view sắp compile.
     *
     * PHẢI gọi cho MỖI view, kể cả view không có <script setup> (truyền mảng
     * rỗng). Bản Python dùng một singleton module-level và đã từng rò method
     * của view trước sang view sau — xem FIX(F3) trong php_js_converter.py.
     * Ở đây instance là per-compile nên rủi ro đó không còn, nhưng vẫn giữ API
     * tường minh để chỗ gọi không phải đoán.
     *
     * @param list<string> $methodNames
     */
    public function setUserMethods(array $methodNames, string $viewPath = ''): void
    {
        $this->userMethods = [];

        foreach ($methodNames as $name) {
            $this->userMethods[$name] = true;
        }

        $this->viewPath = $viewPath;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Gắn tiền tố cho lời gọi hàm trần trong biểu thức.
     *
     * Tên hàm nằm TRONG string literal là dữ liệu hiển thị, không phải lời gọi
     * hàm — che đi trước khi thay thế. Không che thì trang docs viết
     * `source="@bind(name)"` sẽ compile ra `"@App.Helper.bind(name)"`; lỗi này
     * đã đi vào production tại
     * resources/js/saola/web/views/modules/demo/index.ts:488.
     *
     * CHỈ che nháy đơn và nháy kép. Template literal (backtick) để nguyên, vì
     * `${...}` bên trong nó là biểu thức thật và vẫn cần gắn tiền tố.
     */
    public function resolve(string $expr): string
    {
        $mask = new LiteralMask('FN_STR');

        return $mask->unmask($this->prefixBareCalls($mask->mask($expr)));
    }

    /**
     * Chỉ phân giải method của view, không đổi helper/tên hàm lạ.
     *
     * Event arrow viết tay (`() => save(item)`) đã là JavaScript hợp lệ nên
     * không được đưa lại qua toàn bộ ExpressionCompiler. Tuy vậy, method khai
     * trong `<script setup>` vẫn phải gọi qua `this.view`, giống mọi biểu thức
     * khác. API hẹp này cho phép EventDirectiveProcessor thực hiện đúng phần
     * đó mà không làm thay đổi contract cũ của các tên hàm chưa biết.
     */
    public function resolveUserMethodCalls(string $expr): string
    {
        if ($this->userMethods === []) {
            return $expr;
        }

        $mask = new LiteralMask('VIEW_METHOD_STR');
        $masked = $mask->mask($expr);

        foreach ($this->userMethods as $name => $_) {
            $masked = Re::replace(
                '/(?<![\w.])(' . preg_quote($name, '/') . ')\s*\(/',
                'this.view.${1}(',
                $masked,
            );
        }

        return $mask->unmask($masked);
    }

    /** Giả định string literal ĐÃ được che. */
    private function prefixBareCalls(string $expr): string
    {
        // Lượt 1 — các tên đã biết, gắn tiền tố theo đúng thứ tự bản Python.
        // Lookbehind (?<![\w.]) chặn việc gắn thêm tiền tố vào tên đã có
        // namespace (App.Helper.count) hoặc property của object (obj.count).
        foreach (KnownFunctions::KNOWN as $name) {
            $expr = Re::replace(
                '/(?<![\w.])(' . preg_quote($name, '/') . ')\s*\(/',
                KnownFunctions::namespaceFor($name) . '.${1}(',
                $expr,
            );
        }

        // Lượt 2 — fallback cho mọi lời gọi hàm trần còn lại.
        return Re::replaceCallback(
            '/(?<![\w.])([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/',
            fn (array $m): string => $this->resolveBareCall($m[0], $m[1]),
            $expr,
        );
    }

    private function resolveBareCall(string $whole, string $name): string
    {
        if (KnownFunctions::isJsBuiltin($name)) {
            return $whole;
        }

        // FIX(F3): method khai báo trong <script setup> của CHÍNH view này phải
        // gọi qua this.view — compiled output đã bind chúng lên view. Rơi vào
        // App.Helper là TypeError lúc chạy và giết cả view.
        if (isset($this->userMethods[$name])) {
            return 'this.view.' . $name . '(';
        }

        $this->warnUnknown($name);

        return KnownFunctions::HELPER_NAMESPACE . '.' . $name . '(';
    }

    /**
     * Tên lạ: không phải helper đã biết, không phải method của view, không phải
     * builtin JS. Vẫn compile thành App.Helper.<name> như cũ, nhưng báo lúc
     * compile thay vì đợi tới lúc chạy mới nổ.
     */
    private function warnUnknown(string $name): void
    {
        $key = $this->viewPath . "\0" . $name;

        if (isset($this->warnedUnknown[$key])) {
            return;
        }

        $this->warnedUnknown[$key] = true;

        $where = $this->viewPath === '' ? '' : sprintf(' trong "%s"', $this->viewPath);

        $this->warnings[] = sprintf(
            '[sao2js] Cảnh báo%s: `%s(...)` không khớp method nào trong <script setup> '
            . 'lẫn danh sách helper đã biết — sẽ compile thành `%s.%s(...)`. '
            . 'Nếu đây là method của component, kiểm tra chính tả / đã export trong <script setup> chưa.',
            $where,
            $name,
            KnownFunctions::HELPER_NAMESPACE,
            $name,
        );
    }
}
