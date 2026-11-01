<?php

declare(strict_types=1);

namespace Saola\Compiler\Expr;

/**
 * Danh sách tên hàm mà compiler biết — dữ liệu thuần, không logic.
 *
 * Port từ compiler/src/common/config.py (ViewConfig.VIEW_FUNCTIONS) và danh
 * sách `all_functions` / `_JS_BUILTINS` nội bộ trong
 * common/php_js_converter.py::_add_function_prefixes.
 *
 * Thứ tự các phần tử trong KNOWN có ý nghĩa: `HelperResolver` chạy một lượt
 * thay thế cho từng tên theo đúng thứ tự này. Đảo thứ tự có thể đổi kết quả khi
 * hai tên chồng lấn nhau, nên giữ nguyên trình tự của bản Python.
 */
final class KnownFunctions
{
    /** Hàm thuộc App.View — phần còn lại rơi về App.Helper. */
    public const VIEW = [
        'generateViewId', 'execute', 'evaluate', 'escString', 'templateToDom',
        'view', 'loadView', 'renderView', 'include', 'includeIf', 'extendView',
        'setSuperViewPath', 'addViewEngine', 'callViewEngineMounted',
        'startWrapper', 'endWrapper', 'registerSubscribe',
        'section', 'yield', 'yieldContent', 'renderSections', 'hasSection',
        'getChangedSections', 'resetChangedSections', 'isChangedSection', 'emitChangedSections',
        'push', 'stack', 'once', 'on', 'off', 'emit',
        'init', 'setApp', 'setContainer', 'clearOldRendering',
        'isAuth', 'can', 'cannot', 'hasError', 'firstError', 'csrfToken',
        'foreach', 'foreachTemplate',
    ];

    /** Hàm được nhận diện tường minh và gắn tiền tố trước vòng fallback. */
    public const KNOWN = [
        '__', 'text', 'trans', 'lang', 'trans_choice', 'choice', 'getLocale', 'setLocale',
        'count', 'min', 'max', 'abs', 'ceil', 'floor', 'round', 'sqrt',
        'strlen', 'substr', 'trim', 'ltrim', 'rtrim', 'strtolower', 'strtoupper',
        'isset', 'empty', 'is_null', 'is_array', 'is_string', 'is_numeric',
        'array_key_exists', 'in_array', 'array_merge', 'array_push', 'array_pop',
        'json_encode', 'json_decode', 'md5', 'sha1', 'base64_encode', 'base64_decode',
        'now', 'today', 'date', 'time', 'strtotime', 'mktime',
        'diffInDays', 'diffInHours', 'diffInMinutes', 'diffInSeconds',
        'addDays', 'subDays', 'addHours', 'subHours', 'addMinutes', 'subMinutes',
        'format', 'parse', 'createFromFormat',
        'env', 'config', 'auth', 'request', 'response', 'session', 'cache',
        'view', 'redirect', 'route', 'url', 'asset', 'mix',
        'collect', 'dd', 'dump', 'logger', 'abort', 'old', 'slug',
        'ucfirst', 'lcfirst', 'str_replace', 'explode', 'implode', 'array_unique',
        'formatDate', 'formatNumber', 'formatCurrency', 'truncate', 'number_format',
        'updateTitle', 'updateDescription', 'updateKeywords',
        'getUrlParams', 'buildUrl', 'isInViewport', 'scrollTo', 'copyToClipboard',
        'getDeviceType', 'isMobile', 'isTablet', 'isDesktop',
    ];

    /** HelperService public methods that the compiler must recognize as client-side. */
    public const CLIENT_HELPERS = [
        'applyLanguageConfig', 'installLanguage', 'loadLanguage', 'changeLanguage',
        'getLanguageScope', 'is_bool', 'is_int', 'is_float', 'is_object', 'sizeof',
        'mb_substr', 'mb_strtolower', 'mb_strtoupper', 'mb_strlen', 'ucwords',
        'str_ireplace', 'strpos', 'strrpos', 'stripos', 'str_starts_with',
        'str_ends_with', 'str_word_count', 'str_getcsv', 'htmlspecialchars_decode',
        'strip_tags', 'base64_encode', 'base64_decode', 'crc32', 'rawurlencode',
        'rawurldecode', 'array_shift', 'array_unshift', 'array_keys', 'array_values',
        'array_reverse', 'array_slice', 'array_splice', 'array_map', 'array_filter',
        'array_reduce', 'array_find', 'array_find_key', 'array_column', 'array_combine',
        'array_chunk', 'array_pad', 'array_flip', 'array_sum', 'array_product',
        'array_count_values', 'array_fill', 'array_fill_keys', 'array_intersect',
        'array_diff', 'array_intersect_key', 'array_diff_key', 'array_search',
        'ksort', 'krsort', 'rsort', 'usort', 'uasort', 'uksort', 'range', 'mt_rand',
        'array_rand', 'data_get', 'data_set', 'deepClone', 'escapeHtml', 'intdiv', 'fmod',
        'microtime', 'floatval', 'strval', 'boolval', 'gettype', 'function_exists',
        'class_exists', 'uniqid', 'var_dump', 'print_r',
    ];

    /** Tên có sẵn của JS/TS — không bao giờ gắn tiền tố. */
    public const JS_BUILTINS = [
        'function', 'return', 'if', 'else', 'for', 'while', 'do', 'switch',
        'case', 'break', 'continue', 'new', 'delete', 'typeof', 'instanceof',
        'void', 'throw', 'try', 'catch', 'finally', 'class', 'extends',
        'import', 'export', 'default', 'const', 'let', 'var', 'async', 'await',
        'yield', 'super', 'this', 'true', 'false', 'null', 'undefined',
        'Array', 'Object', 'String', 'Number', 'Boolean', 'Symbol', 'BigInt',
        'Math', 'JSON', 'Date', 'RegExp', 'Error', 'Map', 'Set', 'WeakMap',
        'WeakSet', 'Promise', 'Proxy', 'Reflect', 'parseInt', 'parseFloat',
        'isNaN', 'isFinite', 'encodeURIComponent', 'decodeURIComponent',
        'encodeURI', 'decodeURI', 'escape', 'unescape', 'eval', 'console',
        'setTimeout', 'setInterval', 'clearTimeout', 'clearInterval',
        'requestAnimationFrame', 'cancelAnimationFrame',
        'useState', 'updateRealState', 'lockUpdateRealState', 'updateStateByKey',
    ];

    public const VIEW_NAMESPACE = 'App.View';

    public const HELPER_NAMESPACE = 'App.Helper';

    private function __construct()
    {
    }

    public static function isViewFunction(string $name): bool
    {
        return in_array($name, self::VIEW, true);
    }

    public static function isJsBuiltin(string $name): bool
    {
        return in_array($name, self::JS_BUILTINS, true);
    }

    /** Tiền tố dành cho một tên hàm đã biết. */
    public static function namespaceFor(string $name): string
    {
        return self::isViewFunction($name)
            ? self::VIEW_NAMESPACE
            : self::HELPER_NAMESPACE;
    }
}
