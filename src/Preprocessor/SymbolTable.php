<?php

declare(strict_types=1);

namespace Saola\Compiler\Preprocessor;

/**
 * Bảng ký hiệu có phân tầng scope.
 *
 * Ký hiệu ở tầng gốc đến từ khai báo cấp file (`@states`, `@vars`, `@let`,
 * `@const`, `@asset`). Các tầng chồng lên là biến vòng lặp của `@foreach`,
 * chỉ sống trong thân vòng lặp.
 *
 * Tra cứu đi từ tầng TRONG CÙNG ra ngoài: biến vòng lặp che khuất ký hiệu
 * cùng tên ở tầng gốc.
 *
 * Tách khỏi {@see SymbolCollector} — bản JS gộp cả lưu trữ lẫn phân tích vào
 * một class. Lưu trữ có vòng đời riêng: transformer đẩy/gỡ scope trong lúc
 * duyệt template, rất lâu sau khi việc phân tích đã xong.
 */
final class SymbolTable
{
    /** @var array<string, Symbol> */
    private array $symbols = [];

    /** @var list<array<string, Symbol>> */
    private array $scopeStack = [];

    public function reset(): void
    {
        $this->symbols = [];
        $this->scopeStack = [];
    }

    public function add(string $name, Symbol $symbol): void
    {
        $this->symbols[$name] = $symbol;
    }

    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    /** Tra từ scope trong cùng ra ngoài. */
    public function get(string $name): ?Symbol
    {
        if (isset($this->symbols[$name])) {
            return $this->symbols[$name];
        }

        for ($i = count($this->scopeStack) - 1; $i >= 0; $i--) {
            if (isset($this->scopeStack[$i][$name])) {
                return $this->scopeStack[$i][$name];
            }
        }

        return null;
    }

    public function pushScope(): void
    {
        $this->scopeStack[] = [];
    }

    public function popScope(): void
    {
        array_pop($this->scopeStack);
    }

    /** Thêm vào scope trong cùng; nếu chưa có scope nào thì rơi về tầng gốc. */
    public function addScoped(string $name, Symbol $symbol): void
    {
        if ($this->scopeStack === []) {
            $this->add($name, $symbol);

            return;
        }

        $this->scopeStack[count($this->scopeStack) - 1][$name] = $symbol;
    }
}
