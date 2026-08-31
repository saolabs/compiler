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
        'generateViewId', 'execute', 'evaluate', 'escString', 'text', 'templateToDom',
        'view', 'loadView', 'renderView', 'include', 'includeIf', 'extendView',
        'setSuperViewPath', 'addViewEngine', 'callViewEngineMounted',
        'startWrapper', 'endWrapper', 'registerSubscribe',
        'section', 'yield', 'yieldContent', 'renderSections', 'hasSection',
        'getChangedSections', 'resetChangedSections', 'isChangedSection', 'emitChangedSections',
        'push', 'stack', 'once', 'route', 'on', 'off', 'emit',
        'init', 'setApp', 'setContainer', 'clearOldRendering',
        'isAuth', 'can', 'cannot', 'hasError', 'firstError', 'csrfToken',
        'foreach', 'foreachTemplate',
    ];

    /** Hàm được nhận diện tường minh và gắn tiền tố trước vòng fallback. */
    public const KNOWN = [
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
