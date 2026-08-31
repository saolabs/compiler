<?php

declare(strict_types=1);

namespace Saola\Compiler\Preprocessor;

use Saola\Compiler\Support\Balanced;
use Saola\Compiler\Support\Re;

/**
 * Lượt 2 của preprocessor: dịch biểu thức Saola Syntax sang PHP/Blade.
 *
 * Việc chính:
 *  - thêm '$' cho định danh có trong bảng ký hiệu
 *  - `.` thành `->` (truy cập thuộc tính)
 *  - template literal `` `a ${b}` `` thành nối chuỗi PHP
 *  - phân biệt '+' số học với '+' nối chuỗi
 *  - `{k: v}` thành `['k' => v]`
 *  - method kiểu JS (`.length`, `.join()`) thành hàm PHP
 *
 * Port từ compiler/src/preprocessor/expression-transformer.js.
 *
 * Bốn thứ trong bản JS không được port vì là mã chết: `_processTemplateLiterals`
 * và `_isInsideObjectLiteral` không ai gọi; `token.transformedTo` và
 * `token._isProperty` chỉ được ghi, không nơi nào đọc.
 */
final class ExpressionTransformer
{
    /**
     * Tên LoopContext trong `.sao` → đều map sang `$loop` của Laravel.
     *
     * `__loop` là tên tham số callback sao2js sinh ra
     * (`(item, __loopKey, __loopIndex, __loop) => ...`) nên đó là tên người
     * dùng viết. `loop` nhận kèm cho tương thích Blade thuần.
     * PHẢI khớp 1-1 với `_gen_foreach` trong sao2js/render_generator.py.
     */
    private const LOOP_ALIASES = ['__loop', 'loop'];

    /** Directive có đối số là ĐƯỜNG DẪN VIEW → vị trí (0-based) của đối số đó. */
    public const VIEW_PATH_DIRECTIVES = ['extends' => 0, 'include' => 0];

    public const EVENT_DIRECTIVES = [
        'click', 'input', 'change', 'submit', 'keyup', 'keydown',
        'keypress', 'focus', 'blur', 'mouseenter', 'mouseleave', 'mouseover', 'mouseout',
        'dblclick', 'contextmenu', 'wheel', 'scroll', 'resize', 'load',
    ];

    public const BIND_DIRECTIVES = [
        'bind', 'val', 'checked', 'selected', 'disabled', 'required', 'readonly', 'attr',
    ];

    /**
     * Directive tác động lên element, xử lý ở lượt directive.
     *
     * public vì {@see \Saola\Compiler\Directive\DirectiveRegistry} phải biết
     * đúng danh sách này để chặn ghi đè — hai nơi giữ hai bản là chắc chắn
     * lệch, và lệch ở đây nghĩa là người dùng đè được directive lõi mà không bị
     * chặn.
     */
    public const ELEMENT_DIRECTIVES = ['class', 'style', 'exec', 'show', 'hide'];

    /** Định danh KHÔNG thêm '$' dù không có trong bảng ký hiệu. */
    private const NO_PREFIX = [
        'true', 'false', 'null', 'this', 'self', 'parent',
        'event', 'console', 'window', 'document', 'Math', 'JSON',
        'Array', 'Object', 'String', 'Number', 'Boolean', 'Date',
    ];

    /** Global của JS: `Math.max` giữ dấu chấm, không thành `Math->max`. */
    private const JS_GLOBALS = [
        'console', 'Math', 'JSON', 'window', 'document',
        'Object', 'Array', 'String', 'Number', 'Boolean', 'Date',
    ];

    private readonly string $assetPrefix;

    private readonly Tokenizer $tokenizer;

    private ImportAliases $importAliases;

    public function __construct(
        private readonly SymbolTable $symbols,
        string $assetPrefix = 'static/saola/assets/',
    ) {
        // Bảo đảm có '/' ở cuối. Dùng preg thay cho rtrim(): với 'a//' thì JS
        // giữ nguyên 'a//' còn rtrim sẽ cắt thành 'a/'.
        $this->assetPrefix = Re::replace('#/?$#D', '/', $assetPrefix === '' ? 'static/saola/assets/' : $assetPrefix, 1);

        $this->tokenizer = new Tokenizer($this->transformExpression(...));
        $this->importAliases = new ImportAliases();
    }

    public function collectImportAliases(string $content): void
    {
        $this->importAliases = (new ImportAliases())->collect($content);
    }

    // ── Cửa vào ───────────────────────────────────────────────────────

    public function transformExpression(string $expr): string
    {
        if (trim($expr) === '') {
            return $expr;
        }

        return $this->transformTokens($this->tokenizer->tokenize($expr));
    }

    /** `@state(count = 0)` → `@useState($count, 0)`, `@vars(a, b)` → `@vars($a, $b)`, ... */
    public function transformDeclaration(string $declaration): string
    {
        if (Re::match('/^@states?\s*\(([\s\S]*)\)$/', $declaration, $m)) {
            return $this->transformStateDeclaration($m[1]);
        }

        if (Re::match('/^@(?:vars|props)\s*\(([\s\S]*)\)$/', $declaration, $m)) {
            $name = Re::match('/^@(\w+)/', $declaration, $d) ? $d[1] : '';

            return '@' . $name . '(' . $this->transformExpression($m[1]) . ')';
        }

        if (Re::match('/^@let\s*\(([\s\S]*)\)$/', $declaration, $m)) {
            return $this->transformAssignmentDeclaration('@let', $m[1]);
        }

        if (Re::match('/^@const\s*\(([\s\S]*)\)$/', $declaration, $m)) {
            return $this->transformAssignmentDeclaration('@const', $m[1]);
        }

        // @asset/@assets không sinh khai báo runtime — ký hiệu đã thu ở lượt 1,
        // mỗi chỗ dùng được bung thẳng thành asset('...').
        if (Re::match('/^@assets?\s*\(/', $declaration)) {
            return '';
        }

        if (str_starts_with($declaration, '@useState')) {
            return $declaration;
        }

        if (Re::match('/^@import\s*\(([\s\S]*)\)$/', $declaration, $m)) {
            return '@import(' . $this->transformExpression($m[1]) . ')';
        }

        return $declaration;
    }

    /** Dịch cả khối template: `{{ }}`, `{!! !!}`, directive, binding thuộc tính. */
    public function transformTemplate(string $template): string
    {
        // Comment Blade phải giữ NGUYÊN VĂN
        $comments = [];
        $result = Re::replaceCallback('/\{\{--[\s\S]*?--\}\}/', function (array $m) use (&$comments): string {
            $placeholder = '__BLADE_COMMENT_' . count($comments) . '__';
            $comments[] = $m[0];

            return $placeholder;
        }, $template);

        // @verbatim nghĩa là "giữ nguyên văn". Không chặn thì `{{ title }}` trong
        // khối code minh hoạ bị thêm '$' (thành `{{ $title }}`), còn `{{ $title }}`
        // viết sẵn thành `{{ $$title }}` — sai nội dung ở CẢ Blade lẫn JS.
        $verbatim = [];
        $result = Re::replaceCallback('/@verbatim[\s\S]*?@endverbatim/', function (array $m) use (&$verbatim): string {
            $placeholder = '__VERBATIM_RAW_' . count($verbatim) . '__';
            $verbatim[] = $m[0];

            return $placeholder;
        }, $result);

        $result = Re::replaceCallback(
            '/\{\{\s*([\s\S]*?)\s*\}\}/',
            fn (array $m): string => '{{ ' . $this->transformExpression(trim($m[1])) . ' }}',
            $result,
        );

        $result = Re::replaceCallback(
            '/\{!!\s*([\s\S]*?)\s*!!\}/',
            fn (array $m): string => '{!! ' . $this->transformExpression(trim($m[1])) . ' !!}',
            $result,
        );

        $result = $this->transformDirectives($result);
        $result = $this->transformAttributeBindings($result);

        // Placeholder là duy nhất nên str_replace (thay tất cả) tương đương
        // thay-lần-đầu của JS. Bản JS phải dùng replacement dạng HÀM để tránh
        // `$$`/`$&` trong nội dung bị diễn giải; str_replace của PHP không có
        // vấn đề đó.
        foreach ($comments as $i => $comment) {
            $result = str_replace('__BLADE_COMMENT_' . $i . '__', $comment, $result);
        }

        foreach ($verbatim as $i => $block) {
            $result = str_replace('__VERBATIM_RAW_' . $i . '__', $block, $result);
        }

        return $result;
    }

    // ── Binding thuộc tính ────────────────────────────────────────────

    /**
     * `:attr="expr"` và `x-bind:attr="expr"`.
     *
     * KHÔNG đụng binding sự kiện (`@name="expr"`, `x-on:name`) — chúng chạy ở
     * client và phải giữ cú pháp JS.
     */
    private function transformAttributeBindings(string $template): string
    {
        $result = '';
        $inTag = false;
        $currentTag = '';
        $inString = false;

        // Độ sâu ngoặc tròn TRONG thẻ. Không đếm thì '>' của '=>' do directive
        // sinh ra (`@style([ 'w'=> 1 ])`) bị hiểu là đóng thẻ, và mọi thuộc
        // tính phía sau — kể cả `:attr="expr"` — rơi ra ngoài, không được xử
        // lý. Cũng che luôn `@click(a > b)`.
        $parenDepth = 0;
        $stringChar = '';
        $i = 0;
        $length = strlen($template);

        while ($i < $length) {
            $ch = $template[$i];

            if (! $inTag) {
                if ($ch === '<' && isset($template[$i + 1]) && Re::match('/[a-zA-Z\/]/', $template[$i + 1])) {
                    $inTag = true;
                    $parenDepth = 0;
                    $currentTag = Re::match('/^([A-Za-z][\w.-]*)/', substr($template, $i + 1), $tm) ? $tm[1] : '';
                }

                $result .= $ch;
                $i++;
                continue;
            }

            if ($inString) {
                if ($ch === '\\') {
                    $result .= $ch;
                    if (isset($template[$i + 1])) {
                        $result .= $template[$i + 1];
                        $i++;
                    }
                } elseif ($ch === $stringChar) {
                    $inString = false;
                }

                $result .= $ch;
                $i++;
                continue;
            }

            if ($ch === '(') {
                $parenDepth++;
            } elseif ($ch === ')' && $parenDepth > 0) {
                $parenDepth--;
            }

            if ($ch === '>' && $parenDepth === 0) {
                $inTag = false;
                $result .= $ch;
                $i++;
                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $inString = true;
                $stringChar = $ch;
                $result .= $ch;
                $i++;
                continue;
            }

            // `::attr` → `:attr` nguyên văn (lối thoát, không phải binding)
            if ($ch === ':' && ($template[$i + 1] ?? '') === ':') {
                $result .= ':';
                $i += 2;
                continue;
            }

            if (Re::match('/^(x-bind:|:)([A-Za-z_][\w:.\-]*)\s*=\s*(["\'])/', substr($template, $i), $attr)) {
                $name = $attr[2];
                $quote = $attr[3];
                $i += strlen($attr[0]);

                $exprStart = $i;
                $exprEnd = -1;

                while ($i < $length) {
                    if ($template[$i] === '\\') {
                        $i += 2;
                        continue;
                    }
                    if ($template[$i] === $quote) {
                        $exprEnd = $i;
                        break;
                    }
                    $i++;
                }

                if ($exprEnd === -1) {
                    $result .= $attr[0];
                    continue;
                }

                $transformed = $this->transformExpression(trim(substr($template, $exprStart, $exprEnd - $exprStart)));

                // Thẻ component → giữ `:prop="expr"` cho import_tag_resolver.py
                // (nó biến thẻ thành @include và cần dấu ':' để nhận ra binding).
                // Element thường → `:attr="expr"` ≡ `attr="{{ expr }}"`; phát dạng
                // echo để CẢ sao2blade lẫn sao2js bắt được qua đường {{ }} sẵn có.
                $result .= $this->importAliases->has($currentTag)
                    ? ':' . $name . '=' . $quote . $transformed . $quote
                    : $name . '=' . $quote . '{{ ' . $transformed . ' }}' . $quote;

                $i = $exprEnd + 1;
                continue;
            }

            $result .= $ch;
            $i++;
        }

        return $result;
    }

    // ── Token ─────────────────────────────────────────────────────────

    /**
     * '+' là phép cộng hay nối chuỗi?
     *
     * Nếu ở CÙNG mức ngoặc có một chuỗi literal thì mọi '+' ở mức đó là nối
     * chuỗi (PHP dùng '.'). Ngoặc tạo ra mức ưu tiên riêng.
     *
     * @param  list<Token> $tokens
     * @return list<Token>
     */
    private function handlePlusOperator(array $tokens): array
    {
        $hasStringAtDepth0 = false;
        $depth = 0;

        foreach ($tokens as $token) {
            if ($token->value === '(' || $token->value === '[') {
                $depth++;
            } elseif ($token->value === ')' || $token->value === ']') {
                $depth--;
            } elseif ($depth === 0 && $token->is(TokenType::Str)) {
                $hasStringAtDepth0 = true;
                break;
            }
        }

        if (! $hasStringAtDepth0) {
            return $tokens;
        }

        $depth = 0;
        $result = [];

        foreach ($tokens as $token) {
            if ($token->value === '(' || $token->value === '[') {
                $depth++;
            } elseif ($token->value === ')' || $token->value === ']') {
                $depth--;
            }

            $result[] = $token->is(TokenType::Operator) && $token->value === '+' && $depth === 0
                ? new Token(TokenType::PhpConcat, '.')
                : $token;
        }

        return $result;
    }

    /** @param list<Token> $tokens */
    private function transformTokens(array $tokens): string
    {
        $tokens = $this->handlePlusOperator($tokens);

        $result = '';
        $ternaryPending = 0;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (
                $token->is(TokenType::TemplateLiteral)
                || $token->is(TokenType::PhpConcat)
                || $token->is(TokenType::Whitespace)
                || $token->is(TokenType::Str)
                || $token->is(TokenType::Number)
                || $token->is(TokenType::Keyword)
            ) {
                $result .= $token->value;
                continue;
            }

            if ($token->is(TokenType::Identifier)) {
                $result .= $this->renderIdentifier($tokens, $i, $ternaryPending);
                continue;
            }

            if ($token->is(TokenType::Operator) && $token->value === '.') {
                $handled = $this->renderDot($tokens, $i, $result);

                if ($handled !== null) {
                    [$result, $i] = $handled;
                    continue;
                }

                $result .= '->';
                continue;
            }

            if ($token->is(TokenType::Operator)) {
                if ($token->value === '?') {
                    $ternaryPending++;
                } elseif ($token->value === ':') {
                    if ($ternaryPending > 0) {
                        $ternaryPending--;
                    } else {
                        // Không phải dấu ':' của ternary → phân tách key/value mảng
                        $result .= '=>';
                        continue;
                    }
                } elseif ($token->value === '{') {
                    $result .= '[';
                    continue;
                } elseif ($token->value === '}') {
                    $result .= ']';
                    continue;
                }
            }

            $result .= $token->value;
        }

        return $result;
    }

    /** @param list<Token> $tokens */
    private function renderIdentifier(array $tokens, int $i, int $ternaryPending): string
    {
        $name = $tokens[$i]->value;
        $next = self::nextNonWhitespace($tokens, $i);
        $prev = self::prevNonWhitespace($tokens, $i);
        $hasCallParens = $next !== null && $next->value === '(';

        // Đứng sau dấu '.' → là tên thuộc tính, không thêm '$'.
        // PHẢI so KIỂU token, không so value: handlePlusOperator() biến '+'
        // thành Token(PhpConcat, '.') có value CŨNG là '.', nên so value thôi
        // thì `a + '-' + b` coi `b` là tên thuộc tính và nuốt mất '$'
        // → PHP 8 Fatal "Undefined constant b" (§15).
        if ($prev !== null && $prev->is(TokenType::Operator) && $prev->value === '.') {
            return $name;
        }

        // LoopContext: Blade chỉ có `$loop` do Laravel cấp trong @foreach.
        // Không map thì `{{ __loop.index }}` thành `$__loop->index` — biến
        // không tồn tại phía SSR trong khi CSR chạy đúng ⇒ lệch SSR/CSR.
        if (in_array($name, self::LOOP_ALIASES, true)) {
            return '$loop';
        }

        // Khoá của object literal: `{ key: value }` → `['key' => value]`
        if ($next !== null && $next->value === ':' && $ternaryPending === 0) {
            return "'" . $name . "'";
        }

        $symbol = $this->symbols->get($name);

        if ($symbol !== null) {
            if ($symbol->type === SymbolType::Asset) {
                return $this->assetCall($symbol);
            }

            if (! $hasCallParens) {
                return '$' . $name;
            }

            if ($symbol->type === SymbolType::Setter || $symbol->type === SymbolType::Func) {
                return '$' . $name;
            }

            return PhpBuiltins::has($name) ? $name : '$' . $name;
        }

        if ($hasCallParens) {
            return $name;   // nhiều khả năng là hàm PHP
        }

        return in_array($name, self::NO_PREFIX, true) ? $name : '$' . $name;
    }

    /**
     * Dấu '.' — thuộc tính, global JS, hay method kiểu JS?
     *
     * @param  list<Token> $tokens
     * @return array{string, int}|null [kết quả mới, chỉ số mới] hoặc null nếu
     *         chỗ gọi nên phát '->' như bình thường
     */
    private function renderDot(array $tokens, int $i, string $result): ?array
    {
        $prev = self::prevNonWhitespace($tokens, $i);

        // Vế trái đúng là một global của JS → giữ dấu chấm
        if ($prev !== null && $prev->is(TokenType::Identifier) && in_array($prev->value, self::JS_GLOBALS, true)) {
            return [$result . '.', $i];
        }

        $next = self::nextNonWhitespace($tokens, $i);

        if ($next !== null && $next->is(TokenType::Identifier)) {
            $converted = $this->tryJsMethodConversion($next->value, $tokens, $i, $result);

            if ($converted !== null) {
                return $converted;
            }
        }

        return null;
    }

    /**
     * `items.length` → `count($items)`, `arr.join(',')` → `implode(',', $arr)`.
     *
     * @param  list<Token> $tokens
     * @return array{string, int}|null
     */
    private function tryJsMethodConversion(string $methodName, array $tokens, int $dotIndex, string $result): ?array
    {
        $methodIdx = self::nextNonWhitespaceIndex($tokens, $dotIndex);

        if ($methodIdx === -1) {
            return null;
        }

        $afterIdx = self::nextNonWhitespaceIndex($tokens, $methodIdx);
        $after = $afterIdx !== -1 ? $tokens[$afterIdx] : null;
        $hasParens = $after !== null && $after->value === '(';

        $objExpr = self::extractTrailingExpression($result);

        if ($objExpr === null) {
            return null;
        }

        if ($methodName === 'length' && ! $hasParens) {
            return [substr($result, 0, strlen($result) - strlen($objExpr)) . "count({$objExpr})", $methodIdx];
        }

        if (! $hasParens) {
            return null;
        }

        $closeIdx = self::matchingParenIndex($tokens, $afterIdx);

        if ($closeIdx === -1) {
            return null;
        }

        $args = '';
        for ($k = $afterIdx + 1; $k < $closeIdx; $k++) {
            $args .= $tokens[$k]->value;
        }

        $args = trim($args);
        $phpArgs = $args === '' ? '' : $this->transformExpression($args);
        $mapping = JsMethodMap::map($methodName, $objExpr, $phpArgs);

        if ($mapping === null) {
            return null;
        }

        return [substr($result, 0, strlen($result) - strlen($objExpr)) . $mapping, $closeIdx];
    }

    /** Biểu thức đối tượng ngay trước dấu chấm, đọc ngược từ cuối kết quả. */
    private static function extractTrailingExpression(string $str): ?string
    {
        $i = strlen($str) - 1;
        $depth = 0;
        $result = '';

        while ($i >= 0 && ($str[$i] === ' ' || $str[$i] === "\t" || $str[$i] === "\n" || $str[$i] === "\r")) {
            $i--;
        }

        while ($i >= 0) {
            $ch = $str[$i];

            if ($ch === ')' || $ch === ']') {
                $depth++;
                $result = $ch . $result;
                $i--;
                continue;
            }

            if ($ch === '(' || $ch === '[') {
                $depth--;
                if ($depth < 0) {
                    break;
                }
                $result = $ch . $result;
                $i--;
                continue;
            }

            if ($depth > 0) {
                $result = $ch . $result;
                $i--;
                continue;
            }

            if ($i >= 1 && $str[$i - 1] === '-' && $ch === '>') {
                $result = '->' . $result;
                $i -= 2;
                continue;
            }

            if ($ch === '$' || $ch === '_' || ctype_alnum($ch)) {
                $result = $ch . $result;
                $i--;
                continue;
            }

            break;
        }

        return $result === '' ? null : $result;
    }

    // ── Directive ─────────────────────────────────────────────────────

    private function transformDirectives(string $template): string
    {
        $result = $template;

        // slice(1, -1) bỏ cặp ngoặc mà transformAssignmentDeclaration thêm vào —
        // _replaceDirectiveArgs sẽ tự bọc lại.
        $stripParens = fn (string $inner): string
            => substr($this->transformAssignmentDeclaration('', $inner), 1, -1);

        $result = $this->replaceDirectiveArgs($result, 'let', $stripParens);
        $result = $this->replaceDirectiveArgs($result, 'const', $stripParens);

        $result = $this->replaceDirectiveArgs($result, 'foreach', $this->transformForeachExpr(...));
        $result = $this->replaceDirectiveArgs($result, 'for', $this->transformForExpr(...));

        $plain = $this->transformExpression(...);

        foreach (['if', 'elseif', 'while'] as $dir) {
            $result = $this->replaceDirectiveArgs($result, $dir, $plain);
        }

        foreach (self::EVENT_DIRECTIVES as $dir) {
            $result = $this->replaceDirectiveArgs($result, $dir, $plain);
        }

        foreach (self::BIND_DIRECTIVES as $dir) {
            $result = $this->replaceDirectiveArgs($result, $dir, $plain);
        }

        foreach (['class', 'style', 'exec', 'show', 'hide', 'switch', 'case'] as $dir) {
            $result = $this->replaceDirectiveArgs($result, $dir, $plain);
        }

        // @extends nằm ở VIEW_PATH_DIRECTIVES vì đối số của nó là đường dẫn view
        foreach (['section', 'block', 'yield'] as $dir) {
            $result = $this->replaceDirectiveArgs($result, $dir, $plain);
        }

        foreach (self::VIEW_PATH_DIRECTIVES as $dir => $viewArgIndex) {
            $result = $this->replaceDirectiveArgs(
                $result,
                $dir,
                fn (string $inner): string => $this->transformExpression(
                    $this->importAliases->resolveViewArg($inner, $viewArgIndex),
                ),
            );
        }

        return $result;
    }

    /**
     * Thay đối số của mọi lần `@<tên>(...)` xuất hiện.
     *
     * Quét lại từ SAU phần vừa thay — chuỗi đổi độ dài sau mỗi lần thay nên
     * không thể dùng một lượt preg_replace_callback.
     *
     * @param callable(string): string $transform
     */
    private function replaceDirectiveArgs(string $template, string $directiveName, callable $transform): string
    {
        $pattern = '/@' . $directiveName . '\s*\(/';
        $result = $template;
        $offset = 0;

        while ($offset <= strlen($result)) {
            if (! Re::match($pattern, substr($result, $offset), $m, PREG_OFFSET_CAPTURE)) {
                break;
            }

            $matchIndex = $offset + $m[0][1];
            $startIdx = $matchIndex + strlen($m[0][0]);

            $depth = 1;
            $i = $startIdx;
            $length = strlen($result);

            while ($i < $length && $depth > 0) {
                if ($result[$i] === '(') {
                    $depth++;
                } elseif ($result[$i] === ')') {
                    $depth--;
                }
                $i++;
            }

            if ($depth !== 0) {
                // Không cân bằng: bỏ qua, tìm tiếp sau phần khớp (đúng như
                // regex.lastIndex mà exec() để lại bên JS)
                $offset = $startIdx;
                continue;
            }

            $inner = substr($result, $startIdx, $i - 1 - $startIdx);
            $replacement = '@' . $directiveName . '(' . $transform($inner) . ')';

            $result = substr($result, 0, $matchIndex) . $replacement . substr($result, $i);
            $offset = $matchIndex + strlen($replacement);
        }

        return $result;
    }

    /**
     * `items as item` / `items as key => item`.
     *
     * ⚠️ Đẩy scope nhưng KHÔNG BAO GIỜ gỡ — biến vòng lặp rò ra phần còn lại
     * của template. Đó là hành vi của bản JS, giữ nguyên để khớp parity.
     */
    private function transformForeachExpr(string $inner): string
    {
        if (! Re::match('/^(.+?)\s+as\s+(?:(\w+)\s*=>\s*)?(\w+)$/', $inner, $m)) {
            return $this->transformExpression($inner);
        }

        $collection = $this->transformExpression(trim($m[1]));
        $key = $m[2];
        $value = $m[3];

        $this->symbols->pushScope();
        $this->symbols->addScoped($value, new Symbol(SymbolType::LoopVar, '@foreach'));

        if ($key !== '') {
            $this->symbols->addScoped($key, new Symbol(SymbolType::LoopVar, '@foreach'));

            return $collection . ' as $' . $key . ' => $' . $value;
        }

        return $collection . ' as $' . $value;
    }

    private function transformForExpr(string $inner): string
    {
        return implode('; ', array_map(
            fn (string $p): string => $this->transformExpression(trim($p)),
            explode(';', $inner),
        ));
    }

    // ── Khai báo ──────────────────────────────────────────────────────

    private function transformStateDeclaration(string $inner): string
    {
        $parseStr = trim($inner);

        if (str_starts_with($parseStr, '{') && str_ends_with($parseStr, '}')) {
            $parseStr = trim(substr($parseStr, 1, -1));
        }

        $results = [];

        foreach (Balanced::splitTopLevelLoose($parseStr, ',') as $part) {
            $colonIdx = strpos($part, ':');
            $eqIdx = Balanced::findAssignmentLoose($part);

            if ($colonIdx !== false && ($eqIdx === -1 || $colonIdx < $eqIdx)) {
                $name = Re::replace('/^[\'"]|[\'"]$/', '', trim(substr($part, 0, $colonIdx)));
                $value = trim(substr($part, $colonIdx + 1));
            } elseif ($eqIdx !== -1) {
                $name = trim(substr($part, 0, $eqIdx));
                $value = trim(substr($part, $eqIdx + 1));
            } else {
                continue;
            }

            $results[] = '@useState($' . $name . ', ' . $this->transformExpression($value) . ')';
        }

        return implode("\n", $results);
    }

    private function transformAssignmentDeclaration(string $directive, string $inner): string
    {
        $eqIdx = Balanced::findAssignmentLoose($inner);

        if ($eqIdx === -1) {
            return $directive . '(' . $inner . ')';
        }

        $lhs = trim(substr($inner, 0, $eqIdx));
        $rhs = trim(substr($inner, $eqIdx + 1));

        if (str_starts_with($lhs, '[') || str_starts_with($lhs, '{')) {
            return $directive . '(' . self::transformDestructuringLhs($lhs)
                . ' = ' . $this->transformExpression($rhs) . ')';
        }

        return $directive . '($' . Re::replace('/^\$/', '', $lhs)
            . ' = ' . $this->transformExpression($rhs) . ')';
    }

    private static function transformDestructuringLhs(string $lhs): string
    {
        $isArray = str_starts_with($lhs, '[');

        $names = array_map(
            static fn (string $n): string => '$' . Re::replace('/^\$/', '', trim($n)),
            explode(',', substr($lhs, 1, -1)),
        );

        $joined = implode(', ', $names);

        return $isArray ? '[' . $joined . ']' : '{' . $joined . '}';
    }

    /** Ký hiệu @asset → `asset('<prefix><path>')` — Blade eval được ở SSR. */
    private function assetCall(Symbol $symbol): string
    {
        $rel = Re::replace('#^/+#', '', $symbol->assetPath ?? '');
        $rel = str_replace('\\', '\\\\', $rel);
        $rel = str_replace("'", "\\'", $rel);

        return "asset('" . $this->assetPrefix . $rel . "')";
    }

    // ── Tiện ích trên mảng token ──────────────────────────────────────

    /** @param list<Token> $tokens */
    private static function nextNonWhitespace(array $tokens, int $from): ?Token
    {
        $idx = self::nextNonWhitespaceIndex($tokens, $from);

        return $idx === -1 ? null : $tokens[$idx];
    }

    /** @param list<Token> $tokens */
    private static function nextNonWhitespaceIndex(array $tokens, int $from): int
    {
        for ($i = $from + 1, $n = count($tokens); $i < $n; $i++) {
            if (! $tokens[$i]->is(TokenType::Whitespace)) {
                return $i;
            }
        }

        return -1;
    }

    /** @param list<Token> $tokens */
    private static function prevNonWhitespace(array $tokens, int $from): ?Token
    {
        for ($i = $from - 1; $i >= 0; $i--) {
            if (! $tokens[$i]->is(TokenType::Whitespace)) {
                return $tokens[$i];
            }
        }

        return null;
    }

    /** @param list<Token> $tokens */
    private static function matchingParenIndex(array $tokens, int $openIndex): int
    {
        $depth = 1;

        for ($i = $openIndex + 1, $n = count($tokens); $i < $n; $i++) {
            if ($tokens[$i]->value === '(') {
                $depth++;
            } elseif ($tokens[$i]->value === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return -1;
    }
}
