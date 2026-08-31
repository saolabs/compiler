#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Subject: chạy ScopedStyle + ChildrenSlot bản PHP. Cùng hợp đồng với oracle.py. */

require __DIR__ . '/../../../vendor/autoload.php';

use Saola\Compiler\Style\ScopedStyle;
use Saola\Compiler\Template\ChildrenSlot;
use Saola\Compiler\Template\ImportParser;
use Saola\Compiler\Template\TemplateStructure;

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }

    $call = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $args = $call['args'];

    try {
        $value = match ($call['fn']) {
            'scope_class_for' => ScopedStyle::classFor($args[0]),
            'scope_css' => ScopedStyle::apply($args[0], $args[1]),
            'extract_scoped_css' => ScopedStyle::extract($args[0]),
            'count_children_placeholders' => ChildrenSlot::count($args[0]),
            'replace_children_for_blade' => ChildrenSlot::replaceForBlade($args[0]),
            'replace_children_for_legacy_js' => ChildrenSlot::replaceForLegacyJs($args[0]),
            'validate_children_placeholders' => ChildrenSlot::validate($args[0]),
            'is_children_expression' => ChildrenSlot::isChildrenExpression($args[0]),
            // ImportParser có trạng thái — instance MỚI cho mỗi phép gọi
            // Ép object: map rỗng phải ra {} như Python, không phải []
            'parse_imports' => (object) (new ImportParser())->parseImports($args[0]),
            'remove_imports' => (new ImportParser())->removeImports($args[0]),
            'extract_tag_from_path' => ImportParser::extractTagFromPath($args[0]),
            'validate_imported_tag_structure' => (static function () use ($args) {
                TemplateStructure::validate($args[0], $args[1]);

                return null;   // Python trả None khi hợp lệ
            })(),
        };
        $result = ['ok' => true, 'value' => $value];
    } catch (Throwable $e) {
        // Tên lớp ngoại lệ phải khớp phía Python: ChildrenSlotError <-> ChildrenSlotError
        $short = substr(strrchr($e::class, '\\') ?: $e::class, 1);
        $result = ['ok' => false, 'value' => $short === '' ? $e::class : $short];
    }

    // Echo NGUYÊN VĂN dòng input: mã hoá lại sẽ lệch khoảng trắng với Python
    printf(
        "%s\t%s\n",
        $line,
        json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );
}
