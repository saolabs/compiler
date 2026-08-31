<?php

declare(strict_types=1);

namespace Saola\Compiler\Expr;

use Saola\Compiler\Support\Re;

/**
 * Cửa vào duy nhất để chuyển một biểu thức `.sao` thành biểu thức JS.
 *
 * Port từ common/php_js_converter.py::php_to_js_advanced —
 * `convert_php_expression_to_js` + `_rename_loop_identifier`.
 *
 * Object CÓ trạng thái (ngữ cảnh view qua HelperResolver). Tạo mới cho mỗi lần
 * compile; đừng dùng chung giữa các view. Bản Python dùng singleton
 * module-level và đã từng rò method giữa các view — xem FIX(F3).
 */
final class ExpressionCompiler
{
    private const INC_PLACEHOLDER = '__INC_OPERATOR__';

    private const DEC_PLACEHOLDER = '__DEC_OPERATOR__';

    /** Trần lặp khi dọn dấu '+' thừa trong lời gọi hàm lồng nhau. */
    private const MAX_ITERATIONS = 10;

    private readonly PhpJsBridge $legacy;

    public function __construct(
        private readonly HelperResolver $helpers = new HelperResolver(),
    ) {
        $this->legacy = new PhpJsBridge($this->helpers);
    }

    public function helpers(): HelperResolver
    {
        return $this->helpers;
    }

    /**
     * Nạp tên method <script setup> của view sắp compile.
     *
     * @param list<string> $methodNames
     */
    public function setUserMethods(array $methodNames, string $viewPath = ''): void
    {
        $this->helpers->setUserMethods($methodNames, $viewPath);
    }

    /**
     * Chuyển biểu thức sang JS.
     *
     * Đổi tên `loop` ở ĐÂY chứ không trong convert(): convert() có nhiều nhánh
     * return sớm, vá từng nhánh sẽ sót. Đây là cửa duy nhất mọi chỗ gọi đi qua.
     */
    public function compile(string $expr): string
    {
        return self::renameLoopIdentifier($this->convert($expr));
    }

    /**
     * Cửa vào mức CÂU LỆNH — xử lý khối `foreach` và closure trước khi chuyển
     * biểu thức. Port từ common/php_converter.py::php_to_js.
     *
     * Khác `compile()` ở chỗ nhận cả đoạn mã có cấu trúc, không chỉ một biểu
     * thức đơn.
     */
    public function compileStatement(string $expr): string
    {
        // Bỏ cú pháp `use (...)` của closure PHP — JS bắt biến theo lexical scope
        $expr = Re::replace('/\s+use\s*\([^)]*\)/', '', $expr);

        // Xử lý foreach TRƯỚC khi '=>' bị hiểu thành ':' ở bước sau
        $expr = Re::replaceCallback(
            '/\bforeach\s*\(\s*(.*?)\s*as\s*\$?(\w+)(\s*=>\s*\$?(\w+))?\s*\)(\s*)\{/',
            static function (array $m): string {
                $arrayExpr = trim($m[1]);
                $spaceBeforeBrace = $m[5] ?? ' ';

                // Có `key => value`: biến ĐẦU là key, biến sau là value
                if (($m[3] ?? '') !== '') {
                    return sprintf(
                        '%s.foreach(%s, (%s, %s, __loopIndex, loop) =>%s{',
                        KnownFunctions::HELPER_NAMESPACE,
                        $arrayExpr,
                        $m[4],
                        $m[2],
                        $spaceBeforeBrace,
                    );
                }

                return sprintf(
                    '%s.foreach(%s, (%s, __loopKey, __loopIndex, loop) =>%s{',
                    KnownFunctions::HELPER_NAMESPACE,
                    $arrayExpr,
                    $m[2],
                    $spaceBeforeBrace,
                );
            },
            $expr,
        );

        return $this->compile($expr);
    }

    private function convert(string $expr): string
    {
        if (trim($expr) === '') {
            return "''";
        }

        $expr = trim($expr);

        // Giấu ++ và -- đi: bước 7 bên dưới gộp mọi '++' thành '+' để dọn dấu
        // nối thừa, sẽ ăn nhầm toán tử tăng/giảm thật nếu không giấu trước.
        $expr = str_replace(['++', '--'], [self::INC_PLACEHOLDER, self::DEC_PLACEHOLDER], $expr);

        $early = $this->tryEarlyConcatPath($expr);

        if ($early !== null) {
            return $early;
        }

        // Bước 2 — truy cập property
        $expr = str_replace('->', '.', $expr);

        // Bước 3 — dọn dấu '+' thừa quanh chuỗi trong lời gọi hàm
        $expr = Re::replace('/\(\+([\'"][^\'"]*[\'"])\+\)/', '(${1})', $expr);
        $expr = Re::replace('/(\w+)\(\+([\'"][^\'"]*[\'"])\+\)/', '${1}(${2})', $expr);

        // Bước 4 — `+??+'chuỗi'` phải xử lý TRƯỚC bước 5
        $expr = Re::replace('/\+\?\?\+([\'"][^\'"]*[\'"])/', '??+${1}', $expr);

        // Bước 5 — toán tử null coalescing
        $expr = Re::replace('/\+\?\?\+/', '??', $expr);
        $expr = Re::replace('/\?\?\+\+/', '??', $expr);

        // Bước 6 của bản gốc là một phép thay thế đồng nhất
        // (`re.sub(r'\+(...)\+', r'+\1+')` — thay bằng chính nó), nằm trong ba
        // lớp điều kiện không có tác dụng phụ. Bỏ hẳn: hành vi không đổi.

        // Bước 7 — gộp '+' đôi
        $expr = Re::replace('/\+\+/', '+', $expr);

        // Bước 8 — dọn '+' trong toán tử ba ngôi
        $expr = Re::replace('/\?\s*\+([\'"][^\'"]*[\'"])\+\s*:/', '? ${1} :', $expr);
        $expr = Re::replace('/:\s*\+([\'"][^\'"]*[\'"])\+/', ': ${1}', $expr);

        // Bước 9 — dọn nốt, lặp cho tới khi ổn định (lời gọi hàm lồng nhau)
        $expr = Re::replace('/(\w+)\(\+([\'"][^\'"]*[\'"])\+\)/', '${1}(${2})', $expr);

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $before = $expr;
            $expr = Re::replace('/(\w+)\(\+([\'"][^\'"]*[\'"])\+\)/', '${1}(${2})', $expr);
            $expr = Re::replace('/\(\+([\'"][^\'"]*[\'"])\+\)/', '(${1})', $expr);

            if ($before === $expr) {
                break;
            }
        }

        $expr = Re::replace('/\+([\'"][^\'"]*[\'"])\+/', '${1}', $expr);

        // Bỏ tiền tố biến PHP
        $expr = Re::replace('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', '${1}', $expr);

        // Ép kiểu (array) không có nghĩa trong JS
        $expr = Re::replace('/\(array\)\s+/', '', $expr);

        $expr = $this->legacy->convertComplexStructures($expr);
        $expr = $this->legacy->handleStringConcatenation($expr);
        $expr = $this->helpers->resolve($expr);

        return $this->restoreOperators($expr);
    }

    /**
     * Nhánh nối chuỗi thuần: xử lý '.' TRƯỚC mọi bước khác rồi trả về luôn.
     *
     * Chỉ chạy khi biểu thức có '.' cùng biến/chuỗi, KHÔNG phải lời gọi hàm,
     * KHÔNG có toán tử so sánh hay ba ngôi, và KHÔNG trông giống truy cập
     * property (`a->b` hoặc `a.b`) — vì lúc đó '.' là truy cập chứ không phải
     * nối chuỗi.
     *
     * @return string|null null nghĩa là không thuộc nhánh này, đi tiếp luồng chính
     */
    private function tryEarlyConcatPath(string $expr): ?string
    {
        $hasDotWithOperand = str_contains($expr, '.')
            && (str_contains($expr, '$') || str_contains($expr, '"') || str_contains($expr, "'"));

        if (! $hasDotWithOperand || Re::match('/^[a-zA-Z_][a-zA-Z0-9_]*\s*\(/', trim($expr))) {
            return null;
        }

        if (Re::match('/[=!<>]=?/', $expr) || Re::match('/\?.*:/', $expr)) {
            return null;
        }

        if (
            Re::match('/[a-zA-Z_][a-zA-Z0-9_]*->[a-zA-Z_][a-zA-Z0-9_]*/', $expr)
            || Re::match('/[a-zA-Z_][a-zA-Z0-9_]*\.[a-zA-Z_][a-zA-Z0-9_]*/', $expr)
        ) {
            return null;
        }

        $expr = $this->legacy->handleStringConcatenation($expr);
        $expr = $this->restoreOperators($expr);
        $expr = Re::replace('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', '${1}', $expr);

        return $this->helpers->resolve($expr);
    }

    private function restoreOperators(string $expr): string
    {
        return str_replace([self::INC_PLACEHOLDER, self::DEC_PLACEHOLDER], ['++', '--'], $expr);
    }

    /**
     * `loop` → `__loop`.
     *
     * Biểu thức đi vào đây ĐÃ QUA preprocessor, nơi mọi bí danh loop được gom
     * về `$loop` (tên Laravel dùng ở nhánh Blade). Sau khi bỏ '$' còn lại
     * `loop` — định danh KHÔNG tồn tại trong callback JS, vì sao2js sinh
     * `(item, __loopKey, __loopIndex, __loop) => ...`.
     *
     * Không đổi thì SSR chạy đúng (Laravel cấp `$loop`) còn CSR ném
     * `ReferenceError: loop is not defined` và giết cả view lúc mount.
     *
     * Bỏ qua nội dung TRONG chuỗi: `{{ 'loop' }}` là dữ liệu người dùng, đổi
     * thành `'__loop'` là sửa sai nội dung hiển thị.
     */
    private static function renameLoopIdentifier(string $expr): string
    {
        if (! str_contains($expr, 'loop')) {
            return $expr;
        }

        $out = '';
        $i = 0;
        $length = strlen($expr);

        while ($i < $length) {
            $char = $expr[$i];

            if ($char === '"' || $char === "'" || $char === '`') {
                $j = $i + 1;

                while ($j < $length) {
                    if ($expr[$j] === '\\') {
                        $j += 2;
                        continue;
                    }
                    if ($expr[$j] === $char) {
                        $j++;
                        break;
                    }
                    $j++;
                }

                // Nguyên văn, không đụng
                $out .= substr($expr, $i, min($j, $length) - $i);
                $i = $j;
                continue;
            }

            $j = $i;
            while ($j < $length && $expr[$j] !== '"' && $expr[$j] !== "'" && $expr[$j] !== '`') {
                $j++;
            }

            // CHỈ định danh `loop` đứng độc lập: không đụng `__loop` (đã đúng),
            // không đụng property sau dấu chấm (`x.loop`), không đụng `loopFoo`.
            $out .= Re::replace('/(?<![\w.$])loop(?![\w$])/', '__loop', substr($expr, $i, $j - $i));
            $i = $j;
        }

        return $out;
    }
}
