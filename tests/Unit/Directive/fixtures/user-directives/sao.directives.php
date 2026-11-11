<?php

use Saola\Compiler\Directive\Sao;

// Mỗi loại khai báo một mẫu; mọi output là .sao, không dùng hàm runtime riêng
// của PHP/JS để Blade và JS đều render được nguyên văn.
return [
    Sao::directive('twice', fn (string $e) => "{{ ({$e}) * 2 }}"),
    Sao::block('guard', fn (string $e, string $body) => "@if({$e}){$body}@endif"),
    Sao::tag('when', fn (string $e) => ["@if({$e})", '@endif']),
    Sao::attr('tip', fn (string $e) => 'data-tip="{{ ' . $e . ' }}"'),
    Sao::attr('busy', fn () => 'aria-busy="true"', value: false),
];
