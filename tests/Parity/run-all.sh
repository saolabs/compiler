#!/usr/bin/env bash
#
# Chạy toàn bộ cổng parity. Đây là điều kiện gộp của mọi phase:
# bản PHP phải cho ra kết quả GIỐNG HỆT compiler Python.
#
# Mỗi phase port xong thì thêm một cổng vào đây.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FAILED=0

run_gate() {
    local name="$1" script="$2"
    shift 2

    echo "──────────────────────────────────────────────────────────"
    echo "▶ $name"
    if "$script" "$@"; then
        return 0
    fi
    FAILED=$((FAILED + 1))
}

echo "Cổng parity — Saola PHP Compiler"
echo

# Unit test chạy TRƯỚC: nó nhanh và phủ các nhánh chính sách (validate options,
# sandbox, tầng directive) mà cổng parity không chạm tới — hỏng ở đó thì biết
# ngay, khỏi đợi hết các cổng nặng.
if [[ -x "$DIR/../../vendor/bin/phpunit" ]]; then
    echo "──────────────────────────────────────────────────────────"
    echo "▶ Unit (PHPUnit)"
    if ! ( cd "$DIR/../.." && vendor/bin/phpunit --no-progress ); then
        FAILED=$((FAILED + 1))
    fi
else
    echo "⚠️  Bỏ qua unit test — chưa chạy composer install"
fi
echo

run_gate "HydrateId (mã hoá)"      "$DIR/hydrate-id/run.sh"
run_gate "HydrateIdGenerator"      "$DIR/id-generator/run.sh" 20000 1
run_gate "HydrateIdGenerator (2)"  "$DIR/id-generator/run.sh" 20000 1337
run_gate "Biểu thức"               "$DIR/expression/run.sh"
run_gate "SourceSplitter"          "$DIR/source-split/run.sh"
run_gate "Balanced (quét ngoặc)"   "$DIR/balanced/run.sh"
run_gate "SymbolCollector"         "$DIR/symbol-collector/run.sh"
run_gate "Preprocessor"            "$DIR/preprocessor/run.sh"
run_gate "ScopedStyle+ChildrenSlot" "$DIR/common-utils/run.sh"
run_gate "DeclarationTracker"      "$DIR/declarations/run.sh"
run_gate "ImportTagResolver"       "$DIR/import-tag-resolver/run.sh"
run_gate "BladeHydrateProcessor"   "$DIR/hydrate-processor/run.sh"
run_gate "BladeEmitter"            "$DIR/blade-emit/run.sh"
run_gate "TemplateASTParser"       "$DIR/template-ast/run.sh"
run_gate "DirectiveParsers"        "$DIR/directive-parsers/run.sh"
run_gate "Template helpers"        "$DIR/template-helpers/run.sh"
run_gate "Section handlers"        "$DIR/section-handlers/run.sh"
run_gate "Conditional handlers"    "$DIR/conditional-handlers/run.sh"
run_gate "Loop handlers"           "$DIR/loop-handlers/run.sh"
run_gate "DirectiveProcessor"      "$DIR/directive-processor/run.sh"
run_gate "TemplateProcessors"      "$DIR/template-processors/run.sh"
run_gate "TemplateProcessor"       "$DIR/template-processor/run.sh"
run_gate "JsEmitter"               "$DIR/js-emitter/run.sh"
run_gate "EventDirectiveProcessor" "$DIR/event-processor/run.sh"
run_gate "Compiler support"        "$DIR/compiler-support/run.sh"
run_gate "Main compiler"           "$DIR/main-compiler/run.sh"

run_gate "ĐẦU-CUỐI (API công khai)"  "$DIR/full-pipeline/run.sh"
run_gate "Examples (golden)"        "$DIR/examples/run.sh"
run_gate "Node transport (index.js)" "$DIR/node-transport/run.sh"
run_gate "Marker sync (blade↔js)"   "$DIR/marker-sync/run.sh"

echo "──────────────────────────────────────────────────────────"
if [[ $FAILED -eq 0 ]]; then
    echo "✅ Mọi cổng parity đều xanh"
    exit 0
fi

echo "❌ $FAILED cổng hỏng"
exit 1
