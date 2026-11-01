<?php

declare(strict_types=1);

namespace Saola\Compiler\Expr;

use Saola\Compiler\Support\LiteralMask;
use Saola\Compiler\Support\Re;

/**
 * Phân giải lời gọi hàm trần thành helper runtime.
 *
 *     count(items)   →  App.Helper.count(items)
 *     route('home')  →  App.Router.getURL('home')
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
    private bool $eventContext = false;

    /** Nested event objects keep the same function scope as their handler. */
    public function withEventContext(callable $compile): string
    {
        $previous = $this->eventContext;
        $this->eventContext = true;
        try {
            return $compile();
        } finally {
            $this->eventContext = $previous;
        }
    }
    /**
     * Tên method khai báo trong <script setup> của view ĐANG compile.
     *
     * @var array<string, true> Dùng key để tra O(1) — tương đương set của Python
     */
    private array $userMethods = [];

    private string $viewPath = '';

    /**
     * Helper do package/app khai báo (`Sao::helper`): có ở cả PHP lẫn App.Helper,
     * nên không cảnh báo "tên lạ".
     *
     * @var array<string, true>
     */
    private array $declaredHelpers = [];

    /** App.Helper names loaded from resources/saola/_app/HelperProvider.ts. */
    private array $clientHelpers = [];

    /** @param list<string> $names */
    public function declareHelpers(array $names): void
    {
        foreach ($names as $name) {
            $this->declaredHelpers[$name] = true;
        }
    }

    /** @param list<string> $names */
    public function declareClientHelpers(array $names): void
    {
        foreach ($names as $name) {
            $this->clientHelpers[$name] = true;
        }
    }

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

    /** Kiểm tra lời gọi PHP trong đoạn SSR-only; không tác động tới source. */
    public function inspectPhpOnly(string $source): void
    {
        $tokens = token_get_all('<?php ' . $source);
        $previous = null;
        $count = count($tokens);

        for ($i = 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token) || $token[0] !== T_STRING) {
                if (! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $previous = $token;
                }
                continue;
            }

            $next = $i + 1;
            while ($next < $count && is_array($tokens[$next])
                && in_array($tokens[$next][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $next++;
            }

            $isCall = $next < $count && $tokens[$next] === '(';
            $isDeclarationOrMember = is_array($previous)
                && in_array($previous[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], true);

            if ($isCall && ! $isDeclarationOrMember) {
                $name = $token[1];
                if (! function_exists($name) && ! isset($this->declaredHelpers[$name])) {
                    $key = 'ssr' . "\0" . $this->viewPath . "\0" . $name;
                    if (! isset($this->warnedUnknown[$key])) {
                        $this->warnedUnknown[$key] = true;
                        $where = $this->viewPath === '' ? '' : sprintf(' trong "%s"', $this->viewPath);
                        $this->warnings[] = sprintf(
                            '[sao2ssr] Cảnh báo%s: hàm PHP `%s(...)` không tồn tại trong đoạn SSR-only; compile vẫn tiếp tục.',
                            $where,
                            $name,
                        );
                    }
                }
            }

            $previous = $token;
        }
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
        if ($this->eventContext) return $this->resolveUserMethodCalls($expr);
        $mask = new LiteralMask('FN_STR');
        $masked = $mask->mask($expr);
        $this->checkBareCalls($masked);

        return $mask->unmask($this->prefixBareCalls($masked));
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
        // KHÔNG return sớm khi không có userMethods: `emit` là method có sẵn
        // của View, phải phân giải kể cả ở view không có <script setup>.
        if ($this->userMethods === [] && ! str_contains($expr, 'emit')
            && !str_contains($expr, '__') && !str_contains($expr, 'text')) {
            return $expr;
        }

        $mask = new LiteralMask('VIEW_METHOD_STR');
        $masked = $mask->mask($expr);
        $this->checkBareCalls($masked, true);
        $masked = Re::replace('/(?<![\w.$])(?:__|text)\s*\(/', 'App.Helper.text(', $masked);

        foreach ($this->userMethods as $name => $_) {
            $masked = Re::replace(
                '/(?<![\w.])(' . preg_quote($name, '/') . ')\s*\(/',
                'this.view.${1}(',
                $masked,
            );
        }

        $masked = self::rewriteEmitCalls($masked);

        return $mask->unmask($masked);
    }

    /**
     * `emit(...)` trần → `this.view.emit(...)`.
     *
     * `emit` là method CÓ SẴN của View, chỉ khác method người dùng ở chỗ không
     * ai viết nó ra. Không phân giải thì nó ở lại dạng trần trong closure và
     * nổ ReferenceError đúng lúc người dùng bấm — scope compiled không còn
     * biến `emit` nào kể từ khi bỏ biến ma thuật.
     *
     * Lookbehind chặn `obj.emit(` và `$view.emit(` — hai thứ đã đúng sẵn.
     */
    public static function rewriteEmitCalls(string $expr): string
    {
        return Re::replace('/(?<![\w.$])emit\s*\(/', 'this.view.emit(', $expr);
    }

    /** Giả định string literal ĐÃ được che. */
    private function prefixBareCalls(string $expr): string
    {
        // `route()` là hàm Laravel ở SSR và phải tra route table của Router ở
        // CSR. Nó không thuộc App.View hay App.Helper.
        $expr = Re::replace('/(?<![\w.])route\s*\(/', 'App.Router.getURL(', $expr);

        // Lượt 1 — các tên đã biết, gắn tiền tố theo đúng thứ tự bản Python.
        // Lookbehind (?<![\w.]) chặn việc gắn thêm tiền tố vào tên đã có
        // namespace (App.Helper.count) hoặc property của object (obj.count).
        foreach (KnownFunctions::KNOWN as $name) {
            $expr = Re::replace(
                '/(?<![\w.])(' . preg_quote($name, '/') . ')\s*\(/',
                KnownFunctions::namespaceFor($name) . '.' . ($name === '__' ? 'text' : $name) . '(',
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

        // `emit` là method CÓ SẴN của View, không phải biến ma thuật — phân
        // giải y như method người dùng viết trong <script setup>. Thiếu nhánh
        // này nó rơi vào `App.Helper.emit(...)`, chỉ nổ lúc chạy.
        if ($name === 'emit') {
            return 'this.view.emit(';
        }

        return KnownFunctions::HELPER_NAMESPACE . '.' . $name . '(';
    }

    /**
     * Cảnh báo về các tên hàm trần mà SSR và CSR không thể đồng bộ.
     * PHP có thể cung cấp hàm từ core hoặc package, nên chỉ dùng function_exists
     * thay vì giới hạn vào danh sách compiler. Danh sách KNOWN đại diện cho
     * helper đã có trong runtime client; Sao::helper đại diện helper khai báo
     * đồng bộ ở cả hai phía.
     */
    private function checkBareCalls(string $expr, bool $allowViewMethods = false): void
    {
        Re::replaceCallback(
            '/(?<![\\w.$])([a-zA-Z_][a-zA-Z0-9_]*)\\s*\\(/',
            function (array $m) use ($allowViewMethods): string {
                $name = $m[1];

                if ($name === 'route'
                    || KnownFunctions::isJsBuiltin($name)
                    || KnownFunctions::isViewFunction($name)
                    || ($allowViewMethods && isset($this->userMethods[$name]))) {
                    return $m[0];
                }

                $hasPhp = function_exists($name)
                    || in_array($name, ['isset', 'empty'], true)
                    || isset($this->declaredHelpers[$name]);
                $hasClient = in_array($name, KnownFunctions::KNOWN, true)
                    || in_array($name, KnownFunctions::CLIENT_HELPERS, true)
                    || isset($this->clientHelpers[$name])
                    || isset($this->declaredHelpers[$name]);

                if (! $hasPhp || ! $hasClient) {
                    $this->warnHelperAvailability($name, $hasPhp, $hasClient);
                }

                return $m[0];
            },
            $expr,
        );
    }

    private function warnHelperAvailability(string $name, bool $hasPhp, bool $hasClient): void
    {
        $key = $this->viewPath . "\0" . $name;

        if (isset($this->warnedUnknown[$key])) {
            return;
        }

        $this->warnedUnknown[$key] = true;
        $where = $this->viewPath === '' ? '' : sprintf(' trong "%s"', $this->viewPath);
        $missing = match (true) {
            ! $hasPhp && ! $hasClient => 'không tồn tại ở PHP lẫn App.Helper',
            ! $hasPhp => 'chưa có hàm PHP tương ứng',
            default => 'chưa có hàm App.Helper tương ứng',
        };

        $this->warnings[] = sprintf(
            '[sao2js] Cảnh báo%s: `%s(...)` %s. Biểu thức vẫn được compile; hãy khai báo helper tương ứng nếu cần SSR/CSR đồng bộ.',
            $where,
            $name,
            $missing,
        );
    }

}
