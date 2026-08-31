#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Subject: chạy API CÔNG KHAI SaolaCompiler::compile().
 *
 * Khác các subject khác — chúng gọi thẳng từng module. Cổng này đi qua đúng
 * đường mà người dùng thư viện đi, nên bắt được lỗi RÁP các mảnh lại với nhau.
 */

require __DIR__ . '/../../../vendor/autoload.php';

use Saola\Compiler\CompileOptions;
use Saola\Compiler\Lang;
use Saola\Compiler\SaolaCompiler;
use Saola\Compiler\Target;

const ASSET_PREFIX = 'static/parity/assets/';

while (($raw = fgets(STDIN)) !== false) {
    $raw = rtrim($raw, "\r\n");
    if ($raw === '') {
        continue;
    }

    $c = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

    try {
        $result = (new SaolaCompiler())->compile($c['source'], new CompileOptions(
            viewPath: $c['view'],
            functionName: $c['fn'],
            factoryName: $c['factory'],
            emit: Target::Both,
            lang: $c['lang'] === 'ts' ? Lang::Ts : Lang::Js,
            assetPrefix: ASSET_PREFIX,
        ));

        $payload = [
            'ok' => true,
            'blade' => base64_encode($result->blade ?? ''),
            'js' => base64_encode($result->js ?? ''),
        ];
    } catch (Throwable $e) {
        if (getenv('SAOLA_PARITY_DEBUG')) {
            fwrite(STDERR, $e . "\n");
        }
        $payload = [
            'ok' => false,
            'error' => (new ReflectionClass($e))->getShortName() . ':' . substr($e->getMessage(), 0, 200),
        ];
    }

    echo $c['name'] . "\t" . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}
