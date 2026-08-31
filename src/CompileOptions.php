<?php

declare(strict_types=1);

namespace Saola\Compiler;

final class CompileOptions
{
    public function __construct(
        public string $viewPath = 'test.view',
        public string $functionName = 'View',
        public string $factoryName = 'View',
        public string $namespace = '',
        public Target $emit = Target::Both,
        public Lang $lang = Lang::Js,
        public string $idMode = 'terse',
        public string $assetPrefix = '',
        public bool $sandbox = false,
        public ?string $importBaseDir = null,
        public ?string $bladeOutputPath = null,
        public ?string $jsOutputPath = null,
        public int $maxFiles = 500,
        public int $maxFileBytes = 524288,
        public int $maxTotalBytes = 20971520,
        public int $compileTimeout = 120,
        public int $maxIncludeDepth = 32,
        public string $publicBaseUrl = '',
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $emit = $data['emit'] ?? Target::Both;
        if (!$emit instanceof Target) {
            $emit = Target::tryFrom(strtolower((string) $emit))
                ?? throw new \InvalidArgumentException('emit phải là both, blade hoặc js.');
        }
        $lang = $data['lang'] ?? Lang::Js;
        if (!$lang instanceof Lang) {
            $lang = Lang::tryFrom(strtolower((string) $lang))
                ?? throw new \InvalidArgumentException('lang phải là js hoặc ts.');
        }

        return new self(
            viewPath: (string) ($data['viewPath'] ?? $data['view-path'] ?? 'test.view'),
            functionName: (string) ($data['functionName'] ?? $data['fn'] ?? 'View'),
            factoryName: (string) ($data['factoryName'] ?? $data['factory'] ?? ($data['functionName'] ?? $data['fn'] ?? 'View')),
            namespace: (string) ($data['namespace'] ?? ''),
            emit: $emit,
            lang: $lang,
            idMode: (string) ($data['idMode'] ?? $data['id-mode'] ?? 'terse'),
            assetPrefix: (string) ($data['assetPrefix'] ?? $data['asset-prefix'] ?? ''),
            sandbox: filter_var($data['sandbox'] ?? false, FILTER_VALIDATE_BOOL),
            importBaseDir: isset($data['importBaseDir']) ? (string) $data['importBaseDir'] : (isset($data['import-base-dir']) ? (string) $data['import-base-dir'] : null),
            bladeOutputPath: isset($data['bladeOutputPath']) ? (string) $data['bladeOutputPath'] : null,
            jsOutputPath: isset($data['jsOutputPath']) ? (string) $data['jsOutputPath'] : null,
            maxFiles: (int) ($data['maxFiles'] ?? 500),
            maxFileBytes: (int) ($data['maxFileBytes'] ?? 524288),
            maxTotalBytes: (int) ($data['maxTotalBytes'] ?? 20971520),
            compileTimeout: (int) ($data['compileTimeout'] ?? 120),
            maxIncludeDepth: (int) ($data['maxIncludeDepth'] ?? 32),
            publicBaseUrl: (string) ($data['publicBaseUrl'] ?? ''),
        );
    }
}
