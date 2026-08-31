<?php

declare(strict_types=1);

namespace Saola\Compiler\Directive;

use Saola\Compiler\Preprocessor\ExpressionTransformer;
use Saola\Compiler\Support\Re;
use InvalidArgumentException;
use Saola\Compiler\Directive\Builtin\BlockDirective;
use Saola\Compiler\Directive\Builtin\BuiltinDirective;
use Saola\Compiler\Directive\Builtin\ConstDirective;
use Saola\Compiler\Directive\Builtin\EndBlockDirective;
use Saola\Compiler\Directive\Builtin\ExtendsDirective;
use Saola\Compiler\Directive\Builtin\FetchDirective;
use Saola\Compiler\Directive\Builtin\InitDirective;
use Saola\Compiler\Directive\Builtin\LetDirective;
use Saola\Compiler\Directive\Builtin\OnBlockDirective;
use Saola\Compiler\Directive\Builtin\PropsDirective;
use Saola\Compiler\Directive\Builtin\StatesDirective;
use Saola\Compiler\Directive\Builtin\UseBlockDirective;
use Saola\Compiler\Directive\Builtin\UseStateDirective;
use Saola\Compiler\Directive\Builtin\VarsDirective;
use Saola\Compiler\Directive\Builtin\ViewTypeDirective;

/** Internal registry in P4; made an extension API by the Laravel integration in P5. */
final class DirectiveRegistry
{
    /** @var array<string, true> */
    private const LOCKED = [
        'if'=>true, 'elseif'=>true, 'else'=>true, 'foreach'=>true, 'for'=>true,
        'while'=>true, 'switch'=>true, 'case'=>true, 'default'=>true, 'break'=>true,
        'startMarker'=>true, 'endMarker'=>true, 'startReactive'=>true,
        'endReactive'=>true, 'pageStart'=>true, 'pageEnd'=>true, 'hydrate'=>true,
        'out'=>true,
    ];

    /** @var array<string, true> */
    /**
     * Directive tầng T1 bổ sung, LẤY TỪ ExpressionTransformer.
     *
     * 32 directive (@class, @style, @attr, @bind, mọi @click/@change/... ) từng
     * KHÔNG nằm trong tầng nào, nên đăng ký đè chúng được chấp nhận mà không
     * cần cờ và không cảnh báo. Hậu quả im lặng: đè @class làm mất class điều
     * kiện và chèn thêm `'X' => true` vào @attr, không lỗi gì.
     *
     * Lấy từ hằng của ExpressionTransformer thay vì chép lại — hai bản chép
     * tay chắc chắn lệch nhau theo thời gian.
     *
     * @return array<string, true>
     */
    private static function elementTier(): array
    {
        return array_fill_keys(array_merge(
            ExpressionTransformer::EVENT_DIRECTIVES,
            ExpressionTransformer::BIND_DIRECTIVES,
            ExpressionTransformer::ELEMENT_DIRECTIVES,
        ), true);
    }

    private const CORE = [
        'useState'=>true, 'states'=>true, 'vars'=>true, 'props'=>true, 'let'=>true,
        'const'=>true, 'computed'=>true, 'include'=>true, 'importInclude'=>true,
        'children'=>true, 'extends'=>true, 'yield'=>true, 'section'=>true,
        'block'=>true, 'wrapper'=>true, 'await'=>true, 'fetch'=>true,
        'subscribe'=>true,
    ];

    /** @var array<string, BuiltinDirective> */
    private array $directives = [];

    /** @var array<string, UserDirective> */
    private array $userDirectives = [];

    public static function builtins(?DirectiveParsers $parsers = null): self
    {
        $parsers ??= new DirectiveParsers();
        return new self([
            new ExtendsDirective($parsers), new VarsDirective($parsers),
            new PropsDirective($parsers), new LetDirective($parsers),
            new ConstDirective($parsers), new UseStateDirective($parsers),
            new StatesDirective($parsers), new FetchDirective($parsers),
            new InitDirective($parsers), new ViewTypeDirective($parsers),
            new BlockDirective($parsers), new EndBlockDirective($parsers),
            new UseBlockDirective($parsers), new OnBlockDirective($parsers),
        ]);
    }

    /** @param iterable<BuiltinDirective> $directives */
    public function __construct(iterable $directives = [])
    {
        foreach ($directives as $directive) $this->register($directive);
    }

    public function register(BuiltinDirective $directive): void
    {
        $this->directives[$directive->name()] = $directive;
    }

    public function parse(string $name, string $source): mixed
    {
        return ($this->directives[$name] ?? throw new InvalidArgumentException("Unknown directive: {$name}"))->parse($source);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_values(array_unique([...array_keys($this->directives), ...array_keys($this->userDirectives)]));
    }

    /** @param callable(string, ...string): array{blade:string,js:string} $handler */
    public function directive(string $name, callable $handler, bool $override = false): self
    {
        return $this->registerUser(new UserDirective($name, $handler), $override);
    }

    /** @param callable(string, string): array{blade:string,js:string} $handler */
    public function blockDirective(string $name, callable $handler, bool $override = false): self
    {
        return $this->registerUser(new UserDirective($name, $handler, true), $override);
    }

    public function registerUser(UserDirective $directive, bool $override = false): self
    {
        if (isset(self::LOCKED[$directive->name])) {
            throw new InvalidArgumentException("Directive @{$directive->name} thuộc tầng T0 và không thể ghi đè.");
        }
        if ((isset(self::CORE[$directive->name]) || isset(self::elementTier()[$directive->name])) && !$override) {
            throw new InvalidArgumentException("Directive @{$directive->name} thuộc tầng T1; cần override: true.");
        }
        $this->userDirectives[$directive->name] = $directive;
        return $this;
    }

    public function transform(string $source, string $target): string
    {
        if (!in_array($target, ['blade', 'js'], true)) {
            throw new InvalidArgumentException("Đích directive không hợp lệ: {$target}");
        }
        if ($this->userDirectives === []) {
            return $source;
        }

        // @verbatim và comment Blade phải giữ NGUYÊN VĂN: nội dung bên trong là
        // văn bản, không phải directive. Không che thì trang docs in ví dụ
        // `@money(2)` sẽ bị chính directive @money của người dùng viết lại —
        // tài liệu hiện ra thứ khác với thứ nó đang mô tả.
        $regions = [];
        $source = Re::replaceCallback(
            '/\{\{--.*?--\}\}|@verbatim\b.*?@endverbatim\b/si',
            static function (array $m) use (&$regions): string {
                $placeholder = '__SAO_DIRECTIVE_SKIP_' . count($regions) . '__';
                $regions[] = $m[0];

                return $placeholder;
            },
            $source,
        );

        foreach ($this->userDirectives as $directive) {
            $source = $this->transformOne($source, $directive, $target);
        }

        foreach ($regions as $i => $region) {
            $source = str_replace('__SAO_DIRECTIVE_SKIP_' . $i . '__', $region, $source);
        }

        return $source;
    }

    private function transformOne(string $source, UserDirective $directive, string $target): string
    {
        $needle = '@'.$directive->name;
        $offset = 0;
        $iterations = 0;
        while (($start = strpos($source, $needle, $offset)) !== false) {
            if (++$iterations > 10000) {
                throw new \RuntimeException("Quá nhiều lần mở rộng @{$directive->name}.");
            }
            $afterName = $start + strlen($needle);
            if (isset($source[$afterName]) && preg_match('/[A-Za-z0-9_]/', $source[$afterName]) === 1) {
                $offset = $afterName;
                continue;
            }
            while (isset($source[$afterName]) && ctype_space($source[$afterName])) $afterName++;
            if (($source[$afterName] ?? '') !== '(') {
                $offset = $afterName;
                continue;
            }
            [$expression, $end] = \Saola\Compiler\Support\Balanced::extractParensAt($source, $afterName);
            if ($expression === null) {
                throw new \RuntimeException("Directive @{$directive->name} thiếu dấu đóng ngoặc.");
            }

            $body = null;
            $replaceEnd = $end;
            if ($directive->block) {
                $close = '@end'.$directive->name;
                $closeAt = strpos($source, $close, $end);
                if ($closeAt === false) {
                    throw new \RuntimeException("Directive @{$directive->name} thiếu {$close}.");
                }
                $body = substr($source, $end, $closeAt - $end);
                $replaceEnd = $closeAt + strlen($close);
            }
            $replacement = $directive->emit($target, trim($expression), $body);
            $source = substr($source, 0, $start).$replacement.substr($source, $replaceEnd);
            $offset = $start + strlen($replacement);
        }
        return $source;
    }
}
