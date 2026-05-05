# Saola Compiler: Architecture & Compilation Rules

Tài liệu này mô tả chi tiết các quy tắc biên dịch (compilation rules), luồng hoạt động của Saola Compiler, và cơ chế phân loại/render các component. Việc nắm rõ các quy tắc này là bắt buộc đối với các LLMs (AI) và Developer khi muốn maintain, refactor hoặc mở rộng Compiler.

---

## 1. Luồng Biên Dịch (Compilation Flow)

Saola Compiler hoạt động theo cơ chế 2 bước chính khi chuyển đổi từ file `.sao` sang `.js`/`.ts`:

1. **Preprocessor (JS)**: Chuyển đổi cú pháp Saola (`@vars`, `@states`, JS Template Literals) về dạng PHP/Blade trung gian để tương thích với AST parser cũ.
   - *Quy tắc quan trọng*: Preprocessor sẽ tìm các Template Literals chứa toán tử 3 ngôi (ternary) và bọc chúng trong dấu ngoặc đơn `()` trước khi nối chuỗi PHP để tránh lỗi sai thứ tự ưu tiên (precedence). Ví dụ: `${user ? a : b}` → `($user ? a : b)`.
2. **AST Parser & Code Generator (Python)**: Phân tích file PHP/Blade trung gian thành AST Tree, sau đó dịch từng Node sang mã lệnh JS tương ứng cho `View` và `ViewController`.

---

## 2. Phân loại Dữ Liệu (Data Reactivity)

Saola hỗ trợ hai loại dữ liệu chính trong View, và Compiler phải bóc tách chính xác chúng để tạo ra các hàm render tối ưu nhất.

### 2.1. Declared Variables (`@vars`, `@props`, `@let`, `@const`)
- **Mục đích**: Dữ liệu một chiều (one-way), thường nhận từ Server (SSR) hoặc từ Component cha truyền xuống.
- **Quy tắc Compiler**:
  - Không tạo ra Real State (không thông qua `__STATE__.__useState`).
  - Được cập nhật qua hàm `updateVariableData()`.
  - Các phần tử UI phụ thuộc vào biến này sẽ **được đánh dấu là Dynamic**, và chỉ được re-evaluate (tính toán lại) khi `updateVariableData()` được gọi.

### 2.2. Reactive State (`@useState`, `@states`)
- **Mục đích**: Dữ liệu hai chiều (two-way binding), thay đổi nội bộ trong Component ở phía Client.
- **Quy tắc Compiler**:
  - Tạo ra Real State thông qua `__STATE__.__useState()`.
  - Các phần tử UI phụ thuộc vào biến này sẽ **được đánh dấu là Reactive**, và sẽ tự động trigger update UI mỗi khi biến state thay đổi giá trị.

---

## 3. Phân Loại Section (`type`)

Dựa vào việc Section đó sử dụng loại dữ liệu nào ở trên, Compiler sẽ gán `type` tương ứng trong lúc generate code.

| Section Type | Điều Kiện (Triggers) | Quy Tắc Render (Behavior) |
|--------------|----------------------|---------------------------|
| `'static'` | Không dùng bất kỳ biến nào (`@vars` hoặc `@useState`) | Chỉ render 1 lần duy nhất, không bao giờ bị re-evaluate. |
| `'dynamic'` | Có sử dụng biến từ `@vars` / `@props` / `@let` | Bị re-evaluate khi có trigger `updateVariableData()`. |
| `'reactive'` | Có sử dụng biến từ `@useState` / `@states` | Re-render động 100% dựa vào sự thay đổi của State. |

*Lưu ý cho AI/Dev*: Hàm `_get_declared_vars` và `_get_state_vars` trong `template_ast.py` chịu trách nhiệm quét các biến này. Nó hỗ trợ tìm kiếm cả cú pháp PHP (`$var`) lẫn JS Bare Identifiers (`var`).

---

## 4. Cơ Chế Prerender & Hydration (Rất Quan Trọng)

Tính năng `prerender` dùng để tạo ra giao diện ngay lập tức khi load trang (skeleton hoặc SEO HTML), trong lúc chờ dữ liệu bất đồng bộ (`@await`, `@fetch`). Cơ chế này hoạt động khác nhau tùy thuộc vào việc View đó có dùng Layout (`@extends`) hay không.

### 4.1. Trường hợp CÓ `@extends`
- **Skeleton**: Khung Layout ngoài cùng sẽ đóng vai trò là skeleton.
- **Xử lý Section**: 
  - **Static Sections** (VD: SEO meta tags): Được đưa trực tiếp vào `prerender` function để hiển thị ngay lập tức (SEO bot có thể đọc được ngay).
  - **Dynamic Sections**: Nếu được cấu hình preloader (`preloader=True`), nó sẽ được bọc trong wrapper preloader và đưa vào `prerender`.
  - **prerenderSections Config**: Mảng này sẽ chứa **tất cả** các sections được đưa vào hàm prerender (VD: `["meta:title", "content", "sidebar"]`).

### 4.2. Trường hợp KHÔNG CÓ `@extends`
- **Skeleton**: Do không có Layout cha, Compiler phải tự động generate một thẻ `div` bọc ngoài cùng với class `data-preloader` bên trong hàm `prerender`.
- **Xử lý Section**:
  - **Static Sections**: VẪN được đăng ký ngay đầu hàm `prerender` (trước thẻ div skeleton). Điều này cực kỳ quan trọng cho các thẻ Meta SEO (`meta:title`, `meta:description`), giúp chúng xuất hiện lập tức khi SSR.
  - **Dynamic Sections**: Bị bỏ qua trong `prerender` vì chúng cần đợi data, và bản thân toàn bộ View lúc này đang hiển thị Skeleton loading rồi.
  - **prerenderSections Config**: Mảng này CHỈ chứa tên các **Static Sections** (VD: `["meta:description"]`).

### 4.3. Loại Bỏ Trùng Lặp (Duplicate Prevention)
Bất kỳ Section nào đã được đăng ký trong `prerender` function thì:
- Vẫn có thể xuất hiện trong `render` function.
- ViewManager (Client) sẽ tự động kiểm tra: nếu section là `static` và đã được gọi ở `prerender`, nó sẽ không gọi lại ở `render` nữa.

---

## 5. Danh Mục Các Class/Modules Chính

- `SaolaPreprocessor` (JS): Tiền xử lý, thu thập biến, format Template Literals.
- `SaoCompiler` / `BladeCompiler` (Python): Class chính nhận code đầu vào, điều phối luồng parser.
- `TemplateASTParser`: Phân tích HTML/Blade directives thành cấu trúc AST Node dạng cây.
- `RenderGenerator`: Duyệt cây AST và tạo ra JS strings cho hàm `render()`.
- `FunctionGenerators`: Tạo ra các hàm đặc thù như `prerender()`, `updateVariableData()`.
- `DeclarationTracker`: Theo dõi biến nào là State, biến nào là Vars để phục vụ phân loại Section type.
