# Cổng parity

Bản PHP phải cho ra kết quả **giống hệt từng byte** với compiler Python đang chạy
production. Thư mục này là chỗ chứng minh điều đó.

```bash
./run-all.sh
```

## Cách hoạt động

Mỗi cổng có ba phần cùng chung một hợp đồng stdin/stdout:

| File | Vai trò |
|---|---|
| `oracle.py` | Chạy bằng **compiler Python thật** trong `builder/.reference/python/src/` — đây là chuẩn |
| `subject.php` | Chạy bằng bản PHP trong `src/` |
| `run.sh` | Nạp input cho cả hai, `diff` kết quả. Lệch một byte là hỏng |

Bản Python không phải nợ kỹ thuật trong giai đoạn này — **nó là bộ test**. Chỉ
gỡ đi sau khi parity sạch qua một chu kỳ release (Phase 7).

## Các cổng hiện có

### `hydrate-id/` — mã hoá id

Đối chiếu `HydrateId::hash()` với `hydrate_hash()` trên cả 4 mode
(`terse`/`compact`/`md5`/`raw`).

Corpus gồm hai nguồn:

- **Id thật** — compile toàn bộ `.sao` trong repo với `SAOLA_ID_MODE=raw` rồi bóc
  id ra. Ở mode raw, id trong output chính là base_id chưa mã hoá.
- **Id tổng hợp** — sinh theo văn phạm id, nhắm vào ca biên mà view thật không
  chạm tới: chỉ số >= 10, `block-outlet`, `yield`, lồng sâu.

```bash
./hydrate-id/run.sh --rebuild    # dựng lại corpus rồi chạy
```

### `id-generator/` — bộ đếm theo scope

Chạy cùng một dãy thao tác ngẫu nhiên (`ops.py`) qua cả hai bản
`HydrateIdGenerator`, so từng giá trị trả về.

```bash
./id-generator/run.sh 20000 1337     # 20.000 thao tác, seed 1337
```

Dãy ngẫu nhiên chạm được các tổ hợp push/pop lồng nhau mà test viết tay khó nghĩ
ra — mà đó chính là nơi bộ đếm theo scope dễ lệch nhất.

### `expression/` — chuyển biểu thức

Corpus lấy bằng cách **gài spy vào `php_to_js()`** rồi compile cả 56 view — phân
bố input thật của app, không phải test bịa. Nhưng 87% biểu thức thật đi qua
converter mà không đổi, nên `synthetic.py` bổ sung 163 ca viết tay nhắm đúng
các nhánh biến đổi.

### `source-split/` — tách file `.sao`

**Oracle ở đây là JAVASCRIPT**, không phải Python: `parseSaoFile` nằm trong
`builder/src/index.js`. Xem [docs/01-architecture.md §3](../../docs/01-architecture.md).

56 file thật + 16 fixture ép ca biên: thẻ bọc lồng nhau, hai thẻ cấp ngoài cùng,
thẻ không đóng, khối `@ssr`, khai báo trong thẻ bọc, ngoặc lồng, BOM + khoảng
trắng Unicode, tiếng Việt, đủ 11 loại khai báo.

### `balanced/` — quét ngoặc dùng chung

48 chuỗi méo (ngoặc lệch loại, '=' ở vị trí 0, nháy escape) × 5 phép quét.

Tồn tại vì cổng end-to-end trên file thật KHÔNG có răng ở đây — file thật luôn
có ngoặc cân bằng.

So CẶP CHUỖI hai bên dấu '=' chứ không so chỉ số thô: JS đánh chỉ số theo code
unit UTF-16, PHP theo byte, nên `"tên = 1"` cho 4 và 5. Cả hai đều cắt bằng chỉ
số của chính mình nên hai nửa thu được giống hệt — cái cần khớp là hành vi quan
sát được, không phải biểu diễn nội bộ.

### `symbol-collector/` và `preprocessor/` — lượt 1 và lượt 2

Tách hai cổng để cô lập lỗi: bảng ký hiệu sai thì cổng đầu đỏ trước, không phải
đi lần ngược từ output cuối.

### `import-tag-resolver/` — thẻ component đã import

Đối chiếu việc đổi thẻ tự đóng thành `@include` và thẻ cặp có children thành
`@importInclude`. Corpus gồm 26 ca tổng hợp nhắm attributes, nesting, Unicode
và input méo, cộng hai target JS/Blade trên mọi view thật có `@import`.

### `hydrate-processor/` — marker SSR và hydrate class

Chạy 17 ca tổng hợp và 85 input đi qua pipeline thật trên đủ bốn mode id
(`terse`, `compact`, `md5`, `raw`). Đây là cổng bắt lệch marker trước khi output
được ráp vào template Blade cuối.

### `blade-emit/` — Blade emitter

Cổng end-to-end của Phase 3: 56 view production + 29 fixture được ráp đầu vào
đúng như `index.js::processSaoFile`, rồi so output `.blade.php` từng byte.

`corpus.js` tái dựng chuỗi blade đã ráp mà `index.js::processSaoFile` đưa cho
sao2blade — khai báo đã qua preprocessor + template bọc thẻ wrapper + khối
`<style scoped>` nguyên văn. sao2blade không nhận `.sao` thô.

### `examples/` — golden

So PHP với ẢNH CHỤP output đã commit (`examples/expected/`), không phải với
Python. Hai bảo chứng khác nhau:

```
parity  → "PHP làm giống Python"        (mất khi Python bị gỡ ở P6)
golden  → "PHP không đổi ngoài ý muốn"  (còn mãi)
```

Golden do compiler tự sinh (`examples/regenerate.sh`), không viết tay. Lỗ hổng
hiển nhiên của cách đó — compiler sai vẫn sinh golden sai nhất quán — được bịt
bằng việc examples CŨNG chạy qua `full-pipeline`.

### `node-transport/` — đường vận chuyển Node↔PHP

Cổng duy nhất chạy `builder/src/index.js` THẬT. Mọi cổng khác gọi thẳng
`bin/saoc` hoặc `SaolaCompiler::compile()`, nên vùng Node spawn PHP → giải mã
stdout → ráp registry → ghi file không được ai kiểm. Bug ⑤ (vỡ UTF-8 ở ranh
giới chunk) sống đúng trong vùng đó và **lọt qua 28 cổng xanh**.

Chạy trên project sandbox tạm, ba phép kiểm:

| Phép | Bắt được gì |
|---|---|
| TOÀN VẸN | ký tự U+FFFD trong output — UTF-8 vỡ khi giải mã theo chunk |
| TRUNG THỰC | file `index.js` ghi ra khác output `saoc compile` trực tiếp |
| TÁI LẬP | hai lần build cùng nguồn ra kết quả khác nhau |

Corpus gồm 56 view thật **cộng 8 file stress** sinh tự động: nội dung gần như
toàn ký tự nhiều byte, kích thước lệch nhau. Không dựa vào may rủi của một view
thật nào — đã kiểm: bỏ hết view thật, chỉ 8 file stress vẫn bắt được 5 file hỏng.

## Cổng có "răng" không?

Một cổng luôn xanh chưa chắc là cổng tốt — nó có thể chỉ đang không chạm tới
đoạn code cần kiểm. Cách kiểm tra: cố tình phá một chỗ rồi xem cổng có đỏ không.

Đã kiểm:

| Phá gì | Kết quả |
|---|---|
| `jsTrim()` → `trim()` của PHP | ✅ bắt được — fixture 14 đỏ, khoảng trắng Unicode còn sót |
| `replaceFirst()` → `str_replace()` | ⚠️ **không** bắt được |

Trường hợp thứ hai là thật: với các file hiện có, hai ngữ nghĩa cho cùng kết
quả (khai báo trùng nhau được gỡ hết ở cả hai cách). `replaceFirst` vẫn giữ vì
đó mới là port đúng của `String.replace(chuỗi)` bên JS, nhưng **fixture 09 chưa
chứng minh được gì** — ghi ra đây để không ai tưởng nhầm là đã có bảo chứng.

## Thêm cổng mới

1. Tạo `tests/Parity/<tên>/` với `oracle.py` + `subject.php` + `run.sh`
2. Giữ nguyên hợp đồng: stdin nhận input, stdout in kết quả có thể diff
3. Thêm một dòng `run_gate` vào `run-all.sh`
