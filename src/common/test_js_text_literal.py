"""Self-check cho js_text_literal: entity phải giải mã, string literal phải an toàn.

Chạy: python3 src/common/test_js_text_literal.py
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from common.utils import js_text_literal


def demo():
    # Entity giải mã — đây là lỗi gốc: createTextNode không tự giải mã.
    assert js_text_literal('&#64;states') == '@states'
    assert js_text_literal('Thuộc tính &amp; binding') == 'Thuộc tính & binding'
    assert js_text_literal('&lt;script setup&gt;') == '<script setup>'
    assert js_text_literal('() =&gt; count') == '() => count'

    # Ký tự không phải entity giữ nguyên.
    assert js_text_literal('R&D') == 'R&D'
    assert js_text_literal('Laravel 12 · 13') == 'Laravel 12 · 13'

    # String literal JS phải an toàn: nháy đơn, backslash, xuống dòng.
    assert js_text_literal("Don't") == "Don\\'t"
    assert js_text_literal('a\\b') == 'a\\\\b'
    assert js_text_literal('hai\ndòng') == 'hai dòng'
    assert js_text_literal('có\r\nCR') == 'có CR'

    # Giải mã TRƯỚC rồi mới escape — sai thứ tự thì &#39; thành nháy chưa escape.
    assert js_text_literal('&#39;') == "\\'"

    print('js_text_literal: OK')


if __name__ == '__main__':
    demo()
