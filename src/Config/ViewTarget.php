<?php

declare(strict_types=1);

namespace Saola\Compiler\Config;

use Saola\Compiler\Lang;

/**
 * Một view `.sao` cùng mọi thứ suy ra được từ vị trí của nó trong cấu hình.
 *
 * Cách suy tên PHẢI khớp `compiler/src/index.js::processSaoFile` — tên khác
 * nhau nghĩa là Node và artisan sinh ra hai file khác nhau cho cùng một view.
 */
final class ViewTarget
{
    public function __construct(
        /** Đường dẫn tuyệt đối tới file .sao */
        public readonly string $source,
        /** vd `web.pages.home` */
        public readonly string $viewPath,
        /** Tên class, vd `Home` */
        public readonly string $functionName,
        /** Tên factory, vd `WebPagesHome` */
        public readonly string $factoryName,
        /** Đường dẫn tuyệt đối file .blade.php đầu ra */
        public readonly string $bladeOutput,
        /** Đường dẫn tuyệt đối file .js/.ts đầu ra */
        public readonly string $jsOutput,
        public readonly Lang $lang,
        public readonly string $namespace,
    ) {
    }
}
