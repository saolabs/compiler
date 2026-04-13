# Saola v3 — Kiến Trúc & Kế Hoạch Triển Khai

> **Mục tiêu**: Chuyển từ string-based rendering (core/) sang element-tree rendering (one/) — render trực tiếp DOM elements theo thứ tự cha → con, hỗ trợ view extends/layout giống Laravel Blade, với hiệu năng vượt trội.

---

## 1. TỔNG QUAN HIỆN TRẠNG

### 1.1 Hệ thống CŨ (`src/core/`) — String Template

```
View.render() → HTML string → innerHTML → scan DOM tìm markers → gắn reactive bindings
```

| Ưu điểm | Nhược điểm |
|----------|-----------|
| Tương thích SSR Laravel (Blade) | Mỗi re-render tạo lại toàn bộ DOM trong reactive region |
| Comment markers giúp track reactive regions | Mất focus, scroll, input state khi re-render |
| Layout chain (super view) đã hoạt động | ViewManager 1145 dòng, quá nhiều trách nhiệm |
| Router đầy đủ (history/hash, guards, named routes) | `Reactive.refresh()` / `start()`/`stop()` là stub |
| `useState` batched qua RAF | String concatenation chậm với cây DOM lớn |

### 1.2 Hệ thống MỚI (`src/one/`) — Element Tree (Đang viết)

```
View.render() → html(), reactive(), output(), fragment() → tạo DOM elements trực tiếp → chèn vào DOM
```

**Đã triển khai:**

| Module | File | Trạng thái |
|--------|------|-----------|
| `Html` | elements/Html.ts | ✅ Hoàn thiện — tạo element, attrs, events, classes/styles reactive bindings, start/stop lifecycle |
| `Reactive` | elements/Reactive.ts | ✅ Hoàn thiện — comment markers, mount/re-render/clearContent, parent-child, start/stop |
| `Fragment` | elements/Fragment.ts | ✅ Hoàn thiện — multiple root nodes, comment markers, children tracking, start/stop |
| `TextElement` | elements/TextElement.ts | ✅ Reactive text updates in-place, start/stop |
| `Output` | elements/Output.ts | ✅ Hoàn thiện — render/start/stop/update/destroy, comment markers, escapeHTML |
| `Block` | elements/Block.ts | ⚠️ Cơ bản — mount() hoạt động, cần test e2e với BlockManager |
| `BlockOutlet` | elements/BlockOutlet.ts | ✅ Hoàn thiện — render markers, setParent, start/stop |
| `BlockManager` | elements/BlockManager.ts | ✅ Hoàn thiện — mountAll(), clearOutlet(), clearAllOutlets(), destroy() |
| `ElementManager` | elements/ElementManager.ts | ✅ Hoàn thiện — factory shortcuts h/t/r/f/o/b |
| `View` | view/View.ts | ✅ Thin wrapper + lifecycle hooks |
| `ViewController` | view/ViewController.ts | ✅ Hoàn thiện — setup(), commitData(), updateData(), start()/stop(), render(), events, reactive scheduling, loop directives |
| `ViewState` / `StateManager` | view/ViewState.ts | ✅ Hoàn thiện — useState, subscribe, batched RAF, canUpdateStateByKey, lock/unlock, updateRealState |
| `LoopContext` | view/LoopContext.ts | ✅ Hoàn thiện |
| `Application` | app/Application.ts | ✅ DI Container đầy đủ (tốt hơn core/) |
| `MarkerRegistry` | services/MarkerRegistry.ts | ✅ Hoàn thiện — tag shortcuts, record registry, query API |
| `index.ts` | elements/index.ts | ✅ Hoàn thiện — factory functions (fragment, html, output, reactive, text, block, blockOutlet, __foreach, __while, __exec) |
| Contracts | contracts/utils.ts | ✅ Hoàn thiện — ViewControllerContract mở rộng, loop directives, App, start/stop |
| Types | types/utils.ts | ✅ Hoàn thiện — ViewSetupConfig, OneChildrenFactory, HtmlElementConfig |

**Chưa có:**

| Module | Mô tả |
|--------|-------|
| Router | Chưa có — cần port hoặc viết mới |
| ViewManager | Chưa có — orchestrator cho view lifecycle, layout chain |
| ViewLoader | Chưa có — dynamic module loading |
| SSR Hydration | Chưa có — scan DOM markers → attach bindings |
| Services (Event, Http, Store, Storage) | Chưa có — có thể reuse từ core/ |

---

## 2. SO SÁNH HIỆU NĂNG VỚI CÁC FRAMEWORK KHÁC

### 2.1 Bảng so sánh kiến trúc

| Tiêu chí | Saola (cũ) | Saola (mới) | React | Vue 3 | Svelte 5 | Inertia.js |
|----------|-------------|---------------|-------|-------|----------|-----------|
| **Render mechanism** | String → innerHTML | Direct DOM creation | Virtual DOM diff | Virtual DOM + Proxy | Compiled DOM ops | Server-driven (delegated to React/Vue/Svelte) |
| **Reactivity** | Manual subscribe + RAF batch | Manual subscribe + RAF batch | setState → reconcile tree | Proxy-based auto-track | Compiler-generated signals | Delegated to frontend framework |
| **Update granularity** | Entire reactive region re-rendered | Targeted — chỉ update Output/Reactive thay đổi | Component subtree re-render + diff | Component subtree với proxy tracking | Surgical — compiler biết chính xác node nào | Delegated |
| **Memory** | Template strings + DOM scan | Lightweight element wrappers | VDOM tree in memory (double) | VDOM + Proxy wrappers | Minimal — no VDOM | Depends on framework |
| **First render** | Parse string → innerHTML (fast) | Create elements sequentially (fast) | Create VDOM → diff → DOM | Create VDOM → diff → DOM | Direct DOM creation (fastest) | SSR HTML + hydrate |
| **Re-render** | Rebuild string → replace innerHTML | Only re-run changed factory | Diff entire subtree | Diff affected components | Surgical update (fastest) | Full page component swap |
| **Bundle size** | ~50KB (all services) | Target ~30KB (tree-shakeable) | ~45KB (react+react-dom) | ~33KB (vue) | ~2KB (runtime) | ~15KB + framework |
| **SSR** | Laravel Blade native | Laravel Blade native | Node.js server needed | Node.js server needed | Node.js server needed | Laravel native |
| **Layout/Extends** | Blade-style @extends/@section | Blade-style (target) | Component composition | Component + slots | Component + slots | Persistent layouts |

### 2.2 Lợi thế cạnh tranh của Saola mới

1. **Laravel-native SSR** — Không cần Node.js server. Blade render HTML, Saola hydrate. React/Vue/Svelte đều cần Node.js cho SSR.
2. **Blade syntax quen thuộc** — Laravel devs dùng ngay, không cần học JSX/SFC.
3. **No VDOM overhead** — Giống Svelte, compiler biết chính xác element nào cần update. Không tốn memory cho virtual tree.
4. **Direct DOM manipulation** — First render nhanh vì tạo element trực tiếp, không qua VDOM diff.
5. **Surgical updates** — `Output` chỉ update `textContent` khi state thay đổi. `Reactive` chỉ re-run factory khi `stateKeys` liên quan thay đổi.
6. **Inertia.js alternative** — Inertia cần React/Vue/Svelte frontend. Saola là framework đầy đủ, tích hợp sâu với Laravel, không cần thêm framework ngoài.

### 2.3 Điều kiện để vượt trội

| Yêu cầu | Giải pháp |
|----------|----------|
| First render phải nhanh bằng hoặc hơn innerHTML | ✅ `document.createElement` + `appendChild` nhanh hơn innerHTML cho cây nhỏ-trung bình (<1000 nodes) |
| Re-render phải surgical | ✅ Comment markers + `stateKeys` tracking → chỉ update affected nodes |
| List re-render phải có keyed reconciliation | ❌ Chưa có — cần implement (xem Phase 3) |
| Memory footprint phải thấp | ✅ Không giữ VDOM tree. Element wrappers nhẹ. |
| Bundle phải tree-shakeable | ⚠️ Cần refactor — tách services thành independent modules |

---

## 3. KIẾN TRÚC ĐỀ XUẤT (one/ v3)

### 3.1 Tổng quan architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                         Application (DI Container)               │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌──────────┐   ┌──────────────┐   ┌────────────────────────┐   │
│  │  Router   │──▶│ ViewManager  │──▶│ Mount Pipeline          │   │
│  │          │   │              │   │                          │   │
│  │ navigate │   │ loadView()   │   │ 1. Resolve layout chain │   │
│  │ guards   │   │ mountView()  │   │ 2. Collect blocks       │   │
│  │ cache    │   │ unmountView()│   │ 3. Render outermost     │   │
│  └──────────┘   │ scanView()   │   │ 4. Mount into container │   │
│                  └──────────────┘   │ 5. Start all views      │   │
│                                      └────────────────────────┘   │
│                                                                   │
│  ┌─────────────────── View Instance ─────────────────────────┐   │
│  │                                                             │   │
│  │  View { __ctrl__: ViewController }                         │   │
│  │    │                                                       │   │
│  │    ├── ViewState (StateManager)                            │   │
│  │    │     └── useState() → [value, setter, key]            │   │
│  │    │     └── subscribe(keys, callback) → unsubscribe      │   │
│  │    │     └── Batched flush via requestAnimationFrame       │   │
│  │    │                                                       │   │
│  │    ├── Element Tree (rendered output)                      │   │
│  │    │     ├── Fragment (root)                               │   │
│  │    │     │   ├── Html("div") ─── children ──┐             │   │
│  │    │     │   │   ├── Html("h1")              │             │   │
│  │    │     │   │   │   └── TextNode            │             │   │
│  │    │     │   │   ├── Output (reactive text)  │             │   │
│  │    │     │   │   ├── Reactive("if")          │             │   │
│  │    │     │   │   │   ├── <!--r:id-s-->       │             │   │
│  │    │     │   │   │   ├── [content]           │             │   │
│  │    │     │   │   │   └── <!--r:id-e-->       │             │   │
│  │    │     │   │   └── BlockOutlet("content")  │             │   │
│  │    │     │   │       ├── <!--bo:id-s-->      │             │   │
│  │    │     │   │       └── <!--bo:id-e-->      │             │   │
│  │    │     │   └── Html("footer")              │             │   │
│  │    │     │                                                 │   │
│  │    │     └── [Mounted into container or parent Block]      │   │
│  │    │                                                       │   │
│  │    ├── Block Registry (for layout views)                   │   │
│  │    │     └── blocks: Map<name, Block>                      │   │
│  │    │                                                       │   │
│  │    └── Event Management (AbortController-based)            │   │
│  │                                                             │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                   │
│  ┌─── Services ──────────────────────────────────────────────┐   │
│  │  MarkerRegistry │ EventService │ HttpService              │   │
│  │  StoreService   │ StorageService │ LoggerService          │   │
│  └───────────────────────────────────────────────────────────┘   │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

### 3.2 Render Pipeline — Chi tiết

#### Trường hợp 1: View đơn (không extends)

```
Router.navigate('/about')
  │
  ▼
ViewManager.mountView('about', params)
  │
  ├── 1. Load module: await ViewLoader.load('about')
  │     → AboutView class (extends View)
  │
  ├── 2. Create instance: new AboutView('about', 'view')
  │     → View constructor tạo ViewController
  │
  ├── 3. Setup: view.$__setup__(data, systemData)
  │     → Khai báo state (useState), methods, render factory
  │
  ├── 4. Check extends: view.hasSuperView === false
  │     → Không có layout → render trực tiếp
  │
  ├── 5. Mount markers vào container:
  │     container.appendChild(<!--v:about-s-->)
  │     container.appendChild(<!--v:about-e-->)
  │
  ├── 6. Render element tree:
  │     renderFactory(ctx, rootContainer)
  │       │
  │       ├── html("div", config, childrenFactory)
  │       │   ├── document.createElement("div")
  │       │   ├── apply attrs, classes, events
  │       │   ├── insertBefore(div, <!--v:about-e-->)
  │       │   └── childrenFactory(ctx, divWrapper)
  │       │       ├── html("h1", ...) → createElement + appendChild
  │       │       ├── output({stateKeys: ["title"]}, () => title)
  │       │       │   → createTextNode(title)
  │       │       │   → appendChild vào h1
  │       │       └── reactive("if", {stateKeys: ["show"]}, factory)
  │       │           ├── appendChild(<!--r:id-s-->)
  │       │           ├── factory() → [html("p", ...)]
  │       │           │   → createElement("p") → insertBefore(p, <!--r:id-e-->)
  │       │           └── appendChild(<!--r:id-e-->)
  │       │
  │       └── Tree hoàn chỉnh, mọi element đã trong DOM
  │
  ├── 7. Start view: activate reactive subscriptions
  │     → Output subscribes to stateKeys
  │     → Reactive subscribes to stateKeys
  │     → view.onMounted()
  │
  └── Done — View đang hoạt động, tương tác được
```

#### Trường hợp 2: View có extends layout

```
Router.navigate('/home')
  │
  ▼
ViewManager.mountView('home', params)
  │
  ├── 1. Load module: await ViewLoader.load('home')
  │     → HomeView { superViewPath: 'layouts.main' }
  │
  ├── 2. Create instance + setup
  │     → Khai báo state, methods
  │     → render factory tạo Blocks: block("content", renderFactory)
  │
  ├── 3. Resolve layout chain (loop):
  │     viewStack = [HomeView]
  │     current = HomeView
  │     while (current.hasSuperView):
  │       ├── Render current → collect blocks vào BlockManager
  │       ├── Load super: LayoutView = await ViewLoader.load('layouts.main')
  │       ├── Create + setup LayoutView
  │       ├── viewStack.push(LayoutView)
  │       └── current = LayoutView
  │     
  │     viewStack = [HomeView, LayoutView]
  │     (LayoutView không có superView → stop)
  │
  ├── 4. Mount outermost view (LayoutView) vào container:
  │     container.appendChild(<!--v:layout-s-->)
  │     container.appendChild(<!--v:layout-e-->)
  │
  ├── 5. Render LayoutView's element tree:
  │     renderFactory(layoutCtx, container)
  │       │
  │       ├── html("div", {class: "layout"}, children)
  │       │   ├── html("header", ..., [...])
  │       │   ├── html("main", ..., children)
  │       │   │   └── blockOutlet("content", mainWrapper)
  │       │   │       ├── appendChild(<!--bo:content-s-->)
  │       │   │       └── appendChild(<!--bo:content-e-->)
  │       │   └── html("footer", ..., [...])
  │       │
  │       └── Layout DOM hoàn chỉnh, có BlockOutlet markers
  │
  ├── 6. Mount blocks vào outlets:
  │     BlockManager.mountAll()
  │       │
  │       ├── Tìm Block("content") từ HomeView
  │       ├── Tìm BlockOutlet("content") trong LayoutView
  │       ├── Render Block content:
  │       │   block.contentRenderFactory(homeCtx)
  │       │     → [html("h1", ...), html("p", ...), ...]
  │       │   Insert elements giữa <!--bo:content-s--> và <!--bo:content-e-->
  │       │
  │       └── Block đã mount vào outlet
  │
  ├── 7. Start tất cả views (bottom-up):
  │     HomeView.start()    → activate subscriptions, onMounted()
  │     LayoutView.start()  → activate subscriptions, onMounted()
  │
  └── Done — Layout + Page content đều hoạt động
```

#### Navigate giữa các trang cùng layout

```
Đang ở /home (LayoutView + HomeView)
  │
  ▼
Router.navigate('/about')
  │
  ├── 1. Load AboutView { superViewPath: 'layouts.main' }
  │
  ├── 2. Detect: same layout! (layouts.main === current layout)
  │     → KHÔNG re-render layout
  │     → Chỉ swap block content
  │
  ├── 3. Deactivate HomeView:
  │     HomeView.stop()      → unsubscribe reactive, onDeactivated()
  │     Cache HomeView DOM   → giữ trong memory để restore khi back
  │
  ├── 4. Clear BlockOutlet("content"):
  │     Remove nodes giữa <!--bo:content-s--> và <!--bo:content-e-->
  │
  ├── 5. Setup AboutView + collect blocks
  │
  ├── 6. Mount AboutView's Block("content") vào existing outlet
  │
  ├── 7. Start AboutView
  │
  └── Done — Layout giữ nguyên, chỉ content thay đổi (nhanh!)
```

### 3.3 Element Hierarchy & Render Order

```
Nguyên tắc: Parent tạo trước → append vào DOM → render children → children append vào parent

Fragment (root, no DOM element)
  │
  ├── Html("div")
  │   [1] createElement("div")
  │   [2] apply config (attrs, classes, events)
  │   [3] append div vào parent element
  │   [4] childrenFactory(ctx, divWrapper) → returns children array
  │   [5] For each child:
  │       ├── string/number → createTextNode → appendChild
  │       ├── Html → [1]-[5] recursive
  │       ├── Output → createTextNode → appendChild (+ subscribe)
  │       ├── Reactive → appendMarkers → factory() → mount children
  │       ├── Fragment → appendMarkers → mount children
  │       ├── Block → skip (collected for later)
  │       └── BlockOutlet → appendMarkers (placeholder for block content)
  │
  └── Element tree hoàn chỉnh trong DOM
```

### 3.4 Re-render Flow (State Change)

```
setCount(count + 1)
  │
  ▼
StateManager.commitStateChange('count')
  │
  ├── Batch: pendingChanges.add('count')
  │
  ├── Schedule: requestAnimationFrame(flushChanges)
  │
  └── flushChanges():
      │
      ├── Notify single-key listeners:
      │   stateKey 'count' → [listener1, listener2, ...]
      │
      ├── Notify multi-key listeners:
      │   {keys: ['count', 'total'], callback} → gọi nếu 'count' ∈ keys
      │
      └── Listener triggers:
          │
          ├── Output → update textContent in-place (O(1))
          │   textNode.textContent = newValue
          │
          ├── Reactive("if") → scheduleUpdate(reactive)
          │   → ViewController.flushReactiveUpdates()
          │   → reactive.render()
          │     ├── clearContent() — remove nodes giữa markers
          │     ├── factory() — tạo children mới
          │     └── insertBeforeClose — chèn vào DOM
          │
          └── Html attr binding → update attribute in-place
              element.setAttribute(name, newValue)
```

---

## 4. KẾ HOẠCH TRIỂN KHAI — CÁC PHASE

> **Tiến độ:** Phase 1 ✅ | Phase 2 ✅ (partial — chưa ViewManager/ViewLoader) | Phase 3-6 chưa bắt đầu.
> Phase 1 trong ARCHITECTURE tương ứng với Phase 1-8 trong IMPLEMENTATION_PLAN.md (chi tiết hơn).

### Phase 1: Hoàn thiện Element Layer (Foundation) ✅ HOÀN THÀNH
**Ưu tiên: CAO — Nền tảng cho mọi thứ**

| # | Task | File | Chi tiết |
|---|------|------|----------|
| 1.1 | Hoàn thiện `Output` | elements/Output.ts | Reactive text node giữa comment markers. Subscribe stateKeys. Update in-place. Hỗ trợ escapeHTML. |
| 1.2 | `Html` reactive bindings | elements/Html.ts | Subscribe stateKeys cho attrs, classes, styles. Update in-place khi state thay đổi. Không re-render children. |
| 1.3 | `Html` class binding | elements/Html.ts | Xử lý `classes` config — static class + reactive class binding. `classList.toggle()` |
| 1.4 | `Html` style binding | elements/Html.ts | Xử lý `styles` config — `element.style[prop] = value` |
| 1.5 | `Html` props binding | elements/Html.ts | Xử lý `props` config — `(element as any)[prop] = value` cho checked, value, disabled, etc. |
| 1.6 | Index exports + factory functions | elements/index.ts | Clean up `html()`, `output()`, `reactive()`, `fragment()` factory functions |
| 1.7 | Fix `reactive()` function | elements/index.ts | Sửa parentElement type (HTMLElement → HtmlContract) |

### Phase 2: View Lifecycle & Mount Pipeline ⚠️ PARTIAL
**Ưu tiên: CAO — Cần để views render được**

> Task 2.4 (start/stop) đã xong. Còn lại: ViewManager, ViewLoader, Mount Pipeline, Section system, Layout reuse.

| # | Task | File mới | Chi tiết |
|---|------|----------|----------|
| 2.1 | `ViewManager` | view/ViewManager.ts | Orchestrator: loadView, mountView, unmountView, layout chain resolution. Nhẹ hơn core/ (tách trách nhiệm) |
| 2.2 | `ViewLoader` | view/ViewLoader.ts | Dynamic ESM import, caching, path resolution. Port logic từ core/ |
| 2.3 | Mount Pipeline | view/ViewManager.ts | Implement 2 flows: (1) direct mount, (2) layout chain mount |
| 2.4 | View activation | view/ViewController.ts | `start()` / `stop()` — activate/deactivate subscriptions. Walk element tree, call start/stop trên Output, Reactive |
| 2.5 | Section system | view/ViewManager.ts | `@section` / `@yield` / `@push` / `@stack` — section registry |
| 2.6 | Layout reuse | view/ViewManager.ts | Detect same layout → chỉ swap blocks, không re-render layout |

### Phase 3: Reconciliation & List Rendering
**Ưu tiên: TRUNG BÌNH — Cần cho dynamic lists**

| # | Task | File | Chi tiết |
|---|------|------|----------|
| 3.1 | Keyed reconciliation | elements/Reconciler.ts | Diff algorithm cho `@foreach`: key-based move/add/remove. Tương tự Vue/Svelte keyed lists |
| 3.2 | `__foreach` upgrade | view/ViewController.ts | Tích hợp reconciler. Giữ stable DOM nodes khi list thay đổi |
| 3.3 | `__for` / `__while` | view/ViewController.ts | Chuyển từ output mảng sang incremental mount |

**Reconciliation Algorithm (đề xuất):**

```
Keyed Reconciliation (tương tự Vue 3 / Svelte):

OldList: [A, B, C, D, E]     (mỗi item có key)
NewList: [A, C, B, F, E]

1. Walk from start: A === A → skip (giữ nguyên DOM)
2. Walk from end:   E === E → skip
3. Middle: old=[B,C,D] new=[C,B,F]
   - Build oldKeyMap: {B:1, C:2, D:3}
   - C: found in old → move DOM node
   - B: found in old → move DOM node  
   - F: not found → create new DOM + insert
   - D: not in new → remove DOM node

Kết quả: Chỉ 2 move + 1 create + 1 remove
          Thay vì destroy all + recreate all
```

### Phase 4: Router
**Ưu tiên: CAO — Cần cho SPA navigation**

| # | Task | File | Chi tiết |
|---|------|------|----------|
| 4.1 | Router core | routers/Router.ts | Port từ core/ — history/hash, pattern matching, guards. Giữ API tương thích |
| 4.2 | Route → ViewManager | routers/Router.ts | `handleRoute` → `ViewManager.mountView()` |
| 4.3 | Auto navigation | routers/Router.ts | Click interception cho `<a>`, `[data-nav-link]` |
| 4.4 | View caching | view/ViewCache.ts | DOM cache cho browser back/forward. Restore mà không re-render |

### Phase 5: SSR Hydration
**Ưu tiên: TRUNG BÌNH — Cần cho production Laravel**

| # | Task | File | Chi tiết |
|---|------|------|----------|
| 5.1 | SSR data parser | view/SSRViewDataParser.ts | Parse `<!-- [one:view-data] -->` comment markers |
| 5.2 | Hydration mode | elements/*.ts | `hydrateMode`: thay vì create element, tìm existing DOM node → attach bindings |
| 5.3 | Html.hydrate() | elements/Html.ts | `querySelector` theo tag + order → gắn event listeners + reactive bindings |
| 5.4 | Reactive.hydrate() | elements/Reactive.ts | Tìm comment markers → attach subscriptions |

### Phase 6: Services & Polish
**Ưu tiên: THẤP — Reuse hoặc port từ core/**

| # | Task | Ghi chú |
|---|------|---------|
| 6.1 | EventService | Port từ core/ — pub/sub |
| 6.2 | HttpService | Port từ core/ — fetch wrapper |
| 6.3 | StoreService | Port từ core/ — global state |
| 6.4 | StorageService | Port từ core/ — localStorage |
| 6.5 | Helper utilities | Port từ core/ |
| 6.6 | DevTools integration | MarkerRegistry inspection |

---

## 5. CÁC QUYẾT ĐỊNH THIẾT KẾ QUAN TRỌNG

### 5.1 Block vs Section — Thống nhất hay tách?

**Đề xuất: Thống nhất thành Block system**

Trong Laravel Blade có 2 cơ chế:
- `@section('name') ... @endsection` + `@yield('name')` — string content
- `@section('name') ... @show` — với `@parent` — appendable

Saola mới nên dùng 1 cơ chế:
- `block("name", renderFactory)` — khai báo nội dung block trong child view
- `blockOutlet("name")` — khai báo vị trí mount trong layout

Lý do: Trong DOM-based system, mọi thứ là elements, không phải strings. Block/BlockOutlet là abstraction tự nhiên hơn.

### 5.2 BlockManager — Global singleton hay per-layout?

**Đề xuất: Global singleton + scoping theo viewId**

```typescript
// Block key = `${viewId}:${blockName}`
BlockManager.add(block);        // HomeView đăng ký block
BlockManager.addOutlet(outlet); // LayoutView đăng ký outlet
BlockManager.mountAll();        // Match blocks → outlets
```

Lý do: Blocks và outlets thuộc views khác nhau (child vs layout). Cần central coordinator.

### 5.3 Element start/stop lifecycle

**Đề xuất: Two-phase rendering**

```
Phase 1: CREATE — build element tree, mount vào DOM
  - Không subscribe reactive — chỉ tạo elements
  - Output tạo TextNode với initial value
  - Reactive render children lần đầu
  
Phase 2: START — activate reactivity
  - Output.start() → subscribe stateKeys
  - Reactive.start() → subscribe stateKeys
  - Html.start() → subscribe attr/class/style bindings
  - view.onMounted() called
  
Phase 3: STOP — deactivate (navigate away)
  - Unsubscribe tất cả
  - view.onDeactivated() called
  - DOM giữ nguyên (cho caching)

Phase 4: DESTROY — remove from DOM
  - view.onDestroy() called
  - Remove DOM nodes
  - Cleanup references
```

Lý do: Tách CREATE và START giúp layout chain mount đúng thứ tự — tất cả DOM phải sẵn sàng trước khi start bất kỳ reactive nào.

### 5.4 Output vs TextElement — Consolidate?

**Đề xuất: Giữ cả 2, vai trò khác nhau**

| | TextElement | Output |
|---|-----------|--------|
| **Khi nào dùng** | Text tĩnh hoặc đơn giản | Expression có thể chứa HTML |
| **DOM node** | Một `Text` node | Comment markers + Text(s) bên trong |
| **Re-render** | `textNode.textContent = newValue` | Clear + re-create content giữa markers |
| **Performance** | O(1) update | O(n) nhưng hiếm khi gọi |
| **HTML escape** | Luôn escape | Configurable |

Compiler output:
- `{{ $name }}` → `output({stateKeys: ["name"], isEscapeHTML: true}, () => name)` 
- `{!! $html !!}` → `output({stateKeys: ["html"], isEscapeHTML: false}, () => html)`

### 5.5 Reactive scheduling — immediate hay batched?

**Đề xuất: Batched qua requestAnimationFrame (giữ như hiện tại)**

```
State change → pendingChanges.add(key) → RAF → flushChanges → notify listeners
```

Lý do:
- Nhiều state changes trong 1 event handler → chỉ trigger 1 re-render
- Tránh layout thrashing
- Giống React batched updates, Vue nextTick, Svelte $effect scheduling

---

## 6. CẤU TRÚC THƯ MỤC ĐỀ XUẤT

```
src/one/
├── app/
│   └── Application.ts          ✅ DI Container (done)
│
├── bootstrap/
│   └── app.ts                  ⚠️ Init (cần bổ sung)
│
├── contracts/
│   └── utils.ts                ✅ Interfaces (done, cần bổ sung)
│
├── types/
│   └── utils.ts                ✅ Types (done, cần bổ sung)
│
├── elements/
│   ├── Html.ts                 ⚠️ Cần bổ sung reactive bindings
│   ├── Reactive.ts             ✅ Cơ bản (cần start/stop)
│   ├── Fragment.ts             ✅ Done
│   ├── TextElement.ts          ✅ Done
│   ├── Output.ts               ❌ Cần implement
│   ├── Block.ts                ⚠️ Cần kết nối với outlet
│   ├── BlockOutlet.ts          ⚠️ Cần kết nối với block  
│   ├── BlockManager.ts         ⚠️ Cần mountAll logic
│   ├── ElementManager.ts       ⚠️ Cần factory shortcuts
│   ├── Reconciler.ts           ❌ MỚI — keyed list diffing
│   └── index.ts                ⚠️ Cần sửa factory functions
│
├── view/
│   ├── View.ts                 ✅ Done
│   ├── ViewController.ts       ✅ Khá đầy đủ (cần start/stop)
│   ├── ViewState.ts            ✅ Done
│   ├── ViewManager.ts          ❌ MỚI — view lifecycle orchestrator
│   ├── ViewLoader.ts           ❌ MỚI — dynamic module loading
│   ├── ViewCache.ts            ❌ MỚI — DOM caching cho back/forward
│   ├── LoopContext.ts          ✅ Done
│   └── index.ts                ✅ Done
│
├── routers/
│   └── Router.ts               ❌ MỚI — SPA router
│
├── dom/
│   └── Dom.ts                  ✅ Done (nhẹ)
│
├── services/
│   ├── MarkerRegistry.ts       ✅ Done
│   ├── EventService.ts         ❌ Port từ core/
│   ├── HttpService.ts          ❌ Port từ core/
│   ├── StoreService.ts         ❌ Port từ core/
│   ├── StorageService.ts       ❌ Port từ core/
│   └── LoggerService.ts        ❌ Port từ core/
│
├── helpers/
│   ├── utils.ts                ✅ Done
│   └── app.ts                  ✅ Done
│
└── cache/
    └── (future)
```

---

## 7. ƯU TIÊN TRIỂN KHAI NGAY

**Sprint 1 (Tuần 1-2): Có thể render 1 view đơn giản**

1. ✅ Fix types/contracts (đã làm — openTag/closeTag)
2. Implement `Output` hoàn chỉnh
3. `Html` reactive class/attr/style bindings
4. Factory functions (`html()`, `output()`, `reactive()`, `fragment()`)
5. ViewController `start()` / `stop()` methods
6. Test: render counter.sao manually

**Sprint 2 (Tuần 3-4): Layout + Block system**

1. `Block` ↔ `BlockOutlet` connection
2. `BlockManager.mountAll()` 
3. `ViewManager` cơ bản (loadView, mountView)
4. `ViewLoader` (dynamic import)
5. Test: layout.sao + page.sao with blocks

**Sprint 3 (Tuần 5-6): Router + Navigation**

1. Port Router từ core/
2. Route → ViewManager integration
3. Layout reuse optimization
4. View caching (back/forward)
5. Test: multi-page SPA navigation

**Sprint 4 (Tuần 7-8): Reconciliation + Polish**

1. Keyed reconciliation cho `@foreach`
2. SSR hydration
3. Port services (Event, Http, Store)
4. Performance benchmarks

---

## 8. BENCHMARK TARGETS

| Metric | Saola Old | Target Saola New | React 19 | Vue 3.5 | Svelte 5 |
|--------|-----------|-------------------|----------|---------|----------|
| First render 1000 items | ~45ms | **< 30ms** | ~35ms | ~30ms | ~20ms |
| Update 1 item in 1000 | ~15ms (re-render region) | **< 1ms** | ~5ms | ~3ms | ~0.5ms |
| Add 100 items | ~40ms (rebuild all) | **< 15ms** (append only) | ~20ms | ~15ms | ~10ms |
| Remove 100 items | ~35ms | **< 10ms** | ~15ms | ~10ms | ~8ms |
| Memory (1000 items) | ~2MB (strings+DOM) | **< 1.5MB** | ~3MB (VDOM) | ~2.5MB | ~1MB |
| Bundle size (gzip) | ~20KB | **< 12KB** | ~15KB | ~12KB | ~3KB |

---

## 9. TÓM TẮT

Saola v3 (`one/`) hướng đến:

1. **Direct DOM creation** — không qua VDOM hay string template
2. **Compiler-guided updates** — `stateKeys` cho biết chính xác node nào cần update
3. **Laravel Blade compatibility** — `@extends`, `@section`, `@yield`, `@foreach`, `@if`
4. **Two-phase lifecycle** — CREATE (build DOM) → START (activate reactivity)
5. **Layout reuse** — cùng layout → chỉ swap block content
6. **Keyed reconciliation** — hiệu quả cho dynamic lists
7. **Progressive hydration** — SSR HTML → attach bindings (không re-render)

Nền tảng đã tốt (View, ViewController, ViewState, Html, Reactive, Fragment, MarkerRegistry). Cần tập trung hoàn thiện **Output**, **Block↔BlockOutlet mounting**, và **ViewManager** trước khi mở rộng sang Router và SSR.

---

## 10. DATA FLOW & RENDER CONTEXT — THIẾT KẾ CHI TIẾT

> **Vấn đề hiện tại**: ViewController thiếu cơ chế lưu trữ data, config và các callback lifecycle mà compiled output cần. Compiler sinh ra code gọi các methods chưa tồn tại trong `one/` StateManager.

### 10.1 Phân loại dữ liệu trong View

| Loại | Directive | Ví dụ compiled output | Reactivity | Lưu ở đâu |
|------|----------|----------------------|-----------|-----------|
| **Route params / Props** | `@vars`, `@props` | `let {a = 0} = __data__;` | Non-reactive — closure variable | Closure scope trong `$__setup__` |
| **Reactive State** | `@useState` | `__STATE__.__.register('count')` + `let count = null` + setter/updater | **Fully reactive** — setter → notify → re-render | StateManager (registry + pub/sub) + closure var (fast access) |
| **Constants** | `@const` | `const MAX = 10;` | Immutable | Closure scope |
| **Mutable locals** | `@let` | `let n = 0;` + `__UPDATE_DATA_TRAIT__` | Non-reactive, mutable qua trait | Closure scope + update trait |
| **Computed (manual)** | `@let($text = msg + count)` | `let text = msg + count;` | Không auto-reactive — đánh giá 1 lần lúc setup | Closure scope |
| **User methods** | Functions trong view | `this.__ctrl__.setUserDefinedConfig({toggle() {...}})` | N/A | Gắn vào View instance (`this.toggle()`) |
| **System data** | Framework nội bộ | `{__base__, __layout__, __page__, ...}` = `systemData` | Non-reactive config | Destructured trong `$__setup__`, không lưu |
| **SSR View ID** | Hydration | `__data__.__SSR_VIEW_ID__` | One-time | Dùng lúc setup, sau đó chuyển thành viewId |

### 10.2 Data Flow End-to-End

```
┌─────────────────────────────────────────────────────────────────────┐
│ ROUTER                                                               │
│  navigate('/users/42')                                               │
│  → matchRoute('/users/{id}') → params = { id: '42' }               │
│  → ViewManager.mountView('users.show', { id: '42' }, activeRoute)   │
└──────────────────────────────┬──────────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│ VIEW MANAGER                                                         │
│  mountView(name, params, route)                                      │
│    1. module = await ViewLoader.load(name)                           │
│    2. factory = module.default  // exported function                 │
│    3. systemData = { App: this.App, View: this, ... }               │
│    4. view = factory(params, systemData)  // invokes constructor     │
│    └── ▼                                                             │
└──────────────────────────────┬──────────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│ COMPILED FACTORY FUNCTION                                            │
│                                                                       │
│  export function usersShow(__data__ = {}, systemData = {}) {         │
│      const App = app("App");                                         │
│      const view = new usersShowView(App, systemData);                │
│      view.$__setup__(__data__, systemData);                          │
│      return view;                                                    │
│  }                                                                    │
└──────────────────────────────┬──────────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│ $__setup__(__data__, systemData)                                     │
│                                                                       │
│  // Framework references                                             │
│  const App = this.__ctrl__.App;                                      │
│  const __STATE__ = this.__ctrl__.states;                             │
│                                                                       │
│  // Destructure systemData                                           │
│  const { __base__, __layout__, ... } = systemData;                   │
│  const __VIEW_ID__ = __data__.__SSR_VIEW_ID__ || generateViewId();   │
│                                                                       │
│  // 1. LOCAL HELPERS (closure scope)                                 │
│  const useState = (value) => __STATE__.__useState(value);            │
│  const updateStateByKey = (k, v) => __STATE__.__.updateStateByKey(k,v);│
│  const lockUpdateRealState = () => __STATE__.__.lockUpdateRealState();│
│                                                                       │
│  // 2. @vars / @props → destructure from __data__                    │
│  let { userId = 0, filter = '' } = __data__;                        │
│                                                                       │
│  // 3. @useState → triple pattern: register + closure var + setter   │
│  const set$name = __STATE__.__.register('name');                     │
│  let name = null; // closure var (fast read in render)               │
│  const setName = (state) => { name = state; set$name(state); };     │
│  const update$name = (value) => {                                    │
│      if (__STATE__.__.canUpdateStateByKey) {                         │
│          updateStateByKey('name', value);                            │
│          name = value; // sync closure var                           │
│      }                                                                │
│  };                                                                   │
│                                                                       │
│  // 4. @let → mutable closure + update trait                        │
│  let temp = 0;                                                       │
│  __UPDATE_DATA_TRAIT__.temp = (v) => temp = v;                       │
│                                                                       │
│  // 5. User methods → gắn vào View instance                         │
│  this.__ctrl__.setUserDefinedConfig({ handleClick() {...} });        │
│                                                                       │
│  // 6. Setup ViewController với config + render factory              │
│  this.__ctrl__.setup({                                               │
│      data: __data__,            // ◀ raw data reference              │
│      viewId: __VIEW_ID__,       // ◀ view identifier                │
│      path: __VIEW_PATH__,       // ◀ module path                    │
│      superView: null,           // ◀ layout path (hoặc 'layouts.main')│
│      commitConstructorData() {  // ◀ gọi 1 lần sau setup            │
│          update$name('default');                                      │
│          lockUpdateRealState();                                       │
│      },                                                              │
│      updateVariableData(data) { // ◀ gọi khi parent truyền data mới │
│          for (key in data) this.config.updateVariableItemData(key, data[key]);│
│          update$name('default');                                      │
│          lockUpdateRealState();                                       │
│      },                                                              │
│      updateVariableItemData(key, value) {  // ◀ update 1 variable   │
│          this.data[key] = value;                                     │
│          if (__UPDATE_DATA_TRAIT__[key]) __UPDATE_DATA_TRAIT__[key](value);│
│      },                                                              │
│      render() {                 // ◀ element tree render factory     │
│          const ctx = this;                                           │
│          return fragment({ctx, parentElement: null}, (parentElement) => [│
│              html("div", {ctx, parentElement, ...}, (parentElement) => [│
│                  output({ctx, parentElement, stateKeys: ["name"]}, () => name),│
│              ])                                                       │
│          ]);                                                          │
│      }                                                               │
│  });                                                                  │
│                                                                       │
└─────────────────────────────────────────────────────────────────────┘
```

### 10.3 "Triple Pattern" cho @useState — Chi tiết

Mỗi `@useState` directive sinh ra **3 thành phần liên kết**:

```
┌─────────────────────────────────────────────────────────────┐
│  @useState($count, 0)                                        │
│                                                               │
│  [1] REGISTER — đăng ký key trong StateManager               │
│      const set$count = __STATE__.__.register('count');       │
│      → StateManager tạo entry: states['count'] = {value, setter}│
│      → Trả về internal setter function                       │
│                                                               │
│  [2] CLOSURE VARIABLE — biến local cho phép đọc nhanh       │
│      let count = null;                                       │
│      → Render function đọc `count` trực tiếp (O(1) closure) │
│      → KHÔNG qua StateManager.getStateByKey() (chậm)        │
│                                                               │
│  [3] PUBLIC SETTER — user-facing function                     │
│      const setCount = (state) => {                           │
│          count = state;         // cập nhật closure var      │
│          set$count(state);      // cập nhật StateManager     │
│                                 // → trigger subscribers     │
│                                 // → schedule re-render      │
│      };                                                      │
│                                                               │
│  [4] UPDATE FUNCTION — cho commitConstructorData              │
│      const update$count = (value) => {                       │
│          if (__STATE__.__.canUpdateStateByKey) {              │
│              updateStateByKey('count', value);               │
│              count = value; // sync closure                   │
│          }                                                    │
│      };                                                      │
│      → Chỉ dùng trong commitConstructorData (1 lần)         │
│      → Sau lockUpdateRealState(), KHÔNG thể gọi nữa         │
│                                                               │
│  [5] SETTER REGISTRY — cho external access                   │
│      __STATE__.__.setters.setCount = setCount;               │
│      __STATE__.__.setters.count = setCount;                  │
│      → Cho phép truy cập từ bên ngoài view nếu cần         │
│                                                               │
└─────────────────────────────────────────────────────────────┘

Flow khi user click button → setCount(count + 1):
  setCount(1)
    → count = 1                          // [2] closure var updated
    → set$count(1)                       // [1] call internal setter
      → StateManager.states['count'].value = 1
      → StateManager.commitStateChange('count')
        → pendingChanges.add('count')
        → requestAnimationFrame(flush)
          → flushChanges()
            → notify listeners subscribed to 'count'
              → Output re-reads closure: () => count  // returns 1
              → textNode.textContent = "1"
              → Reactive re-renders if subscribed to 'count'
```

### 10.4 Những gì `one/` StateManager đã bổ sung (Phase 1 ✅)

Compiled output gọi các method sau — **tất cả đã implement trong ViewState.ts**:

| Method | Cần cho | Trạng thái |
|--------|--------|-------|
| `register(key)` | `@useState` | ✅ Đã có |
| `canUpdateStateByKey` | `update$xxx` guard | ✅ Đã thêm (Phase 1) |
| `lockUpdateRealState()` | `commitConstructorData` | ✅ Đã thêm (Phase 1) |
| `unlockUpdateRealState()` | `updateData` | ✅ Đã thêm (Phase 1) |
| `updateRealState(state)` | Bulk state update | ✅ Đã thêm (Phase 1) |
| `setters` | External setter access | ✅ Đã có |
| `__useState(value)` | Local `useState()` helper | ✅ Đã thêm trên ViewState (Phase 1) |

### 10.5 Những gì `one/` ViewController.setup() đã bổ sung (Phase 2 ✅)

Compiled output truyền config object vào `setup()` với các fields sau — **tất cả đã được xử lý trong ViewController.ts**:

```typescript
// Compiled output gọi:
this.__ctrl__.setup({
    // ─── Metadata ──────────────────────
    data: __data__,                    // Raw input data
    viewId: __VIEW_ID__,               // Unique view identifier
    path: __VIEW_PATH__,               // Module path ('web.home')
    superView: null | 'layouts.main',  // Layout path (null nếu không extends)
    subscribe: true,                   // Có subscribe reactive không
    
    // ─── Resources ─────────────────────
    scripts: [],                       // External JS to load
    styles: [],                        // External CSS to load  
    resources: [],                     // Other resources
    
    // ─── Data Lifecycle Callbacks ──────
    commitConstructorData(): void,     // Gọi 1 lần sau setup → set initial state values → lock
    updateVariableData(data): void,    // Gọi khi parent truyền data mới → update tất cả vars + states
    updateVariableItemData(key, value): void, // Update 1 variable qua __UPDATE_DATA_TRAIT__
    
    // ─── Render ────────────────────────
    prerender(): string | null,        // Pre-render HTML (for SSR, optional)
    render(): any,                     // Element tree render factory (NEW) hoặc HTML string (OLD)
    
    // ─── Fetch (optional) ──────────────
    fetch: null | Function,            // Async data fetching
});
```

**`one/` ViewController.setup() hiện tại đã xử lý đầy đủ:**
1. ✅ Parse + validate config fields
2. ✅ Lưu metadata (viewId, path, superView)
3. ✅ Lưu data reference
4. ✅ Lưu render factory
5. ✅ `commitData()` gọi `commitConstructorData()` 1 lần (idempotent)
6. ✅ `updateData(newData)` gọi `updateVariableData(newData)` + lock/unlock
7. ✅ `start()` / `stop()` walk element tree (Phase 7)

### 10.6 Config Object — Thiết kế chi tiết cho `one/`

```typescript
// ─── Types cần thêm vào types/utils.ts ──────────────────────

export interface ViewSetupConfig {
    /** Raw data từ route/parent */
    data: Record<string, any>;
    
    /** Unique view ID (từ SSR hoặc generated) */
    viewId: string;
    
    /** Module path (e.g. 'web.home', 'layouts.main') */
    path: string;
    
    /** Layout/super view path — null nếu không extends */
    superView: string | null;
    
    /** Có subscribe reactive state changes không */
    subscribe: boolean;
    
    /** External resources */
    scripts: string[];
    styles: string[];
    resources: string[];
    
    /** 
     * Gọi 1 lần sau khi setup hoàn tất.
     * Set initial state values (update$count(0), etc.)
     * Kết thúc bằng lockUpdateRealState()
     */
    commitConstructorData: () => void;
    
    /**
     * Gọi khi parent/router truyền data mới.
     * Update tất cả @vars/@let + re-set states.
     * Dùng khi navigate giữa cùng view nhưng khác params.
     */
    updateVariableData: (data: Record<string, any>) => void;
    
    /**
     * Update 1 biến cụ thể qua __UPDATE_DATA_TRAIT__.
     * Gọi bởi updateVariableData hoặc external.
     */
    updateVariableItemData: (key: string, value: any) => void;
    
    /**
     * Pre-render (SSR, optional).
     * Trả về HTML string hoặc null.
     */
    prerender: (() => string | null) | null;
    
    /**
     * Element tree render factory (NEW system).
     * Trả về Fragment/element tree.
     */
    render: () => any;
    
    /** Async data fetch function (optional) */
    fetch: (() => Promise<any>) | null;
}

// ─── Cần thêm vào contracts/utils.ts ──────────────────────

export interface ViewControllerContract {
    // ... existing ...
    
    /** Raw input data reference */
    data: Record<string, any>;
    
    /** Config from compiled $__setup__ */
    config: ViewSetupConfig;
    
    /** Whether this view defines a super view (layout) */
    hasSuperView: boolean;
    
    /** Path to super view (layout) */
    superViewPath: string | null;
    
    /** Update data from external source (parent, router) */
    updateData(data: Record<string, any>): void;
    
    /** Update single data item */
    updateDataItem(key: string, value: any): void;
}
```

### 10.7 ViewController.setup() — Implementation Plan

```typescript
// ─── Trong ViewController ──────────────────────────────────

/** Raw data from route/parent */
public data: Record<string, any> = {};

/** Compiled config including render factory and data callbacks */
private config: ViewSetupConfig | null = null;

/** Layout/super view path */
public superViewPath: string | null = null;

/** Whether this view extends a layout */
public get hasSuperView(): boolean {
    return this.superViewPath !== null;
}

/** Data initialization completed flag */
private _isDataCommitted = false;

/**
 * Setup — called by compiled $__setup__ with full config.
 * 
 * 1. Lưu config reference
 * 2. Extract metadata (viewId, path, superView)
 * 3. Lưu data reference
 * 4. Lưu render factory
 * 5. CHƯA gọi commitConstructorData — ViewManager sẽ gọi
 *    sau khi layout chain đã resolve xong
 */
setup(config: ViewSetupConfig): void {
    this.config = config;
    
    // Update metadata
    if (config.viewId) this.viewId = config.viewId;
    if (config.path) this.path = config.path;
    this.data = config.data || {};
    this.superViewPath = config.superView || null;
    
    // Store render factory
    if (config.render) {
        this.setRenderFactory(config.render);
    }
}

/**
 * Commit initial data — gọi bởi ViewManager sau khi 
 * layout chain đã sẵn sàng.
 * 
 * Gọi commitConstructorData() từ compiled config
 * → set initial state values
 * → lockUpdateRealState()
 */
commitData(): void {
    if (this._isDataCommitted || !this.config) return;
    this._isDataCommitted = true;
    
    if (typeof this.config.commitConstructorData === 'function') {
        this.config.commitConstructorData();
    }
}

/**
 * Update data from external source.
 * Gọi bởi ViewManager khi navigate giữa cùng view.
 */
updateData(newData: Record<string, any>): void {
    if (!this.config) return;
    this.data = { ...this.data, ...newData };
    
    if (typeof this.config.updateVariableData === 'function') {
        this.config.updateVariableData(newData);
    }
}

/**
 * Update single data item.
 */
updateDataItem(key: string, value: any): void {
    if (!this.config) return;
    
    if (typeof this.config.updateVariableItemData === 'function') {
        this.config.updateVariableItemData(key, value);
    }
}
```

### 10.8 StateManager — Methods cần bổ sung

```typescript
// ─── Thêm vào StateManager (ViewState.ts) ──────────────────

/** Flag — cho phép update state qua updateStateByKey chỉ trong commitConstructorData */
private _canUpdateStateByKey: boolean = true;

/** Public getter cho compiled output check */
get canUpdateStateByKey(): boolean {
    return this._canUpdateStateByKey;
}

/**
 * Lock state updates — gọi sau commitConstructorData.
 * Sau lock, update$xxx() sẽ KHÔNG hoạt động.
 * Chỉ setXxx() (public setter) mới thay đổi state được.
 * 
 * Mục đích: Ngăn commitConstructorData chạy lại sau init.
 * Đây là guard cho initial state setup.
 */
lockUpdateRealState(): void {
    this._canUpdateStateByKey = false;
}

/**
 * Unlock — cho phép updateVariableData gọi lại update$xxx.
 * Gọi trước updateVariableData, lock lại sau khi xong.
 */  
unlockUpdateRealState(): void {
    this._canUpdateStateByKey = true;
}

/**
 * Bulk state update — cập nhật nhiều state keys cùng lúc.
 * Chỉ hoạt động khi canUpdateStateByKey = true.
 * 
 * @param stateMap — { count: 0, name: 'John' }
 */
updateRealState(stateMap: Record<string | number, any>): void {
    if (!this._canUpdateStateByKey) return;
    for (const [key, value] of Object.entries(stateMap)) {
        if (this.states[key]) {
            this.states[key].value = value;
            // KHÔNG trigger listeners — commitConstructorData 
            // sẽ set initial values yên lặng
        }
    }
}

/**
 * __useState trên ViewState — wrapper cho compiled output.
 * Tương tự React useState, trả về [value, setter].
 */
// Thêm method này trên ViewState class:
__useState(value: any, key?: string | number): [any, (newValue: any) => void] {
    const [val, setter, k] = this.__.useState(value, key);
    return [val, setter];
}
```

### 10.9 Thời điểm gọi các Data Callbacks

```
ViewManager.mountView(name, params)
  │
  ├── factory(params, systemData)
  │     └── new View() → $__setup__(params, systemData)
  │           ├── Khai báo tất cả @vars, @useState, @let, @const
  │           ├── setUserDefinedConfig({...})
  │           └── this.__ctrl__.setup(config)  ◀ LƯU config, CHƯA commit
  │
  ├── Resolve layout chain (nếu hasSuperView)
  │     ├── Load layout → factory → $__setup__ → setup(config)
  │     └── Repeat cho layout cha... 
  │
  ├── Mount outermost view vào container
  │     └── view.render() → element tree vào DOM
  │
  ├── Mount blocks vào outlets
  │     └── BlockManager.mountAll()
  │
  ├── ◀◀ COMMIT DATA (cho TẤT CẢ views trong stack) ◀◀
  │     ├── layoutView.__ctrl__.commitData()
  │     │     └── commitConstructorData()
  │     │           ├── update$xxx(initialValue) cho mỗi state
  │     │           └── lockUpdateRealState()
  │     │
  │     └── pageView.__ctrl__.commitData()
  │           └── commitConstructorData()
  │                 ├── update$xxx(initialValue) cho mỗi state  
  │                 └── lockUpdateRealState()
  │
  ├── Start tất cả views
  │     ├── Output.start() → subscribe stateKeys
  │     ├── Reactive.start() → subscribe stateKeys
  │     └── view.onMounted()
  │
  └── Done — View sẵn sàng tương tác
```

**Tại sao commitData() phải gọi SAU mount?**

Vì `commitConstructorData()` gọi `update$count(0)` → `updateStateByKey('count', 0)` → có thể trigger subscribers. Nếu gọi trước khi DOM sẵn sàng, subscribers sẽ cố update DOM nodes chưa tồn tại.

**Thứ tự đúng:**
1. `$__setup__` → khai báo variables, register states (giá trị ban đầu = null)
2. `setup(config)` → lưu config, chưa commit
3. Render element tree → tạo DOM với initial values (null/empty)
4. `commitData()` → set initial state values → trigger re-render → DOM cập nhật đúng giá trị
5. `start()` → activate ongoing subscriptions

> **Lưu ý**: Bước 4 có thể gây 1 lần re-render "thừa" (null → initial value). Tối ưu: trong `commitConstructorData`, `lockUpdateRealState` được gọi cuối cùng, nhưng trước đó `update$xxx` set giá trị QUIETLY (không trigger) nếu đây là lần init đầu tiên. Cần cờ `isInitialCommit` trong StateManager.

### 10.10 Navigate giữa cùng View (khác params) — Data Update Flow

```
Đang ở /users/42 → navigate /users/99
  │
  ├── Router detect: same view (users.show), different params
  │
  ├── OPTION A: Reuse view instance (tối ưu)
  │     ├── view.__ctrl__.updateData({ id: 99 })
  │     │     └── config.updateVariableData({ id: 99 })
  │     │           ├── For each key: updateVariableItemData(key, value)
  │     │           │     └── __UPDATE_DATA_TRAIT__[key](value)
  │     │           │           └── closure variable updated (userId = 99)
  │     │           ├── Re-set states: update$xxx(newInitial)
  │     │           └── lockUpdateRealState()
  │     └── Reactive re-render triggered by state changes
  │
  ├── OPTION B: Destroy + recreate (simpler, mặc định)
  │     ├── oldView.destroy()
  │     ├── newView = factory({ id: 99 }, systemData)
  │     └── Mount newView (full flow)
  │
  └── Done

OPTION A hiệu quả hơn nhưng phức tạp hơn.
Gợi ý: mặc định dùng OPTION B, OPTION A là tối ưu hóa sau.
```

### 10.11 __UPDATE_DATA_TRAIT__ — Cơ chế cập nhật biến non-reactive

```typescript
// Compiler sinh ra trait object trong $__setup__:
const __UPDATE_DATA_TRAIT__: Record<string, (value: any) => void> = {};

// Mỗi @vars, @let, @props đều register updater:
let userId = __data__.userId ?? 0;
__UPDATE_DATA_TRAIT__.userId = (value) => userId = value;

let filter = '';
__UPDATE_DATA_TRAIT__.filter = (value) => filter = value;

// Khi updateVariableItemData(key, value) được gọi:
//   1. this.data[key] = value;          → update raw data object
//   2. __UPDATE_DATA_TRAIT__[key](value) → update closure variable
// 
// Closure variable được cập nhật → 
// Lần render tiếp theo sẽ đọc giá trị mới
// (vì render factory dùng closure reference)
```

**ViewController không cần biết `__UPDATE_DATA_TRAIT__` tồn tại.**
Compiled output tự tạo trong `$__setup__` closure scope. ViewController chỉ cần gọi `config.updateVariableItemData(key, value)` — callback trong config sẽ xử lý.

### 10.12 Cách Render Factory đọc data

**Điểm then chốt: Render factory đọc data qua CLOSURE, không qua object lookup.**

```javascript
// Trong $__setup__:
let count = null;                    // closure variable
const setCount = (state) => { ... }; // closure setter

// render factory:
render() {
    return fragment({ctx, parentElement: null}, (parentElement) => [
        html("div", {ctx, parentElement}, (parentElement) => [
            // output đọc `count` trực tiếp từ closure — O(1)
            output({ctx, parentElement, stateKeys: ["count"]}, () => count),
            
            // event handler cũng dùng closure
            html("button", {
                ctx, parentElement,
                events: { click: [(e) => setCount(count + 1)] }
            }, (parentElement) => ["Click"])
        ])
    ]);
}
```

**Tại sao closure chứ không phải `this.count` hay `ctx.states.count`?**

1. **Performance**: Closure access là O(1) — engine đã resolve scope chain lúc compile. Object property lookup cần hash table traversal.
2. **No proxy overhead**: Không cần Proxy (Vue) hay getter/setter trap.
3. **Compiler-friendly**: Compiler biết chính xác biến nào thuộc scope nào, sinh code tối ưu.
4. **Memory**: Không tạo thêm object nào — biến sống trong function scope.

**Trade-off**: Không thể "observe" closure variables từ bên ngoài. Đây là lý do cần `stateKeys` — compiler liệt kê rõ ràng dependencies thay vì auto-detect.

### 10.13 Tổng kết — TODO Implementation cho Data Flow

| # | Task | File | Độ ưu tiên |
|---|------|------|-----------|
| 1 | Thêm `canUpdateStateByKey`, `lockUpdateRealState()`, `unlockUpdateRealState()` vào StateManager | view/ViewState.ts | **CAO** |
| 2 | Thêm `updateRealState(stateMap)` vào StateManager | view/ViewState.ts | **CAO** |
| 3 | Thêm `__useState()` wrapper trên ViewState | view/ViewState.ts | **CAO** |
| 4 | Định nghĩa `ViewSetupConfig` type | types/utils.ts | **CAO** |
| 5 | Cập nhật `ViewControllerContract` — thêm data, config, hasSuperView | contracts/utils.ts | **CAO** |
| 6 | Refactor `ViewController.setup()` — parse config, lưu metadata | view/ViewController.ts | **CAO** |
| 7 | Thêm `commitData()`, `updateData()`, `updateDataItem()` vào ViewController | view/ViewController.ts | **CAO** |
| 8 | Thêm `start()` / `stop()` lifecycle vào ViewController | view/ViewController.ts | **TRUNG BÌNH** |
| 9 | ViewManager gọi đúng thứ tự: setup → render → commitData → start | view/ViewManager.ts (mới) | **CAO** |
| 10 | Test: compiled output (demo2.js) chạy được với new ViewController | test/ | **CAO** |
