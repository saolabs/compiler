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

    private bool $computedExpression = false;

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

        if (Re::match('/^@computed\s*\(([\s\S]*)\)$/', $declaration, $m)) {
            try {
                $this->computedExpression = true;
                return $this->transformAssignmentDeclaration('@computed', $m[1]);
            } finally {
                $this->computedExpression = false;
            }
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
        // Che `{{-- … --}}` và `@verbatim … @endverbatim` trong MỘT lượt quét.
        //
        // Hai lượt riêng thì khối nào che sau sẽ nuốt placeholder của khối che
        // trước khi hai thứ lồng nhau, và vòng khôi phục quét `$result` không
        // còn gì để thay — placeholder rò thẳng ra trang. Đo được cả hai chiều:
        // comment TRONG verbatim (thấy trên /demo/setup, 06/09/2026) và verbatim
        // TRONG comment.
        //
        // Quét xen kẽ trái-sang-phải thì khối NGOÀI luôn khớp trước và nuốt trọn
        // khối trong, nên không còn chuyện lồng nhau. Nội dung được cất giữ
        // nguyên văn để các bước dịch bên dưới không đụng tới: `{{ title }}` trong
        // khối code minh hoạ không bị thêm '$', comment giữ đúng chữ đã viết.
        //
        // Comment NGOÀI verbatim vẫn bị bỏ như cũ — việc đó do các khâu sau làm
        // (MainCompiler và chính Blade), không phải ở đây.
        $masked = [];
        $result = Re::replaceCallback(
            '/\{\{--[\s\S]*?--\}\}|@verbatim\b[\s\S]*?@endverbatim\b/i',
            function (array $m) use (&$masked): string {
                $placeholder = '__SAO_MASKED_' . count($masked) . '__';
                $masked[] = $m[0];

                return $placeholder;
            },
            $template,
        );

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

        $result = $this->transformComponentTagEvents($result);
        $result = $this->transformDirectives($result);
        $result = $this->transformAttributeBindings($result);

        // Placeholder là duy nhất nên str_replace (thay tất cả) tương đương
        // thay-lần-đầu của JS. Bản JS phải dùng replacement dạng HÀM để tránh
        // `$$`/`$&` trong nội dung bị diễn giải; str_replace của PHP không có
        // vấn đề đó.
        // Placeholder là duy nhất và không lồng nhau nên thay thẳng, thứ tự nào
        // cũng đúng. `str_replace` của PHP không diễn giải `$$`/`$&` trong nội
        // dung — bản JS phải dùng replacement dạng HÀM để tránh đúng chuyện đó.
        foreach ($masked as $i => $block) {
            $result = str_replace('__SAO_MASKED_' . $i . '__', $block, $result);
        }

        return $result;
    }

    // ── Sự kiện của thẻ component ─────────────────────────────────────

    /**
     * `<mycomp @edit(handler(event))>` → `<mycomp @edit="handler($event)">`.
     *
     * Con phát sự kiện bằng `emit('edit', payload)`, cha lắng nghe ngay tại thẻ
     * — không qua event bus. Biểu thức được dịch Ở ĐÂY rồi cất vào nháy, nên
     * {@see \Saola\Compiler\Template\ImportTagResolver} đọc nó bằng đúng
     * đường thuộc tính có nháy sẵn có.
     *
     * PHẢI chạy TRƯỚC transformDirectives: tên sự kiện trùng tên DOM
     * (`<mycomp @click(...)>`) mà để lượt directive chạm vào trước thì nó bị
     * dịch thành cấu hình sự kiện của element, mất luôn đường về component.
     */
    private function transformComponentTagEvents(string $template): string
    {
        $tags = $this->importAliases->names();
        if ($tags === []) {
            return $template;
        }

        $pattern = '~<(' . implode('|', array_map(
            static fn (string $tag): string => preg_quote($tag, '~'),
            $tags,
        )) . ')(?=[\s/>])~';

        $result = '';
        $offset = 0;

        while (Re::match($pattern, $template, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $m[0][1];
            $end = self::scanComponentTagEnd($template, $start + strlen($m[0][0]));
            $result .= substr($template, $offset, $start - $offset)
                . $this->rewriteTagEvents(substr($template, $start, $end - $start));
            $offset = $end;
        }

        return $result . substr($template, $offset);
    }

    /**
     * Vị trí ngay sau '>' của thẻ mở, bỏ qua '>' nằm trong nháy HOẶC trong
     * ngoặc tròn — `@edit(a > b)` là đối số, không phải chỗ đóng thẻ.
     */
    private static function scanComponentTagEnd(string $source, int $start): int
    {
        $quote = null;
        $paren = 0;
        $length = strlen($source);

        for ($pos = $start; $pos < $length; $pos++) {
            $char = $source[$pos];

            if ($quote !== null) {
                if ($char === '\\') {
                    $pos++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '(') {
                $paren++;
            } elseif ($char === ')' && $paren > 0) {
                $paren--;
            } elseif ($char === '>' && $paren === 0) {
                return $pos + 1;
            }
        }

        return $length;
    }

    /**
     * `@on('tên', handler)` → tên sự kiện lấy từ đối số thứ nhất.
     *
     * Dạng tổng quát bên cạnh `@edit(handler)`: tên là DỮ LIỆU nên đặt được cả
     * những tên không phải định danh (`@on('user:saved', h)`), và không thể đọc
     * nhầm với một sự kiện DOM trùng tên.
     *
     * Cả hai cách viết ra CÙNG một khoá `on$<tên>` ở hạ nguồn.
     *
     * @return array{string, string} [tên sự kiện, biểu thức handler]
     */
    private static function splitOnDirective(string $directive, string $inner): array
    {
        if ($directive !== 'on') {
            return [$directive, $inner];
        }

        $comma = self::topLevelComma($inner);
        $name = $comma === -1 ? trim($inner) : trim(substr($inner, 0, $comma));

        if ($comma === -1 || ! Re::match('/^([\'"])(.+)\1$/s', $name, $m)) {
            throw new \RuntimeException(
                "@on() cần tên sự kiện dạng chuỗi rồi tới handler: @on('tên', handler). Nhận được: @on({$inner})",
            );
        }

        return [$m[2], substr($inner, $comma + 1)];
    }

    /** Vị trí dấu ',' đầu tiên ở mức ngoài cùng, -1 nếu không có. */
    private static function topLevelComma(string $value): int
    {
        $depth = 0;
        $quote = null;
        for ($i = 0, $n = strlen($value); $i < $n; $i++) {
            $ch = $value[$i];
            if ($quote !== null) {
                if ($ch === '\\') $i++;
                elseif ($ch === $quote) $quote = null;
                continue;
            }
            if ($ch === '"' || $ch === "'" || $ch === '`') $quote = $ch;
            elseif ($ch === '(' || $ch === '[' || $ch === '{') $depth++;
            elseif ($ch === ')' || $ch === ']' || $ch === '}') $depth--;
            elseif ($ch === ',' && $depth === 0) return $i;
        }

        return -1;
    }

    /**
     * Chỉ nhận `@name(` NGOÀI nháy: `title="a@b(c)"` là văn bản của thuộc tính,
     * không phải khai báo sự kiện.
     */
    private function rewriteTagEvents(string $tag): string
    {
        $out = '';
        $kept = 0;
        $quote = null;
        $length = strlen($tag);

        for ($pos = 0; $pos < $length; $pos++) {
            $char = $tag[$pos];

            if ($quote !== null) {
                if ($char === '\\') $pos++;
                elseif ($char === $quote) $quote = null;
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }

            if ($char !== '@' || ! Re::match('/\G@([A-Za-z_][\w-]*)\s*\(/', $tag, $m, PREG_OFFSET_CAPTURE, $pos)) {
                continue;
            }

            [$inner, $end] = Balanced::extractParensAt($tag, $pos + strlen($m[0][0]) - 1);
            if ($inner === null) {
                break;
            }

            [$name, $inner] = self::splitOnDirective($m[1][0], $inner);
            $expr = $this->transformExpression(trim($inner));
            // Nháy kép trong giá trị thì bọc bằng nháy đơn — cùng quy ước với
            // `:prop="..."`; chứa cả hai loại thì không biểu diễn được.
            $wrap = str_contains($expr, '"') ? "'" : '"';
            $out .= substr($tag, $kept, $pos - $kept) . '@' . $name . '=' . $wrap . $expr . $wrap;
            $kept = $end;
            $pos = $end - 1;
        }

        return $out . substr($tag, $kept);
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

            // Dấu ':' GIỮA tên một thuộc tính `@…` là của tên sự kiện
            // (`@on('user:saved', …)` đã thành `@user:saved="…"`), không phải
            // mở đầu một binding. Khớp nhầm thì giá trị bị dịch LẦN HAI.
            if (self::insideEventAttrName($result)) {
                $result .= $ch;
                $i++;
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

    /**
     * Con trỏ đang nằm giữa tên một thuộc tính directive (`@name`)?
     *
     * Nhìn lui trong phần đã dựng tới khoảng trắng gần nhất: mảnh đó bắt đầu
     * bằng '@' nghĩa là ta đang ở giữa `@user:saved`, không phải ở đầu `:prop`.
     */
    private static function insideEventAttrName(string $out): bool
    {
        $cut = strcspn(strrev($out), " \t\n\r<");
        if ($cut === 0 || $cut === strlen($out)) {
            return false;
        }

        return $out[strlen($out) - $cut] === '@';
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
        $paramBraces = self::arrowParamBraces($tokens);

        $result = '';
        $ternaryPending = 0;
        $count = count($tokens);
        /**
         * Mỗi '{' đang mở là object literal (→ '[') hay THÂN HÀM (giữ '{')?
         *
         * `(a, b) => { f(a); g(b) }` mà đổi thành '[' sẽ ra `[f(a); g(b)]` —
         * vừa sai JS vừa sai PHP. Ngăn xếp để '}' đóng đúng loại của '{' đã mở.
         *
         * @var list<bool> true = thân hàm
         */
        $braceIsBlock = [];

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
                    $previous = self::prevNonWhitespace($tokens, $i);
                    // Giữ '{' khi nó là THÂN hàm (`=> { … }`) hoặc PATTERN gỡ
                    // rối trong danh sách tham số (`({id, title}) => …`).
                    $isBlock = isset($paramBraces[$i])
                        || ($previous !== null
                            && $previous->is(TokenType::Operator)
                            && $previous->value === '=>');
                    $braceIsBlock[] = $isBlock;
                    $result .= $isBlock ? '{' : '[';
                    continue;
                } elseif ($token->value === '}') {
                    $result .= array_pop($braceIsBlock) === true ? '}' : ']';
                    continue;
                }
            }

            $result .= $token->value;
        }

        return $result;
    }

    /**
     * Chỉ số các token '{' nằm trong DANH SÁCH THAM SỐ của một arrow.
     *
     * `({id, title}) => f(id)` — dấu ngoặc nhọn ở đây là pattern gỡ rối, không
     * phải object literal, nên không được đổi thành '[' của mảng PHP.
     *
     * Nhận diện đi NGƯỢC từ '=>': token liền trước là ')' thì dò về '(' khớp,
     * mọi '{' trong khoảng đó là pattern. Đi ngược là cách duy nhất chắc chắn —
     * lúc gặp '{' thì chưa biết phía sau có '=>' hay không.
     *
     * @param list<Token> $tokens
     * @return array<int, true>
     */
    private static function arrowParamBraces(array $tokens): array
    {
        $marks = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! $tokens[$i]->is(TokenType::Operator) || $tokens[$i]->value !== '=>') {
                continue;
            }

            $close = $i - 1;
            while ($close >= 0 && $tokens[$close]->is(TokenType::Whitespace)) {
                $close--;
            }
            if ($close < 0 || ! $tokens[$close]->is(TokenType::Operator) || $tokens[$close]->value !== ')') {
                continue;
            }

            $depth = 0;
            $open = -1;
            for ($k = $close; $k >= 0; $k--) {
                if (! $tokens[$k]->is(TokenType::Operator)) {
                    continue;
                }
                if ($tokens[$k]->value === ')') {
                    $depth++;
                } elseif ($tokens[$k]->value === '(') {
                    $depth--;
                    if ($depth === 0) {
                        $open = $k;
                        break;
                    }
                }
            }
            if ($open < 0) {
                continue;
            }

            for ($m = $open; $m <= $close; $m++) {
                if ($tokens[$m]->is(TokenType::Operator) && ($tokens[$m]->value === '{' || $tokens[$m]->value === '}')) {
                    $marks[$m] = true;
                }
            }
        }

        return $marks;
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
            if ($this->computedExpression) {
                return [substr($result, 0, strlen($result) - strlen($objExpr))."data_get({$objExpr}, '{$methodName}')", $methodIdx];
            }
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
        if ($this->computedExpression && in_array($methodName, ['filter', 'map', 'reduce'], true)) {
            $mapping = $this->computedCollection($methodName, $objExpr, $args);
            return [substr($result, 0, strlen($result) - strlen($objExpr)).$mapping, $closeIdx];
        }
        $phpArgs = $args === '' ? '' : $this->transformExpression($args);
        $mapping = JsMethodMap::map($methodName, $objExpr, $phpArgs);

        if ($mapping === null) {
            return null;
        }

        return [substr($result, 0, strlen($result) - strlen($objExpr)) . $mapping, $closeIdx];
    }

    /** Collection callbacks use PHP arrow closures (capture inputs by value). */
    private function computedCollection(string $method, string $object, string $arguments): string
    {
        $args = Balanced::splitTopLevelStripped($arguments, ',');
        $callback = $args[0] ?? '';
        if (!Re::match('/^\s*(?:\(([^()]*)\)|([A-Za-z_]\w*))\s*=>\s*([\s\S]+)$/', $callback, $m)) {
            throw new \InvalidArgumentException('Computed .'.$method.' requires an expression arrow callback.');
        }
        $params = array_map('trim', explode(',', trim($m[1] !== '' ? $m[1] : $m[2])));
        if (count($params) > 2 || str_starts_with(trim($m[3]), '{')) {
            throw new \InvalidArgumentException('Computed callbacks support one or two parameters and an expression body.');
        }
        foreach ($params as $param) {
            if (!Re::match('/^[A-Za-z_]\w*$/', $param)) throw new \InvalidArgumentException('Invalid computed callback parameter: '.$param);
        }
        $closure = 'fn('.implode(', ', array_map(static fn(string $p): string => '$'.$p, $params)).') => '.$this->transformExpression($m[3]);
        $array = "array_values({$object})";
        return match ($method) {
            'filter' => "array_values(array_filter({$array}, {$closure}, ARRAY_FILTER_USE_BOTH))",
            'map' => "array_map({$closure}, {$array}, array_keys({$array}))",
            'reduce' => count($args) === 2
                ? "array_reduce({$array}, {$closure}, ".$this->transformExpression($args[1]).')'
                : throw new \InvalidArgumentException('Computed .reduce requires an explicit initial value.'),
        };
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
