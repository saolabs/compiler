<?php

declare(strict_types=1);

namespace Saola\Compiler\Preprocessor;

/**
 * Hàm có sẵn của PHP — KHÔNG được thêm '$' khi chuyển Saola Syntax sang Blade.
 *
 * Định danh không nằm trong danh sách này VÀ không có trong bảng ký hiệu sẽ bị
 * coi là biến chưa khai báo.
 *
 * Port từ compiler/src/preprocessor/php-builtins.js.
 */
final class PhpBuiltins
{
    /** @var list<string> */
    private const NAMES = [
        // Mảng
        'count', 'sizeof', 'array_merge', 'array_push', 'array_pop',
        'array_shift', 'array_unshift', 'array_slice', 'array_splice',
        'array_keys', 'array_values', 'array_unique', 'array_reverse',
        'array_filter', 'array_map', 'array_reduce', 'array_search',
        'array_key_exists', 'array_combine', 'array_chunk', 'array_pad',
        'array_fill', 'array_flip', 'array_intersect', 'array_diff',
        'array_column', 'array_sum', 'array_product', 'array_rand',
        'array_walk', 'sort', 'rsort', 'asort', 'arsort', 'ksort', 'krsort',
        'usort', 'uasort', 'uksort', 'shuffle', 'compact', 'extract',
        'range', 'list', 'each', 'reset', 'end', 'next', 'prev', 'current',
        'in_array', 'implode', 'explode',

        // Chuỗi
        'strlen', 'substr', 'strpos', 'strrpos', 'strstr', 'stristr',
        'str_replace', 'str_ireplace', 'str_pad', 'str_repeat',
        'str_word_count', 'str_contains', 'str_starts_with', 'str_ends_with',
        'strtolower', 'strtoupper', 'ucfirst', 'lcfirst', 'ucwords',
        'trim', 'ltrim', 'rtrim', 'nl2br', 'wordwrap', 'chunk_split',
        'sprintf', 'printf', 'sscanf', 'number_format',
        'md5', 'sha1', 'crc32', 'base64_encode', 'base64_decode',
        'urlencode', 'urldecode', 'rawurlencode', 'rawurldecode',
        'htmlspecialchars', 'htmlentities', 'html_entity_decode',
        'strip_tags', 'addslashes', 'stripslashes', 'quotemeta',
        'preg_match', 'preg_match_all', 'preg_replace', 'preg_split',

        // Toán học
        'abs', 'ceil', 'floor', 'round', 'max', 'min', 'pow', 'sqrt',
        'log', 'log2', 'log10', 'exp', 'fmod', 'intdiv',
        'rand', 'mt_rand', 'random_int',

        // Kiểu
        'isset', 'empty', 'unset', 'is_null', 'is_array', 'is_string',
        'is_numeric', 'is_int', 'is_float', 'is_bool', 'is_object',
        'is_callable', 'gettype', 'settype', 'intval', 'floatval',
        'strval', 'boolval',

        // JSON
        'json_encode', 'json_decode',

        // Ngày giờ
        'date', 'time', 'mktime', 'strtotime', 'now', 'today',

        // Helper của Laravel
        'view', 'route', 'url', 'asset', 'mix', 'config', 'env',
        'auth', 'request', 'response', 'session', 'cache', 'cookie',
        'redirect', 'back', 'abort', 'app', 'resolve',
        'collect', 'dd', 'dump', 'logger', 'old', 'csrf_token',
        'trans', '__', 'e', 'event', 'dispatch', 'broadcast',
        'storage_path', 'resource_path', 'public_path', 'base_path',
        'class_basename',

        // Riêng của Blade
        'slot', 'component',
    ];

    /** @var array<string, true>|null Tra O(1), dựng một lần */
    private static ?array $lookup = null;

    private function __construct()
    {
    }

    public static function has(string $name): bool
    {
        self::$lookup ??= array_fill_keys(self::NAMES, true);

        return isset(self::$lookup[$name]);
    }
}
