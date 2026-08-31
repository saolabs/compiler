<?php

declare(strict_types=1);

namespace Saola\Compiler\Preprocessor;

/** Loại token do {@see Tokenizer} sinh ra. Giá trị khớp chuỗi bên bản JS. */
enum TokenType: string
{
    case Whitespace = 'whitespace';
    case TemplateLiteral = 'template_literal';
    case Str = 'string';
    case Number = 'number';
    case Keyword = 'keyword';
    case Identifier = 'identifier';
    case Operator = 'operator';

    /** Sinh ra khi '+' được xác định là nối chuỗi chứ không phải phép cộng. */
    case PhpConcat = 'php_concat';
}
