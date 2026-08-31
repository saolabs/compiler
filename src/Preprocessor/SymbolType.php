<?php

declare(strict_types=1);

namespace Saola\Compiler\Preprocessor;

/**
 * Loại của một ký hiệu do người dùng khai báo.
 *
 * Quyết định transformer đối xử thế nào với định danh: thêm `$`, bung thành
 * `asset(...)`, hay để nguyên.
 *
 * Giá trị khớp chuỗi bên symbol-collector.js.
 */
enum SymbolType: string
{
    case State = 'state';
    case Setter = 'setter';
    case Var = 'var';
    case Local = 'local';
    case Constant = 'constant';

    /** `Func` chứ không phải `Function` — `function` là từ khoá của PHP. */
    case Func = 'function';

    case LoopVar = 'loop_var';
    case Asset = 'asset';
}
