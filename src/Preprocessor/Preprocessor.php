<?php

declare(strict_types=1);

namespace Saola\Compiler\Preprocessor;

use Saola\Compiler\Source\SourceParts;
use Saola\Compiler\Support\Re;

/**
 * Chuyển Saola Syntax (`.sao`) sang cú pháp PHP/Blade.
 *
 * Hai lượt:
 *   1. {@see SymbolCollector} — quét khai báo, dựng bảng ký hiệu
 *   2. {@see ExpressionTransformer} — dịch khai báo và template
 *
 * LUÔN transform — không còn "chế độ pass-through cho cú pháp PHP cũ". Trước
 * đây `<blade>` khiến CẢ FILE (kể cả declarations nằm ngoài thẻ đó) bị bỏ qua
 * hoàn toàn, và một heuristic tính điểm quyết định phần còn lại. Cả hai đã bị
 * gỡ: xem docs/05-roadmap.md §11 về usecase thật duy nhất từng dựa vào nó
 * (`posts/list.sao`, không route, đã viết lại sang Saola Syntax).
 *
 * `PhpJsBridge` (trong Expr/) là chuyện khác — nó là bước dịch PHP→JS BẮT
 * BUỘC ở tầng dưới cho MỌI biểu thức, không liên quan gì tới quyết định
 * "transform hay không" ở đây.
 *
 * Port từ compiler/src/preprocessor/index.js. Đây là code JS chứ không phải
 * Python: nó nằm ở phía Node và bắt buộc phải có bằng PHP thì compiler mới
 * đứng độc lập được (docs/01-architecture.md §3).
 */
final class Preprocessor
{
    public function __construct(
        private readonly string $assetPrefix = 'static/saola/assets/',
    ) {
    }

    /**
     * Dịch các phần đã tách từ một file `.sao`.
     *
     * `script` và `style` KHÔNG bị đụng tới — chúng vốn đã là JS/CSS.
     */
    public function preprocess(SourceParts $parts): SourceParts
    {
        // Dựng lại nội dung đầy đủ để quét ký hiệu
        $fullContent = implode("\n", [
            ...$parts->declarations,
            $parts->blade,
            $parts->bladeWithSSR,
        ]);

        $table = (new SymbolCollector())->collect($fullContent);
        $transformer = new ExpressionTransformer($table, $this->assetPrefix);

        // Alias @import phải lấy từ nội dung GỐC: sau khi transform thì
        // `x as tên` đã thành cú pháp PHP, không còn nhận ra alias nữa.
        $transformer->collectImportAliases($fullContent);

        return new SourceParts(
            declarations: array_map(
                static fn (string $d): string => $transformer->transformDeclaration($d),
                $parts->declarations,
            ),
            blade: $parts->blade === '' ? $parts->blade : $transformer->transformTemplate($parts->blade),
            bladeWithSSR: $parts->bladeWithSSR === ''
                ? $parts->bladeWithSSR
                : $transformer->transformTemplate($parts->bladeWithSSR),
            script: $parts->script,
            style: $parts->style,
            cleanedContent: $parts->cleanedContent,
            wrapperType: $parts->wrapperType,
        );
    }

    /**
     * Dịch thẳng nội dung `.sao` thô.
     *
     * API thay thế cho lúc chưa tách phần. Bản JS chỉ dùng nó trong test, nhưng
     * port để bộ test preprocessor sẵn có chạy được với bản PHP.
     */
    public function preprocessRaw(string $content): string
    {
        $table = (new SymbolCollector())->collect($content);
        $transformer = new ExpressionTransformer($table, $this->assetPrefix);
        $transformer->collectImportAliases($content);

        $sections = self::splitSections($content);
        $result = '';

        foreach ($sections['declarations'] as $declaration) {
            $result .= $transformer->transformDeclaration($declaration) . "\n";
        }

        if ($sections['template'] !== '') {
            $result .= $transformer->transformTemplate($sections['template']);
        }

        if ($sections['script'] !== null) {
            $result .= "\n" . $sections['script'];
        }

        if ($sections['style'] !== null) {
            $result .= "\n" . $sections['style'];
        }

        return $result;
    }

    // ── Tách phần (cho preprocessRaw) ─────────────────────────────────

    /**
     * @return array{declarations: list<string>, template: string, script: ?string, style: ?string}
     */
    private static function splitSections(string $content): array
    {
        $script = null;
        $style = null;

        if (Re::match('/<script[\s\S]*?<\/script>/i', $content, $m)) {
            $script = $m[0];
            $content = self::replaceFirst($content, $m[0], '');
        }

        if (Re::match('/<style[\s\S]*?<\/style>/i', $content, $m)) {
            $style = $m[0];
            $content = self::replaceFirst($content, $m[0], '');
        }

        $declarations = [];
        $templateLines = [];
        $inTemplate = false;
        $inDeclaration = false;
        $currentDecl = '';

        foreach (explode("\n", $content) as $line) {
            $trimmed = trim($line);

            if ($inDeclaration) {
                $currentDecl .= "\n" . $line;

                if (self::isBalanced($currentDecl)) {
                    $declarations[] = trim($currentDecl);
                    $currentDecl = '';
                    $inDeclaration = false;
                }

                continue;
            }

            if (
                str_starts_with($trimmed, '<blade')
                || str_starts_with($trimmed, '<template')
                || str_starts_with($trimmed, '<sao:blade')
            ) {
                $inTemplate = true;
                $templateLines[] = $line;
                continue;
            }

            if ($inTemplate) {
                $templateLines[] = $line;

                if (in_array($trimmed, ['</blade>', '</template>', '</sao:blade>'], true)) {
                    $inTemplate = false;
                }

                continue;
            }

            if (Re::match('/^@(state|vars|props|let|const|useState|states|assets?)\s*\(/', $trimmed)) {
                $currentDecl = $line;

                if (self::isBalanced($currentDecl)) {
                    // Một dòng: đẩy bản ĐÃ TRIM (khác nhánh nhiều dòng bên trên)
                    $declarations[] = $trimmed;
                    $currentDecl = '';
                } else {
                    $inDeclaration = true;
                }

                continue;
            }

            if ($trimmed !== '' && ! str_starts_with($trimmed, '//') && ! str_starts_with($trimmed, '{{--')) {
                $templateLines[] = $line;
            }
        }

        return [
            'declarations' => $declarations,
            'template' => implode("\n", $templateLines),
            'script' => $script,
            'style' => $style,
        ];
    }

    private static function isBalanced(string $str): bool
    {
        return substr_count($str, '(') === substr_count($str, ')');
    }

    /** `String.replace(chuỗi)` của JS chỉ thay lần đầu; `str_replace` thay hết. */
    private static function replaceFirst(string $haystack, string $needle, string $replacement): string
    {
        $pos = strpos($haystack, $needle);

        return $pos === false
            ? $haystack
            : substr_replace($haystack, $replacement, $pos, strlen($needle));
    }
}
