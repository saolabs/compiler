<?php

declare(strict_types=1);

namespace Saola\Compiler\Source;

use Saola\Compiler\Support\Balanced;
use Saola\Compiler\Support\BladeComment;
use Saola\Compiler\Support\Re;

/**
 * Tách file `.sao` thành khai báo / template / script / style.
 *
 * Bước đầu tiên của pipeline, chạy trước preprocessor.
 *
 * Port từ compiler/src/index.js::parseSaoFile (~280 dòng). Đây là code JS chứ
 * không phải Python — nó nằm ở phía Node, và phải chuyển sang PHP thì compiler
 * mới đứng độc lập được. Xem docs/01-architecture.md §3.
 */
final class SourceSplitter
{
    /**
     * Khai báo ở cấp ngoài cùng. Thứ tự khớp bản JS; đổi thứ tự có thể đổi kết
     * quả khi hai tên chồng lấn nhau.
     */
    private const DECLARATION_TYPES = [
        'useState', 'const', 'let', 'var', 'vars',
        'state', 'props', 'states', 'import', 'asset', 'assets',
    ];

    /** Khối chỉ chạy phía server — có mặt trong Blade, vắng mặt trong JS. */
    private const SSR_BLOCK = '/@(?:serverside|serverSide|ssr|SSR|useSSR|useSsr)\b[\s\S]*?'
        . '@end(?:serverside|serverSide|ServerSide|SSR|Ssr|ssr|useSSR|useSsr)\b/i';

    private const SCRIPT_TAG = '/<script[\s\S]*?<\/script>/i';

    private const STYLE_TAG = '/<style[\s\S]*?<\/style>/i';

    public function __construct(
        private readonly WrapperScanner $wrappers = new WrapperScanner(),
    ) {
    }

    public function split(string $source): SourceParts
    {
        // Blade giữ nguyên bản gốc: hydrate processor cần thấy directive @ssr
        // để bỏ qua việc cấp id cho element chỉ có ở server, rồi mới strip.
        $contentForBlade = $source;

        // JS thì bỏ hẳn khối @ssr — cả directive lẫn nội dung
        $content = Re::replace(self::SSR_BLOCK, '', $source);

        $wrappers = $this->wrappers->scan($content);
        $declarations = $this->extractDeclarations($content, $wrappers);

        // @await / @fetch không phải khai báo mà là cờ cho compiler; chúng
        // được chèn lại lên đầu template.
        // @await/@fetch nêu trong chú thích không phải cờ thật — không che thì
        // view đi gọi API chỉ vì tài liệu có nhắc tới nó (§21). Làm trắng giữ
        // nguyên độ dài nên offset dưới đây vẫn trỏ đúng $content gốc.
        $flagScan = BladeComment::blank($content);

        $hasAwait = Re::match('/@await(\s|$)/D', $flagScan);
        // Phải lấy TRỌN `@fetch(...)`, không chỉ `@fetch(`.
        //
        // Bản cũ dùng `content.match(/@fetch\s*\(/)[0]` — chỉ ra `@fetch(` —
        // rồi chèn mảnh CỤT đó lên đầu template. Bước strip sau đó
        // (`/@fetch\s*\([^)]*\)/`) không khớp được vì thiếu ngoặc đóng, nên nó
        // nuốt luôn phần đầu template: `<div>Có {{ users.length }}...` chỉ còn
        // `}} người dùng`, và view mất sạch cây render phía JS.
        $fetchPrefix = null;

        if (Re::match('/@fetch\s*\(/', $flagScan, $fetchMatch, PREG_OFFSET_CAPTURE)) {
            $parenAt = $fetchMatch[0][1] + strlen($fetchMatch[0][0]) - 1;
            $inner = Balanced::extractParens($content, $parenAt);

            $fetchPrefix = $inner === null
                ? $fetchMatch[0][0]
                : substr($content, $fetchMatch[0][1], $parenAt - $fetchMatch[0][1] + strlen($inner) + 2);
        }

        $wrapperType = null;
        $bladeFromWrapper = null;

        if ($wrappers !== []) {
            // Nhiều thẻ cấp ngoài cùng: lấy thẻ ĐẦU, bỏ các thẻ còn lại
            $wrapperType = $wrappers[0]->tagName;
            $bladeFromWrapper = self::jsTrim($wrappers[0]->innerContent);

            // Gỡ MỌI thẻ bọc khỏi content để <script>/<style> nằm trong thẻ
            // không bị bóc ra ở bước sau
            foreach ($wrappers as $wrapper) {
                $content = self::replaceFirst($content, $wrapper->fullMatch, '');
            }
        }

        $blade = $bladeFromWrapper ?? $this->stripToTemplate($content, $declarations);
        $bladeWithSSR = $wrapperType === null
            ? $this->stripToTemplate($contentForBlade, $declarations)
            : $this->bladeWithSsrFromWrapper($contentForBlade, $blade);

        return new SourceParts(
            declarations: $declarations,
            blade: $this->prependFlags($blade, $hasAwait, $fetchPrefix),
            bladeWithSSR: $this->prependFlags($bladeWithSSR, $hasAwait, $fetchPrefix),
            script: $this->extractTagBody($content, 'script'),
            style: $this->extractTagBody($content, 'style'),
            cleanedContent: $content,
            wrapperType: $wrapperType,
        );
    }

    /**
     * Bóc khai báo ở cấp ngoài cùng, GIỮ NGUYÊN thứ tự trong file.
     *
     * Khớp ngoặc theo cặp chứ không bằng regex — khai báo lồng ngoặc được:
     * `@let([$x, $y] = useState($data))`.
     *
     * @param  list<WrapperTag> $wrappers
     * @return list<string>
     */
    private function extractDeclarations(string $content, array $wrappers): array
    {
        $found = [];
        $length = strlen($content);

        // Ví dụ minh hoạ trong comment không phải khai báo thật. Làm trắng giữ
        // nguyên độ dài nên offset vẫn trỏ đúng vào $content gốc.
        $scan = BladeComment::blank($content);

        foreach (self::DECLARATION_TYPES as $type) {
            $offset = 0;

            while ($offset < $length) {
                if (! Re::match('/@' . $type . '\s*\(/', substr($scan, $offset), $m, PREG_OFFSET_CAPTURE)) {
                    break;
                }

                $start = $offset + $m[0][1];
                $cursor = $start + strlen($m[0][0]);
                $depth = 1;

                while ($cursor < $length && $depth > 0) {
                    if ($scan[$cursor] === '(') {
                        $depth++;
                    } elseif ($scan[$cursor] === ')') {
                        $depth--;
                    }
                    $cursor++;
                }

                if ($depth === 0 && ! $this->isInsideAnyWrapper($wrappers, $start, $cursor)) {
                    $found[] = ['index' => $start, 'text' => substr($content, $start, $cursor - $start)];
                }

                $offset = $start + strlen($m[0][0]);
            }
        }

        usort($found, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);

        return array_map(static fn (array $d): string => $d['text'], $found);
    }

    /** @param list<WrapperTag> $wrappers */
    private function isInsideAnyWrapper(array $wrappers, int $start, int $end): bool
    {
        foreach ($wrappers as $wrapper) {
            if ($wrapper->covers($start, $end)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Không có thẻ bọc: template là phần còn lại sau khi gỡ script, style và
     * các khai báo.
     *
     * @param list<string> $declarations
     */
    private function stripToTemplate(string $content, array $declarations): string
    {
        $content = self::stripOutsideComments(self::SCRIPT_TAG, $content);
        $content = self::stripOutsideComments(self::STYLE_TAG, $content);

        foreach ($declarations as $declaration) {
            $content = self::replaceFirst($content, $declaration, '');
        }

        return self::jsTrim($content);
    }

    /**
     * Lấy lại nội dung thẻ bọc từ bản GỐC (còn nguyên khối @ssr).
     *
     * Phải quét lại: `$content` đã bị bỏ khối @ssr nên vị trí không còn khớp.
     */
    private function bladeWithSsrFromWrapper(string $contentForBlade, string $fallback): string
    {
        $wrappers = $this->wrappers->scan($contentForBlade);

        return $wrappers === []
            ? $fallback
            : self::jsTrim($wrappers[0]->innerContent);
    }

    private function prependFlags(string $blade, bool $hasAwait, ?string $fetchPrefix): string
    {
        if ($hasAwait) {
            $blade = "@await\n" . $blade;
        }

        if ($fetchPrefix !== null) {
            $blade = $fetchPrefix . "\n" . $blade;
        }

        return $blade;
    }

    /** Nội dung thẻ <script> / <style> ĐẦU TIÊN, đã trim. */
    /**
     * Xoá mọi match NẰM NGOÀI comment, giữ nguyên comment.
     *
     * Xoá từ cuối về đầu để offset của các match trước không bị dịch.
     */
    private static function stripOutsideComments(string $pattern, string $content): string
    {
        $sets = Re::matchAll($pattern, BladeComment::blank($content), PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach (array_reverse($sets) as $set) {
            $content = substr_replace($content, '', $set[0][1], strlen($set[0][0]));
        }

        return $content;
    }

    private function extractTagBody(string $content, string $tag): string
    {
        $pattern = '/<' . $tag . '[^>]*>([\s\S]*?)<\/' . $tag . '>/i';

        // `<script>`/`<style>` in ra làm ví dụ trong comment không phải khối
        // thật. Khớp trên bản làm trắng, rồi cắt nội dung từ bản GỐC theo
        // offset — thân thẻ thật có thể chứa `{{--` nên không đọc bản trắng.
        if (! Re::match($pattern, BladeComment::blank($content), $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        return self::jsTrim(substr($content, $m[1][1], strlen($m[1][0])));
    }

    /**
     * Thay lần xuất hiện ĐẦU TIÊN.
     *
     * `String.replace(chuỗi, ...)` của JS chỉ thay lần đầu, còn `str_replace()`
     * của PHP thay TẤT CẢ. Dùng nhầm sẽ xoá cả các khai báo trùng nội dung ở
     * chỗ khác trong file.
     */
    private static function replaceFirst(string $haystack, string $needle, string $replacement): string
    {
        if ($needle === '') {
            return $haystack;
        }

        $pos = strpos($haystack, $needle);

        return $pos === false
            ? $haystack
            : substr_replace($haystack, $replacement, $pos, strlen($needle));
    }

    /**
     * Tương đương String.prototype.trim() của JS.
     *
     * `trim()` của PHP chỉ cắt " \t\n\r\0\x0B", còn JS cắt mọi khoảng trắng
     * Unicode kể cả BOM và non-breaking space. Với file `.sao` có BOM hoặc
     * khoảng trắng dán từ trình soạn thảo, khác biệt này đủ làm lệch output.
     */
    private static function jsTrim(string $value): string
    {
        return Re::replace('/^[\s\x{FEFF}\x{00A0}]+|[\s\x{FEFF}\x{00A0}]+$/u', '', $value);
    }
}
