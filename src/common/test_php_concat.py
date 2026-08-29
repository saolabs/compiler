"""Nối chuỗi PHP → JS: toán tử giữa hai vế phải còn nguyên.

_handle_string_concatenation bản cũ tách biểu thức theo cả KHOẢNG TRẮNG rồi nối
tất cả bằng '+', nên mọi toán tử nằm giữa hai vế nối bị nuốt:

    $count * 10 . '%'   →  count+*+10+'%'    (JS không parse được)
    $count + 1 . 'px'   →  count+++1+'px'

Lỗi này nằm im vì @style bị nhánh AST bỏ qua hoàn toàn; sửa @style xong thì
`@style({width: `${count * 10}%`})` của /demo phát ra biểu thức hỏng ngay.

Chạy: python3 -m pytest src/common/test_php_concat.py
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from common.php_converter import php_to_js


def test_toan_tu_giua_hai_ve_noi_khong_bi_nuot():
    assert php_to_js("$count * 10 . '%'") == "count * 10+'%'"
    assert php_to_js("$count + 1 . 'px'") == "count + 1+'px'"
    assert php_to_js("$w - $pad . 'px'") == "w - pad+'px'"


def test_noi_chuoi_thuong_van_nhu_cu():
    assert php_to_js("$count . '%'") == "count+'%'"
    assert php_to_js('$a . $b') == 'a+b'
    assert php_to_js("$w . '-' . $h") == "w+'-'+h"


def test_khong_co_dau_cham_thi_giu_nguyen():
    assert php_to_js('$count * 10') == 'count * 10'


def test_dau_cham_thap_phan_khong_phai_toan_tu_noi():
    assert php_to_js("$x . 1.5 . 'u'") == "x+1.5+'u'"


def test_chuoi_nhay_kep_thanh_template_literal():
    assert php_to_js('$name . " xin chào $who"') == 'name+` xin chào ${who}`'


# CHƯA sửa, bug có sẵn ở tầng khác: php_to_js cắt '$' NẰM TRONG chuỗi literal —
# php_to_js("'$foo'") ra "'foo'" kể cả khi không có nối chuỗi nào. Nằm ngoài
# _handle_string_concatenation nên không đụng tới ở đây.


if __name__ == '__main__':
    for name, fn in sorted(globals().items()):
        if name.startswith('test_'):
            fn()
    print('test_php_concat: OK')
