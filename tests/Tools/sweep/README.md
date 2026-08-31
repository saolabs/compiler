# Sweep — săn lỗi im lặng ngoài tầm cổng parity

Cổng parity chứng minh **"port giống bản gốc"**, KHÔNG chứng minh **"bản gốc
đúng"**. Cả 5 bug ở docs/05-roadmap.md §15 đều xanh parity suốt vì Python và
PHP sai giống hệt nhau. Công cụ này để tìm đúng loại đó.

```bash
python3 gen.py     # sinh cases/*.sao (86 tổ hợp cú pháp)
python3 check.py   # soát bất biến SSR↔CSR, in ca nghi vấn
rm -rf cases       # dọn

python3 leak.py    # 48 ca: mã trong {{-- --}} / @verbatim có thành mã thật không
```

`leak.py` không cần `gen.py` — nó tự sinh ca. Bug "mã trong chú thích" đã xuất
hiện ở **sáu** khâu quét khác nhau (§16, §20, §21), mỗi lần vá một chỗ lại lộ
chỗ kế. Chạy lại nó sau mỗi lần thêm khâu quét mới.

## Bốn luật soát

| Luật | Bắt cái gì |
|---|---|
| `TAG` | thẻ có hydrate id ở Blade ≠ `this.html(...)` ở JS |
| `NO-ID` | thẻ trong wrapper mà thiếu hydrate id (nội dung bị bỏ quên) |
| `OUTPUT` | marker output reactive lệch hai phía |
| `ĐÃ BÁO` | compiler tự cảnh báo ⇒ không còn là lỗi im lặng, KHÔNG tính là bug |

`NO-ID` sinh ra sau khi ca `@block` thoát lưới: Blade không cấp id VÀ JS không
sinh thẻ, nên so hai danh sách rỗng vẫn "khớp" (§15⑤).

## Kiểm răng trước khi tin kết quả

Tắt `Html::splitInlineDirectives()` (thêm `return $template;` ở đầu hàm) →
phải thấy **14/86 ca đỏ**. Bật lại → 0. Sweep xanh mà chưa thử phá thì chưa
chứng minh được gì.

## Thêm ca mới

Sửa `cases` trong `gen.py`. Ca nào tìm ra bug thật thì chép sang
`tests/Parity/source-split/fixtures/` — corpus đó tự động vào cả cổng parity
lẫn marker-sync, tức là được canh mãi về sau.
