# Saola v3 — KẾ HOẠCH TRIỂN KHAI CHI TIẾT

> **Tài liệu này là nguồn tham chiếu duy nhất cho quá trình triển khai.**
> Mục đích: Tránh mất context khi làm việc lâu dài. Mỗi task có đầy đủ:
> file cần sửa, code template, dependencies, test scenarios.

---

## MỤC LỤC

1. [Tổng quan hiện trạng](#1-tổng-quan-hiện-trạng)
2. [Compiled Output — Contract Runtime phải tuân thủ](#2-compiled-output--contract-runtime-phải-tuân-thủ)
3. [Phase 1: StateManager — Missing Methods ✅](#3-phase-1-statemanager--missing-methods--hoàn-thành)
4. [Phase 2: ViewController.setup() — Data Lifecycle ✅](#4-phase-2-viewcontrollersetup--data-lifecycle--hoàn-thành)
5. [Phase 3: Output Element — Full Implementation ✅](#5-phase-3-output-element--full-implementation--hoàn-thành)
6. [Phase 4: Html Reactive Bindings ✅](#6-phase-4-html-reactive-bindings--hoàn-thành)
7. [Phase 5: ElementManager Factory Functions ✅](#7-phase-5-elementmanager-factory-functions--hoàn-thành)
8. [Phase 6: elements/index.ts — Fix Factory Signatures ✅](#8-phase-6-elementsindexts--fix-factory-signatures--hoàn-thành)
9. [Phase 7: ViewController start/stop Lifecycle ✅](#9-phase-7-viewcontroller-startstop-lifecycle--hoàn-thành)
10. [Phase 8: Block ↔ BlockOutlet Mounting ✅](#10-phase-8-block--blockoutlet-mounting--hoàn-thành)
11. [Phase 9: ViewManager — Orchestrator ❌](#11-phase-9-viewmanager--orchestrator)
12. [Phase 10: Router ❌](#12-phase-10-router)
13. [Phase 11: Reconciliation (Keyed Lists) ❌](#13-phase-11-reconciliation-keyed-lists)
14. [Phase 12: SSR Hydration ❌](#14-phase-12-ssr-hydration)
15. [Dependency Graph](#15-dependency-graph)
16. [Test Checklist](#16-test-checklist)

---

## 1. TỔNG QUAN HIỆN TRẠNG

> **Cập nhật**: Sau khi hoàn thành Phase 1–8, hầu hết runtime đã implement xong.
> Chỉ còn Phase 9 (ViewManager), Phase 10 (Router), Phase 11–12 chưa triển khai.

### Files & Trạng thái

| File | Dòng | Trạng thái | Ghi chú |
|------|------|-----------|---------|
| `src/one/view/ViewState.ts` | 453 | ✅ Done | Đã thêm `canUpdateStateByKey`, `lock/unlock`, `updateRealState`, `__useState` |
| `src/one/view/ViewController.ts` | 609 | ✅ Done | `setup()` parse metadata, `commitData`, `updateData`, `start()`/`stop()`, `render()` capture root tree, lifecycle hooks |
| `src/one/view/View.ts` | 89 | ✅ Done | Không cần sửa |
| `src/one/elements/Output.ts` | 141 | ✅ Done | Full implementation: render/start/stop/update/destroy, comment markers |
| `src/one/elements/Html.ts` | 273 | ✅ Done | Reactive bindings cho attrs/classes/styles, start/stop lifecycle |
| `src/one/elements/Reactive.ts` | 235 | ✅ Done | Đã thêm start/stop, recursive children lifecycle |
| `src/one/elements/Fragment.ts` | 130 | ✅ Done | Children tracking, start/stop |
| `src/one/elements/TextElement.ts` | 101 | ✅ Done | Đã có start/stop |
| `src/one/elements/Block.ts` | 80 | ⚠️ Partial | mount() cơ bản, cần test với BlockManager.mountAll() |
| `src/one/elements/BlockOutlet.ts` | 68 | ✅ Done | Render markers, setParent, start/stop |
| `src/one/elements/BlockManager.ts` | 209 | ✅ Done | Đã thêm mountAll(), clearOutlet(), clearAllOutlets(), destroy() |
| `src/one/elements/ElementManager.ts` | 46 | ✅ Done | Factory shortcuts h/t/r/f/o/b |
| `src/one/elements/index.ts` | 126 | ✅ Done | Factory signatures khớp compiled output, thêm __foreach/__while/__exec |
| `src/one/contracts/utils.ts` | 261 | ✅ Done | ViewControllerContract mở rộng: loop directives, App, start/stop |
| `src/one/types/utils.ts` | 176 | ✅ Done | ViewSetupConfig, OneChildrenFactory 1-arg, HtmlElementConfig.parent |
| `src/one/view/ViewManager.ts` | — | ❌ Chưa có | Cần tạo mới (Phase 9) |
| `src/one/routers/Router.ts` | — | ❌ Chưa có | Port từ core/ (Phase 10) |

### Pattern Compiled Output quan trọng

```
compiled .sao → factory function → new View → $__setup__(__data__, systemData)
  → closure scope: @vars, @useState (triple pattern), @let, @const
  → this.__ctrl__.setUserDefinedConfig({...})
  → this.__ctrl__.setup(config) // config chứa render, commitConstructorData, etc.
```

---

## 2. COMPILED OUTPUT — CONTRACT RUNTIME PHẢI TUÂN THỦ

### 2.1 Các method StateManager mà compiled output GỌI

> **Tất cả đã implement xong** trong ViewState.ts (Phase 1).

```typescript
// File: esamples/demo2.js — compiled output pattern

// 1. register(key) — ✅
const set$status = __STATE__.__.register('status');

// 2. canUpdateStateByKey — ✅
if (__STATE__.__.canUpdateStateByKey) { ... }

// 3. lockUpdateRealState() — ✅
lockUpdateRealState(); // → __STATE__.__.lockUpdateRealState()

// 4. updateRealState(state) — ✅
updateRealState(state); // → __STATE__.__.updateRealState(state)

// 5. updateStateByKey(key, value) — ✅
updateStateByKey('status', value);

// 6. setters registry — ✅
__STATE__.__.setters.setStatus = setStatus;
__STATE__.__.setters.status = setStatus;

// 7. __useState(value) — ✅ (trên ViewState class)
const useState = (value) => __STATE__.__useState(value);
```

### 2.2 Compiled render factory — API elements cần match

> **Tất cả factory functions đã implement xong** trong `elements/index.ts`.
> Compiled output import: `{ fragment, html, output, reactive, __foreach, __while, __exec }`

```javascript
// Từ esamples/demo2.js (hand-written DOM render — thay cho string-based compiler output):
import { fragment, html, output, reactive, __foreach, __while, __exec } from 'saola';

render: function() {
    let parentElement = null;
    let parentReactive = null;
    const ctx = this;
    return fragment({ ctx, parentElement }, (parentElement) => [
        html("div", { ctx, parentElement,
            classes: { "demo": { type: 'static', value: true }, "active": { type: 'binding', factory: () => status, stateKeys: ["status"] } },
            attrs: { "data-count": { type: 'binding', factory: () => App.Helper.count(demoList), stateKeys: ["demoList"] } }
        }, (parentElement) => [
            html("h1", { ctx, parentElement }, (parentElement) => [
                "Hello, ",
                output({ ctx, parentElement, stateKeys: ["user"], isEscapeHTML: true }, () => user["name"]),
                "!"
            ]),
            html("button", { ctx, parentElement, events: {click: [(event) => setStatus(!status)]} }, (parentElement) => [
                "Toggle: ",
                output({ ctx, parentElement, stateKeys: ["status"] }, () => status ? 'On' : 'Off')
            ]),
            reactive("if", {id: `rc-${__VIEW_ID__}-if-1`, ctx, parentElement, parentReactive, stateKeys: ["posts"]},
                (parentReactive, parentElement) => {
                    if (App.Helper.count(posts) === 0) {
                        return [html("li", { ctx, parentElement }, (parentElement) => ["No posts available."])];
                    } else {
                        return [
                            reactive("foreach", {id: `rc-...`, ctx, parentElement, parentReactive, stateKeys: ["posts"]},
                                (parentReactive, parentElement) => {
                                    return __foreach({ctx, parentElement}, posts, (post, key, index, loop) => [
                                        html("li", { ctx, parentElement }, (parentElement) => [post["title"]])
                                    ])
                                }
                            )
                        ];
                    }
                    return [];
                }
            )
        ])
    ]);
}
```

### 2.3 Compiled setup config — ViewController.setup() phải xử lý

> **Đã implement trong ViewController.ts** (Phase 2).
> `setup()` parse metadata, bind render factory. `commitData()` gọi `commitConstructorData()` 1 lần.

```typescript
// Ví dụ từ compiled demo2.js:
this.__ctrl__.setup({
    superView: null,           // string | null — layout path
    subscribe: true,           // boolean
    fetch: null,               // Function | null
    data: __data__,            // Record<string, any>
    viewId: __VIEW_ID__,       // string
    path: __VIEW_PATH__,       // string
    scripts: [],               // string[]
    styles: [],                // string[]
    resources: [],             // any[]
    commitConstructorData: function() {
        update$status(false);
        update$user({"name": "Jone", "email": "jon@test.com"});
        update$posts([...]);
        lockUpdateRealState();
    },
    updateVariableData: function(data) {
        for (const key in data) {
            if (data.hasOwnProperty(key)) {
                if (typeof this.config.updateVariableItemData === 'function') {
                    this.config.updateVariableItemData.call(this, key, data[key]);
                }
            }
        }
        update$status(false);
        update$user({"name": "Jone", "email": "jon@test.com"});
        update$posts([...]);
        lockUpdateRealState();
    },
    updateVariableItemData: function(key, value) {
        this.data[key] = value;
        if (typeof __UPDATE_DATA_TRAIT__[key] === "function") {
            __UPDATE_DATA_TRAIT__[key](value);
        }
    },
    prerender: function() { return null; },
    render: function() { /* element tree — xem section 2.2 */ }
});
```

**LƯU Ý QUAN TRỌNG**: Trong compiled output, `this` trong `render()`, `commitConstructorData()`, etc. tham chiếu tới **ViewController** (vì sẽ được gọi bằng `config.render.call(controller)`). Nhưng `updateVariableData` dùng `this.config.updateVariableItemData` — nghĩa là `this` ở đây phải là ViewController mà có property `config`.

### 2.4 Compiled file structure — Class layout

> Compiler output (sao2js) tạo file JS với structure sau:

```javascript
import { Application, View, ViewController, app, Reactive, fragment, html, output, reactive, __foreach, __while, __exec } from 'saola';

const __VIEW_PATH__ = 'demo2';
const __VIEW_NAMESPACE__ = '';
const __VIEW_TYPE__ = 'view';
const __VIEW_CONFIG__ = { hasSuperView: false, viewType: 'view', ... };

// Custom ViewController subclass — compiler always generates this
class demo2ViewController extends ViewController {
    constructor(view) {
        super(view, __VIEW_PATH__, __VIEW_TYPE__);
        this.config = __VIEW_CONFIG__;
    }
}

// View subclass — chứa $__setup__ với all state/variable declarations
class demo2View extends View {
    constructor(App, systemData) {
        super(__VIEW_PATH__, __VIEW_TYPE__, demo2ViewController);
        this.__ctrl__.setApp(App);
    }
    $__setup__(__data__, systemData) {
        // ... state declarations, render factory, etc.
    }
}

// Export factory function
export function demo2(__data__ = {}, systemData = {}) {
    const App = app("App");
    const view = new demo2View(App, systemData);
    view.$__setup__(__data__, systemData);
    return view;
}
```

---

## 3. PHASE 1: STATEMANAGER — MISSING METHODS ✅ HOÀN THÀNH

### File: `src/one/view/ViewState.ts`

### 3.1 Thêm vào class `StateManager`

**Vị trí**: Sau property declarations (dòng ~35), thêm:

```typescript
/** Flag — cho phép update state qua update$xxx chỉ trước lock */
private _canUpdateStateByKey: boolean = true;
```

**Vị trí**: Sau constructor, thêm getter:

```typescript
/** Public getter — compiled output check this trước updateStateByKey */
get canUpdateStateByKey(): boolean {
    return this._canUpdateStateByKey;
}
```

**Vị trí**: Sau `updateStateAddressKey()` method, thêm 3 methods:

```typescript
/**
 * Lock — ngăn update$xxx() hoạt động sau initialization.
 * Gọi cuối commitConstructorData().
 * Sau lock, chỉ setXxx() (public setter) mới đổi state.
 */
lockUpdateRealState(): void {
    this._canUpdateStateByKey = false;
}

/**
 * Unlock — cho phép updateVariableData gọi update$xxx lại.
 * Gọi trước updateVariableData(), lock lại khi xong.
 */
unlockUpdateRealState(): void {
    this._canUpdateStateByKey = true;
}

/**
 * Bulk state update — set nhiều state keys QUIETLY (không trigger).
 * Dùng trong initialization — set initial values trước khi lock.
 */
updateRealState(stateMap: Record<string | number, any>): void {
    if (!this._canUpdateStateByKey) return;
    for (const key in stateMap) {
        if (stateMap.hasOwnProperty(key) && this.states[key]) {
            this.states[key].value = stateMap[key];
        }
    }
}
```

### 3.2 Thêm `__useState` vào class `ViewState`

**Vị trí**: Sau `unsubscribe()` method trong class ViewState:

```typescript
/**
 * __useState — wrapper API cho compiled output.
 * Tương tự React useState, return [value, setter].
 * 
 * Compiled output: const useState = (value) => __STATE__.__useState(value);
 */
__useState(value: any, key?: string | number): [any, (newValue: any) => void] {
    const [val, setter] = this.__.useState(value, key);
    return [val, setter];
}
```

### 3.3 Validation checklist

- [ ] `__STATE__.__.register('count')` → trả về setter function
- [ ] `__STATE__.__.canUpdateStateByKey` → `true` trước lock, `false` sau
- [ ] `__STATE__.__.lockUpdateRealState()` → set flag `false`
- [ ] `__STATE__.__.unlockUpdateRealState()` → set flag `true`
- [ ] `__STATE__.__.updateRealState({count: 0, name: 'John'})` → set values quietly
- [ ] `__STATE__.__useState(0)` → trả về `[0, setter]`
- [ ] `__STATE__.__.setters.setCount = setCount` → gán được
- [ ] `__STATE__.__.updateStateByKey('count', 5)` → works khi canUpdate = true, no-op khi false

### 3.4 Dependencies

- Không có dependencies — phase này tự hoàn chỉnh

---

## 4. PHASE 2: VIEWCONTROLLER.SETUP() — DATA LIFECYCLE ✅ HOÀN THÀNH

### File: `src/one/view/ViewController.ts`

### 4.1 Thêm types

**File: `src/one/types/utils.ts`** — thêm cuối file:

```typescript
/** Config object từ compiled $__setup__ */
export interface ViewSetupConfig {
    data: Record<string, any>;
    viewId: string;
    path: string;
    superView: string | null;
    subscribe: boolean;
    scripts: string[];
    styles: string[];
    resources: any[];
    commitConstructorData: () => void;
    updateVariableData: (data: Record<string, any>) => void;
    updateVariableItemData: (key: string, value: any) => void;
    prerender: (() => string | null) | null;
    render: () => any;
    fetch: (() => Promise<any>) | null;
}
```

### 4.2 Sửa ViewController — Thêm properties

**Vị trí**: Sau `private config: Record<string, any> = {};` (khoảng dòng 55):

```typescript
/** Typed config from setup */
private _setupConfig: ViewSetupConfig | null = null;

/** Raw data from route/parent */
public data: Record<string, any> = {};

/** Layout/super view path */
public superViewPath: string | null = null;

/** Whether data has been committed (initial values set) */
private _isDataCommitted = false;
```

### 4.3 Refactor `setup()` method

**Thay thế method `setup()` hiện tại:**

```typescript
/**
 * Setup — called by compiled $__setup__ with full config.
 * 
 * Lưu config, extract metadata, lưu render factory.
 * CHƯA gọi commitConstructorData — đợi ViewManager gọi commitData().
 */
setup(config: Record<string, any>): void {
    this.config = config;
    this._setupConfig = config as any;

    // Extract metadata
    if (config.viewId) this.viewId = config.viewId;
    if (config.path) this.path = config.path;
    this.data = config.data || {};
    this.superViewPath = config.superView || null;

    // Store render factory
    if (typeof config.render === 'function') {
        this.setRenderFactory(config.render.bind(this));
    }
}
```

### 4.4 Thêm data lifecycle methods

**Vị trí**: Sau `destroy()` method:

```typescript
// ─── Data Lifecycle ─────────────────────────────────────────

/** Whether this view has a super view (layout) */
get hasSuperView(): boolean {
    return this.superViewPath !== null && this.superViewPath !== '';
}

/**
 * Commit initial data — set initial state values.
 * 
 * Called by ViewManager AFTER:
 * 1. View's element tree is rendered vào DOM
 * 2. Block ↔ BlockOutlet đã mount (nếu có layout)
 * 
 * Flow: commitConstructorData() → update$xxx(initial) → lockUpdateRealState()
 */
commitData(): void {
    if (this._isDataCommitted || !this._setupConfig) return;
    this._isDataCommitted = true;

    if (typeof this._setupConfig.commitConstructorData === 'function') {
        this._setupConfig.commitConstructorData.call(this);
    }
}

/**
 * Update data from external source (navigate same view, different params).
 * 
 * Flow: unlock → updateVariableData(newData) → re-set states → lock
 */
updateData(newData: Record<string, any>): void {
    if (!this._setupConfig) return;

    // Unlock so update$xxx functions work
    this.states.__.unlockUpdateRealState();

    // Merge data
    this.data = { ...this.data, ...newData };
    if (this._setupConfig.data) {
        Object.assign(this._setupConfig.data, newData);
    }

    if (typeof this._setupConfig.updateVariableData === 'function') {
        this._setupConfig.updateVariableData.call(this, newData);
    }
}

/**
 * Update single data item.
 */
updateDataItem(key: string, value: any): void {
    if (!this._setupConfig) return;

    if (typeof this._setupConfig.updateVariableItemData === 'function') {
        this._setupConfig.updateVariableItemData.call(this, key, value);
    }
}
```

### 4.5 LƯU Ý: `this` binding trong compiled config callbacks

Compiled output viết:
```javascript
commitConstructorData: function() {
    update$isOpen(false);  // closure reference — OK
    lockUpdateRealState(); // closure reference — OK
}
```

Dùng `function() {}` (not arrow), nhưng không dùng `this` bên trong.  
Các closures `update$isOpen`, `lockUpdateRealState` captured trong `$__setup__` scope.

**Tuy nhiên**, `updateVariableData` dùng `this`:
```javascript
updateVariableData: function(data) {
    // ...
    if (typeof this.config.updateVariableItemData === 'function') {
        this.config.updateVariableItemData.call(this, key, data[key]);
    }
}
```

Ở đây `this` phải là ViewController (vì có `this.config`). Nên gọi bằng:
```typescript
this._setupConfig.updateVariableData.call(this, newData);
```

### 4.6 Validation checklist

- [ ] `setup(config)` lưu metadata đúng (viewId, path, superView, data)
- [ ] `setup(config)` lưu render factory (bind this)
- [ ] `commitData()` gọi `commitConstructorData()` 1 lần duy nhất
- [ ] `commitData()` idempotent — gọi lần 2 không làm gì
- [ ] `updateData({key: newValue})` unlock → update → (tự lock trong callback)
- [ ] `updateDataItem('key', value)` delegate đúng
- [ ] `hasSuperView` trả `false` khi `superView: null`
- [ ] Compiled demo2.js `$__setup__` chạy không lỗi

### 4.7 Dependencies

- **Phase 1** phải hoàn thành trước (StateManager.lockUpdateRealState, canUpdateStateByKey)

---

## 5. PHASE 3: OUTPUT ELEMENT — FULL IMPLEMENTATION ✅ HOÀN THÀNH

### File: `src/one/elements/Output.ts`

### 5.1 Full implementation

```typescript
import type { HtmlContract, OutputContract, ReactiveContract, ViewControllerContract } from "../contracts/utils";
import { escapeHTML, generateUUID } from "../hellpers/utils";
import markerRegistry from "../services/MarkerRegistry";

/**
 * Output — reactive text output between comment markers.
 * 
 * Compiled từ: {{ $expression }}  → output({ctx, parentElement, stateKeys, isEscapeHTML}, () => expression)
 * Compiled từ: {!! $expression !!} → output({..., isEscapeHTML: false}, () => expression)
 * 
 * Render: Tạo Text node giữa <!--o:id-s--> và <!--o:id-e-->
 * Update: Khi stateKeys thay đổi → re-evaluate contentFactory → update textContent
 * 
 * Tại sao dùng comment markers thay vì chỉ Text node?
 * → Vì expression có thể trả về empty string → Text node biến mất
 * → Markers giữ vị trí ổn định trong DOM cho re-insert
 */
export class Output implements OutputContract {
    ctx: ViewControllerContract;
    parent: HtmlContract | null;
    openTag: Comment;
    closeTag: Comment;
    stateKeys: string[];
    contentFactory: () => string;
    isEscapeHTML: boolean;

    private textNode: Text | null = null;
    private unsubscribe: (() => void) | null = null;
    private _isStarted = false;
    private _isDestroyed = false;
    private id: string;

    constructor({
        ctx,
        parent = null,
        stateKeys = [],
        contentFactory = () => '',
        isEscapeHTML = true
    }: {
        ctx: ViewControllerContract;
        parent?: HtmlContract | null;
        stateKeys?: string[];
        contentFactory?: () => string;
        isEscapeHTML?: boolean;
    }) {
        this.ctx = ctx;
        this.parent = parent;
        this.stateKeys = stateKeys;
        this.contentFactory = contentFactory;
        this.isEscapeHTML = isEscapeHTML;
        this.id = generateUUID(8);

        this.openTag = document.createComment(`o:${this.id}-s`);
        this.closeTag = document.createComment(`o:${this.id}-e`);
    }

    /**
     * Render — insert markers + initial text into parent element.
     */
    render(): void {
        if (!this.parent?.element || this._isDestroyed) return;

        const parentEl = this.parent.element;

        // Append markers
        parentEl.appendChild(this.openTag);

        // Create initial text content
        const rawText = String(this.contentFactory() ?? '');
        const displayText = this.isEscapeHTML ? escapeHTML(rawText) : rawText;

        this.textNode = document.createTextNode(displayText);
        parentEl.appendChild(this.textNode);

        parentEl.appendChild(this.closeTag);
    }

    /**
     * Start — subscribe to state changes for reactive updates.
     * Called during Phase 2 (START) of view lifecycle.
     */
    start(): void {
        if (this._isStarted || this._isDestroyed) return;
        this._isStarted = true;

        if (this.stateKeys.length > 0) {
            this.unsubscribe = this.ctx.states.__.subscribe(
                this.stateKeys,
                () => this.update()
            );
        }
    }

    /**
     * Stop — unsubscribe from state changes.
     * Called when view is deactivated (navigate away, cached).
     */
    stop(): void {
        if (!this._isStarted) return;
        this._isStarted = false;

        if (this.unsubscribe) {
            this.unsubscribe();
            this.unsubscribe = null;
        }
    }

    /**
     * Update — re-evaluate contentFactory and update text node.
     * O(1) operation — chỉ thay textContent, không tạo/xóa DOM nodes.
     */
    private update(): void {
        if (!this.textNode || this._isDestroyed) return;

        const rawText = String(this.contentFactory() ?? '');
        const displayText = this.isEscapeHTML ? escapeHTML(rawText) : rawText;

        if (this.textNode.textContent !== displayText) {
            this.textNode.textContent = displayText;
        }
    }

    /**
     * Destroy — cleanup everything.
     */
    destroy(): void {
        if (this._isDestroyed) return;
        this._isDestroyed = true;
        this.stop();

        // Remove text node
        if (this.textNode) {
            this.textNode.remove();
            this.textNode = null;
        }

        // Remove markers
        this.openTag.remove();
        this.closeTag.remove();

        this.parent = null;
    }

    // ─── OneElement markers ─────────────────────────────

    get isOneElement(): boolean { return true; }
    set isOneElement(_: boolean) {}
    get isOneOutput(): boolean { return true; }
    set isOneOutput(_: boolean) {}
}
```

### 5.2 Tích hợp với Html.render()

Hiện tại `Html.render()` xử lý children:
```typescript
if ('element' in child) {
    this.element.appendChild(child.element);
    this.children.push(child);
    child.render();
}
```

Output KHÔNG có property `element` — nó có `openTag`/`closeTag`. Cần thêm case cho Output trong Html.render():

**File: `src/one/elements/Html.ts`** — trong `render()` method, sau check `'element' in child`:

```typescript
} else if ('openTag' in child && 'closeTag' in child) {
    // Output, Reactive, Fragment — render sẽ tự append vào parent
    child.parent = this; // set parent reference
    this.children.push(child);
    child.render();
}
```

### 5.3 Validation checklist

- [ ] `output({ctx, parent, stateKeys: ["count"]}, () => count)` → tạo text "0" giữa markers
- [ ] State change `setCount(5)` → text cập nhật thành "5" (sau start())
- [ ] `isEscapeHTML: true` → `<script>` thành `&lt;script&gt;`
- [ ] `isEscapeHTML: false` → giữ nguyên HTML
- [ ] `destroy()` → remove markers + text, cleanup subscriptions

### 5.4 Dependencies

- Phase 1 (StateManager subscribe)
- Html.render() phải handle Output children (Phase 5.2)

---

## 6. PHASE 4: HTML REACTIVE BINDINGS ✅ HOÀN THÀNH

### File: `src/one/elements/Html.ts`

### 6.1 Hiện trạng

`initializeAttributes()` xử lý `config.attrs` và `config.props` nhưng:
- `type: 'binding'` → chỉ set giá trị ban đầu, TODO cho reactive
- `config.classes` → CHƯA xử lý  
- `config.styles` → CHƯA xử lý

### 6.2 Thêm properties để track subscriptions

```typescript
/** All state subscriptions for reactive bindings — cleanup on destroy */
private bindingUnsubscribes: (() => void)[] = [];
```

### 6.3 Reactive Attribute Bindings

Thay TODO trong `initializeAttributes()` `attrs` section:

```typescript
// Reactive binding for attributes
if (attrConfig.type === 'binding' && attrConfig.stateKeys?.length) {
    const unsubscribe = this.ctx.states.__.subscribe(
        attrConfig.stateKeys,
        () => {
            const newValue = attrConfig.factory ? attrConfig.factory() : '';
            if (newValue !== undefined && newValue !== null && newValue !== false) {
                this.element.setAttribute(attrName, String(newValue));
            } else {
                this.element.removeAttribute(attrName);
            }
        }
    );
    this.bindingUnsubscribes.push(unsubscribe);
}
```

### 6.4 Reactive Property Bindings

Thay TODO trong `initializeAttributes()` `props` section:

```typescript
// Reactive binding for properties
if (propConfig.type === 'binding' && propConfig.stateKeys?.length) {
    const unsubscribe = this.ctx.states.__.subscribe(
        propConfig.stateKeys,
        () => {
            const newValue = propConfig.factory ? propConfig.factory() : '';
            if (newValue !== undefined && newValue !== null && newValue !== false) {
                (this.element as any)[propName] = newValue;
            } else {
                (this.element as any)[propName] = false;
                delete (this.element as any)[propName];
            }
        }
    );
    this.bindingUnsubscribes.push(unsubscribe);
}
```

### 6.5 Thêm `initializeClasses()` method

```typescript
private initializeClasses(): void {
    if (!this.config.classes) return;

    for (const [className, classConfig] of Object.entries(this.config.classes)) {
        if (classConfig.type === 'static') {
            if (classConfig.value) {
                this.element.classList.add(className);
            }
        } else if (classConfig.type === 'binding') {
            // Initial value
            const initialValue = classConfig.factory ? classConfig.factory() : !!classConfig.value;
            this.element.classList.toggle(className, !!initialValue);

            // Subscribe for reactive updates
            if (classConfig.stateKeys?.length) {
                const unsubscribe = this.ctx.states.__.subscribe(
                    classConfig.stateKeys,
                    () => {
                        const newValue = classConfig.factory ? classConfig.factory() : false;
                        this.element.classList.toggle(className, !!newValue);
                    }
                );
                this.bindingUnsubscribes.push(unsubscribe);
            }
        }
    }
}
```

### 6.6 Thêm `initializeStyles()` method

```typescript
private initializeStyles(): void {
    if (!this.config.styles) return;

    for (const [prop, styleConfig] of Object.entries(this.config.styles)) {
        if (styleConfig.type === 'value') {
            this.element.style.setProperty(prop, styleConfig.value ?? '');
        } else if (styleConfig.type === 'binding') {
            // Initial value
            const initialValue = styleConfig.factory ? styleConfig.factory() : (styleConfig.value ?? '');
            this.element.style.setProperty(prop, initialValue);

            // Subscribe for reactive updates
            if (styleConfig.stateKeys?.length) {
                const unsubscribe = this.ctx.states.__.subscribe(
                    styleConfig.stateKeys,
                    () => {
                        const newValue = styleConfig.factory ? styleConfig.factory() : '';
                        this.element.style.setProperty(prop, newValue);
                    }
                );
                this.bindingUnsubscribes.push(unsubscribe);
            }
        }
    }
}
```

### 6.7 Update `initialize()` method

```typescript
private initialize() {
    this.initializeAttributes();
    this.initializeClasses();
    this.initializeStyles();
    this.initializeEvents();
}
```

### 6.8 Update `destroy()` — cleanup subscriptions

```typescript
destroy() {
    this.abortController.abort();
    this.abortController = new AbortController();

    // Cleanup reactive binding subscriptions
    for (const unsub of this.bindingUnsubscribes) {
        unsub();
    }
    this.bindingUnsubscribes = [];

    this.children.forEach(child => {
        if ('destroy' in child && typeof child.destroy === 'function') {
            child.destroy();
        }
    });
    this.children = [];
    if (this.element.children.length > 0) {
        this.element.innerHTML = '';
    }
}
```

### 6.9 Thêm start/stop cho Html

```typescript
/** Start reactive bindings (Phase 2 lifecycle) */
start(): void {
    // Start children recursively
    for (const child of this.children) {
        if ('start' in child && typeof (child as any).start === 'function') {
            (child as any).start();
        }
    }
}

/** Stop reactive bindings */
stop(): void {
    for (const child of this.children) {
        if ('stop' in child && typeof (child as any).stop === 'function') {
            (child as any).stop();
        }
    }
}
```

### 6.10 Validation checklist

- [ ] `classes: { "active": { type: 'binding', factory: () => status, stateKeys: ["status"] } }`
  → `classList.toggle("active", true/false)` khi status thay đổi
- [ ] `attrs: { "data-count": { type: 'binding', factory: () => count, stateKeys: ["count"] } }`
  → `setAttribute("data-count", "5")` khi count thay đổi
- [ ] `attrs: { "disabled": { type: 'binding', factory: () => isDisabled ? '' : false } }`
  → `removeAttribute("disabled")` khi false
- [ ] Static class `{ type: 'static', value: true }` → `classList.add('demo')` một lần
- [ ] `destroy()` cleanup tất cả subscriptions

### 6.11 Dependencies

- Phase 1 (StateManager.subscribe) 

---

## 7. PHASE 5: ELEMENTMANAGER FACTORY FUNCTIONS ✅ HOÀN THÀNH

### File: `src/one/elements/ElementManager.ts`

### 7.1 Thêm shorthand factory methods

```typescript
import type { ViewControllerContract, HtmlContract, ReactiveContract } from "../contracts/utils";
import type { OneElementConfig, OneChildrenFactory, BlockRenderFactory, ReactiveChildrenFactory, HtmlElementConfig } from "../types/utils";
import { Html } from "./Html";
import { TextElement } from "./TextElement";
import { Reactive } from "./Reactive";
import { Fragment } from "./Fragment";
import { Block } from "./Block";
import { Output } from "./Output";

export class ElementManagerService {
    private factories: Map<string, (...args: any[]) => any> = new Map();

    // ─── Element Shorthand Factories ────────────────────────────

    /** html element — h("div", config, childrenFactory) */
    h(tagName: string, config: HtmlElementConfig, childrenFactory?: OneChildrenFactory | null): Html {
        return new Html({
            tagName,
            ctx: config.ctx,
            parent: config.parentElement ?? null,
            config: {
                attrs: config.attrs || {},
                props: config.props || {},
                events: config.events || {},
                classes: config.classes || {},
                styles: config.styles || {},
            },
            childrenFactory: childrenFactory ?? null,
        });
    }

    /** text element — t(config, textFactory) */
    t(config: {
        ctx: ViewControllerContract;
        parent?: HtmlContract | null;
        stateKeys?: string[];
        isEscapeHTML?: boolean;
    }, textFactory: () => string): TextElement {
        return new TextElement({
            ctx: config.ctx,
            parent: config.parent ?? null,
            stateKeys: config.stateKeys || [],
            generateText: textFactory,
            isEscapeHTML: config.isEscapeHTML ?? true,
        });
    }

    /** reactive region — r(type, config, childrenFactory) */
    r(type: string, config: {
        id?: string | null;
        ctx: ViewControllerContract;
        parentElement?: HtmlContract | null;
        parentReactive?: ReactiveContract | null;
        stateKeys?: string[];
        options?: Record<string, any>;
    }, childrenFactory: ReactiveChildrenFactory): Reactive {
        return new Reactive({
            type: type || 'reactive',
            id: config.id ?? null,
            ctx: config.ctx,
            stateKeys: config.stateKeys || [],
            childrenFactory,
            parentReactive: config.parentReactive ?? null,
            parentElement: config.parentElement ?? null,
            options: config.options || {},
        });
    }

    /** fragment — f(config, childrenFactory) */
    f(config: {
        ctx: ViewControllerContract;
        parent?: HtmlContract | null;
    }, childrenFactory: OneChildrenFactory): Fragment {
        return new Fragment(config.ctx, config.parent ?? null, childrenFactory);
    }

    /** output — o(config, contentFactory) */
    o(config: {
        ctx: ViewControllerContract;
        parent?: HtmlContract | null;
        stateKeys?: string[];
        isEscapeHTML?: boolean;
    }, contentFactory: () => string): Output {
        return new Output({
            ctx: config.ctx,
            parent: config.parent ?? null,
            stateKeys: config.stateKeys || [],
            contentFactory,
            isEscapeHTML: config.isEscapeHTML ?? true,
        });
    }

    /** block — b(config) */
    b(config: {
        ctx: ViewControllerContract;
        name: string;
        viewId?: string | null;
        contentRenderFactory?: BlockRenderFactory;
    }): Block {
        return new Block({
            ctx: config.ctx,
            name: config.name,
            viewId: config.viewId ?? null,
            contentRenderFactory: config.contentRenderFactory,
        });
    }

    // ─── Custom Component Registry ─────────────────────────────

    set(name: string, factory: (...args: any[]) => any): void {
        this.factories.set(name, factory);
    }

    get(name: string): ((...args: any[]) => any) | undefined {
        return this.factories.get(name);
    }

    has(name: string): boolean {
        return this.factories.has(name);
    }
}

export const ElementManager = new ElementManagerService();
export default ElementManager;
```

### 7.2 Dependencies

- Phase 3 (Output class)

---

## 8. PHASE 6: ELEMENTS/INDEX.TS — FIX FACTORY SIGNATURES ✅ HOÀN THÀNH

### File: `src/one/elements/index.ts`

### 8.1 Vấn đề hiện tại

Compiled output gọi:
```javascript
html("div", { ctx, parentElement, classes: {...} }, (parentElement) => [...])
reactive("if", {id, ctx, parentElement, parentReactive, stateKeys}, (parentReactive, parentElement) => [...])
output({ctx, parentElement, stateKeys, isEscapeHTML}, () => value)
fragment({ctx, parentElement}, (parentElement) => [...])
```

Nhưng factory function `reactive()` hiện tại nhận params khác:
```typescript
function reactive(type, config: { viewController, statesKeys, ... })
//                              ^^^ statesKeys vs stateKeys  ← TYPO!
//                              ^^^ viewController vs ctx    ← KHÁC TÊN!
```

### 8.2 Fix `reactive()` factory

```typescript
export function reactive(
    type: string,
    config: {
        id?: string | null;
        ctx: ViewControllerContract;
        parentReactive?: Reactive | null | undefined;
        parentElement?: HtmlContract | null;
        stateKeys?: string[];
        options?: Record<string, any>;
    },
    childrenFactory?: ReactiveChildrenFactory
): Reactive {
    return new Reactive({
        type: type || 'reactive',
        id: config.id || null,
        ctx: config.ctx,
        stateKeys: config.stateKeys || [],
        childrenFactory: childrenFactory || (() => []),
        parentReactive: config.parentReactive || null,
        parentElement: config.parentElement || null,
        options: config.options || {},
    });
}
```

**Thay đổi**: `viewController` → `ctx`, `statesKeys` → `stateKeys`

### 8.3 Fix `html()` factory — handle parent field name

Compiled output truyền `parentElement` nhưng Html constructor expects `parent`:

```typescript
export function html(
    tagName: string,
    config: HtmlElementConfig,
    childrenFactory?: OneChildrenFactory
): Html {
    return new Html({
        tagName,
        parent: config.parentElement ?? config.parent ?? null,
        ctx: config.ctx,
        childrenFactory: childrenFactory ?? null,
        config: {
            attrs: config.attrs || {},
            props: config.props || {},
            events: config.events || {},
            classes: config.classes || {},
            styles: config.styles || {},
        }
    });
}
```

### 8.4 Đảm bảo `HtmlElementConfig` type hỗ trợ cả `parent` và `parentElement`

**File: `src/one/types/utils.ts`** — sửa:

```typescript
export type HtmlElementConfig = OneElementConfig & {
    ctx: ViewControllerContract;
    parentElement?: HtmlContract | null;  // compiled output dùng tên này
    parent?: HtmlContract | null;         // internal dùng tên này
}
```

### 8.5 Dependencies

- Phase 3 (Output class cho output() factory)

---

## 9. PHASE 7: VIEWCONTROLLER START/STOP LIFECYCLE ✅ HOÀN THÀNH

### File: `src/one/view/ViewController.ts`

### 9.1 Thêm `start()` method

```typescript
/**
 * Start — activate reactive subscriptions trên tất cả elements.
 * Called AFTER: render + commitData.
 * 
 * Walk element tree:
 * - Output.start() → subscribe stateKeys
 * - TextElement.start() → subscribe stateKeys
 * - Reactive already subscribed via __reactive() helper
 * - Html: start children recursively
 */
start(): void {
    if (this._isMounted && !this._isDestroyed) {
        // Walk the root element tree and start all child elements
        if (this.rootElement) {
            this.startElement(this.rootElement);
        }
        // Call lifecycle hook
        if (typeof this.view.onMounted === 'function') {
            this.view.onMounted();
        }
    }
}

private startElement(element: any): void {
    if (typeof element.start === 'function') {
        element.start();
    }
    // Recursively start children if available
    if (element.children && Array.isArray(element.children)) {
        for (const child of element.children) {
            this.startElement(child);
        }
    }
}
```

### 9.2 Thêm `stop()` method

```typescript
/**
 * Stop — deactivate reactive subscriptions.
 * View DOM stays in place (for caching), only reactivity stops.
 */
stop(): void {
    if (!this._isDestroyed) {
        if (this.rootElement) {
            this.stopElement(this.rootElement);
        }
        if (typeof this.view.onDeactivated === 'function') {
            this.view.onDeactivated();
        }
    }
}

private stopElement(element: any): void {
    if (typeof element.stop === 'function') {
        element.stop();
    }
    if (element.children && Array.isArray(element.children)) {
        for (const child of element.children) {
            this.stopElement(child);
        }
    }
}
```

### 9.3 Dependencies

- Phase 3 (Output.start/stop)
- Phase 4 (Html.start/stop)

---

## 10. PHASE 8: BLOCK ↔ BLOCKOUTLET MOUNTING ✅ HOÀN THÀNH

### Files: `Block.ts`, `BlockOutlet.ts`, `BlockManager.ts`

### 10.1 BlockManager — thêm `register()` và `mountAll()`

```typescript
// BlockManagerService — thêm methods:

/**
 * Register a block slot in a layout view.
 * Called by ViewController.__useBlock(name, parentElement).
 */
register(name: string, parentElement: HtmlContract): { slot: Reactive } {
    // Tạo Reactive region cho block slot
    const reactive = new Reactive({
        type: 'block-slot',
        ctx: parentElement.ctx || null,
        parentElement,
        stateKeys: [],
        childrenFactory: () => [],
    });
    // TODO: store reactive reference for mounting later
    return { slot: reactive };
}

/**
 * Mount all registered blocks into their corresponding outlets.
 * Called by ViewManager after layout + page views are both rendered.
 */
mountAll(): void {
    for (const [name, block] of this.activeBlocks) {
        const outlet = this.blockOutlets.get(name);
        if (outlet && block.contentRenderFactory) {
            this.mountBlockIntoOutlet(block, outlet);
        }
    }
}

/**
 * Mount a single block's content into an outlet.
 */
private mountBlockIntoOutlet(block: BlockContract, outlet: BlockOutletContract): void {
    if (!outlet.parent?.element) return;

    const parentEl = outlet.parent.element;

    // Render block content
    const children = block.contentRenderFactory!(block.ctx);

    // Insert between outlet markers
    for (const child of children) {
        if (typeof child === 'string' || typeof child === 'number') {
            const textNode = document.createTextNode(String(child));
            parentEl.insertBefore(textNode, outlet.closeTag);
        } else if (child && typeof child === 'object') {
            if ('element' in child) {
                parentEl.insertBefore(child.element, outlet.closeTag);
                child.render();
            } else if ('openTag' in child) {
                // Reactive/Fragment — set parent, render
                (child as any).parentElement = outlet.parent;
                child.render();
            }
        }
    }
}

/**
 * Clear content from an outlet (for page swap).
 */
clearOutlet(name: string): void {
    const outlet = this.blockOutlets.get(name);
    if (!outlet) return;

    let current = outlet.openTag.nextSibling;
    while (current && current !== outlet.closeTag) {
        const next = current.nextSibling;
        current.remove();
        current = next;
    }
}

destroy(): void {
    this.blocks.clear();
    this.blockOutlets.clear();
    this.activeBlocks.clear();
    this.listeners.clear();
}
```

### 10.2 Dependencies

- Phase 3 (Output), Phase 4 (Html), Phase 6 (factory functions)

---

## 11. PHASE 9: VIEWMANAGER — ORCHESTRATOR

### File MỚI: `src/one/view/ViewManager.ts`

### 11.1 Responsibilities

1. Load view modules (dynamic import)
2. Invoke factory → get View instance
3. Resolve layout chain (if extends)
4. Mount vào container
5. commitData cho all views
6. Start all views
7. Track active views for cleanup

### 11.2 Skeleton

```typescript
import type { ApplicationContract, ViewContract, ViewControllerContract } from "../contracts/utils";
import { BlockManager } from "../elements/BlockManager";

export class ViewManager {
    private app: ApplicationContract;
    private container: HTMLElement;
    private activeViews: Map<string, ViewContract> = new Map();
    private currentLayout: ViewContract | null = null;

    constructor(app: ApplicationContract, container: HTMLElement) {
        this.app = app;
        this.container = container;
    }

    /**
     * Mount a view by name.
     * 
     * Flow:
     * 1. Load module
     * 2. Create instance via factory(data, systemData)
     * 3. Check hasSuperView → resolve layout chain
     * 4. Mount outermost to container
     * 5. Mount blocks into outlets
     * 6. commitData for all views
     * 7. Start all views
     */
    async mountView(name: string, data: Record<string, any> = {}): Promise<void> {
        // 1. Load module
        const module = await this.loadModule(name);
        const factory = module.default || module[name];

        // 2. Create instance
        const systemData = this.buildSystemData();
        const view: ViewContract = factory(data, systemData);
        const ctrl = view.__ctrl__;

        // 3. Check layout
        if (ctrl.hasSuperView) {
            await this.mountWithLayout(view, ctrl, data, systemData);
        } else {
            this.mountDirect(view, ctrl);
        }
    }

    private mountDirect(view: ViewContract, ctrl: ViewControllerContract): void {
        // Create wrapper Html for container
        // ctrl.setRootElement(wrapper)
        // ctrl.render()
        // ctrl.commitData()
        // ctrl.start()
    }

    private async mountWithLayout(
        pageView: ViewContract,
        pageCtrl: ViewControllerContract,
        data: Record<string, any>,
        systemData: Record<string, any>
    ): Promise<void> {
        // Resolve layout chain
        // Collect blocks from page
        // Mount outermost layout
        // BlockManager.mountAll()
        // commitData for all
        // Start all
    }

    private async loadModule(name: string): Promise<any> {
        // Dynamic import based on path convention
        // e.g., name = 'web.home' → import('/views/web/home.js')
        throw new Error('ViewLoader not implemented');
    }

    private buildSystemData(): Record<string, any> {
        return {
            __base__: null,
            __layout__: null,
            __page__: null,
            __component__: null,
            __template__: null,
            __context__: null,
            __partial__: null,
            __system__: null,
            __env: {},
            __helper: {},
        };
    }

    /** Unmount current view + layout */
    unmount(): void {
        for (const [, view] of this.activeViews) {
            view.__ctrl__.destroy();
        }
        this.activeViews.clear();
        this.currentLayout = null;
    }
}
```

### 11.3 Dependencies

- ALL previous phases (1-8)

---

## 12. PHASE 10: ROUTER

### Port from `src/core/routers/`

Router cần:
- History API / Hash mode
- Route pattern matching (`/users/:id`)
- Navigation guards (before/after)  
- Route → ViewManager.mountView()
- Link interception (`<a>` clicks)
- Back/forward caching

**Approach**: Port từ core/ Router, thay `ViewManager` integration.

### Dependencies

- Phase 9 (ViewManager)

---

## 13. PHASE 11: RECONCILIATION (KEYED LISTS)

### File MỚI: `src/one/elements/Reconciler.ts`

Algorithm: LIS-based keyed reconciliation (giống Vue 3).

```
OldList: [A, B, C, D, E]
NewList: [A, C, B, F, E]

1. Prefix match: A = A → skip
2. Suffix match: E = E → skip
3. Middle: old=[B,C,D] new=[C,B,F]
   - keyMap: {B:1, C:2, D:3}
   - C → found → move
   - B → found → move
   - F → not found → create
   - D → not in new → remove
```

### Dependencies

- Phase 9 (ViewManager) cho integration testing

---

## 14. PHASE 12: SSR HYDRATION

### Approach

1. Server (Laravel Blade) renders HTML with comment markers
2. Client scans markers → find existing DOM nodes
3. Attach reactive bindings instead of re-creating DOM
4. Elements get `hydrateMode` flag

### Dependencies

- ALL previous phases

---

## 15. DEPENDENCY GRAPH

```
Phase 1: StateManager methods         ← NO DEPENDENCIES
    │
    ├── Phase 2: ViewController.setup()
    │       │
    │       ├── Phase 3: Output element
    │       │       │
    │       │       ├── Phase 5: ElementManager factories
    │       │       │
    │       │       └── Phase 6: index.ts factory fixes
    │       │
    │       ├── Phase 4: Html reactive bindings
    │       │
    │       └── Phase 7: ViewController start/stop
    │               │
    │               ├── Phase 8: Block ↔ BlockOutlet
    │               │       │
    │               │       └── Phase 9: ViewManager
    │               │               │
    │               │               ├── Phase 10: Router
    │               │               │
    │               │               ├── Phase 11: Reconciliation
    │               │               │
    │               │               └── Phase 12: SSR Hydration
    │               │
    │               └── [Can test single view rendering at this point]
    │
    └── [Can test StateManager at this point]
```

**MILESTONE 1** (sau Phase 1-4, 6): Compiled demo2.js `$__setup__` chạy không lỗi.  
**MILESTONE 2** (sau Phase 7): Single view render + reactive updates hoạt động.  
**MILESTONE 3** (sau Phase 8-9): Layout + blocks hoạt động.  
**MILESTONE 4** (sau Phase 10): SPA navigation hoạt động.

---

## 16. TEST CHECKLIST

### Test 1: StateManager (sau Phase 1)

```typescript
const viewState = new ViewState();
const sm = viewState.__;

// register + canUpdateStateByKey
const setter = sm.register('count', 0);
assert(sm.canUpdateStateByKey === true);

// updateStateByKey works before lock
sm.updateStateByKey('count', 5);
assert(sm.getStateByKey('count') === 5);

// lock
sm.lockUpdateRealState();
assert(sm.canUpdateStateByKey === false);

// updateStateByKey no-op after lock? 
// NOTE: updateStateByKey itself still works — 
// canUpdateStateByKey is only checked by compiled update$xxx guard
sm.updateStateByKey('count', 10); // still works
assert(sm.getStateByKey('count') === 10);

// unlock
sm.unlockUpdateRealState();
assert(sm.canUpdateStateByKey === true);

// __useState on ViewState
const [val, setVal] = viewState.__useState(42, 'test');
assert(val === 42);
```

### Test 2: ViewController Setup (sau Phase 2)

```typescript
const view = new View('test', 'view');
const ctrl = view.__ctrl__;

let committed = false;
ctrl.setup({
    data: { id: 1 },
    viewId: 'v-test',
    path: 'test',
    superView: null,
    subscribe: true,
    scripts: [], styles: [], resources: [],
    commitConstructorData: () => { committed = true; },
    updateVariableData: () => {},
    updateVariableItemData: () => {},
    prerender: null,
    render: () => null,
    fetch: null,
});

assert(ctrl.viewId === 'v-test');
assert(ctrl.data.id === 1);
assert(ctrl.hasSuperView === false);
assert(committed === false); // Not yet

ctrl.commitData();
assert(committed === true);
ctrl.commitData(); // idempotent
```

### Test 3: Output Rendering (sau Phase 3)

```typescript
// Create container
const container = document.createElement('div');
const parentHtml = { element: container, parent: null, render: () => {} };

let count = 0;
const out = new Output({
    ctx: ctrl,
    parent: parentHtml,
    stateKeys: ['count'],
    contentFactory: () => String(count),
    isEscapeHTML: true,
});

out.render();
assert(container.textContent === '0');

// Start + update
out.start();
count = 5;
ctrl.states.__.updateStateByKey('count', 5);
// After RAF flush:
assert(container.textContent === '5');
```

### Test 4: Full Compiled View (sau Phase 7)

```
// Import compiled demo2.js
// Call factory function
// Verify DOM tree created
// Trigger state change
// Verify DOM updated
```

### Test 5: Layout + Blocks (sau Phase 8-9)

```
// Create layout view with blockOutlet("content")
// Create page view with block("content", renderFactory)
// ViewManager.mountView()
// Verify page content appears in layout outlet
```

---

## PHỤ LỤC A: FILE MAP NHANH

```
Cần SỬA:
  src/one/view/ViewState.ts          → Phase 1 (4 methods)
  src/one/view/ViewController.ts     → Phase 2 (setup refactor) + Phase 7 (start/stop)
  src/one/elements/Output.ts         → Phase 3 (full rewrite)
  src/one/elements/Html.ts           → Phase 4 (reactive bindings) 
  src/one/elements/ElementManager.ts → Phase 5 (factory shortcuts)
  src/one/elements/index.ts          → Phase 6 (fix signatures)
  src/one/elements/BlockManager.ts   → Phase 8 (mountAll)
  src/one/types/utils.ts             → Phase 2 (ViewSetupConfig type)
  src/one/contracts/utils.ts         → Phase 2 (update contracts)

Cần TẠO MỚI:
  src/one/view/ViewManager.ts        → Phase 9
  src/one/routers/Router.ts          → Phase 10
  src/one/elements/Reconciler.ts     → Phase 11
```

## PHỤ LỤC B: COMPILED OUTPUT REFERENCE — `esamples/demo2.js`

Đây là reference chính cho runtime API. Mọi thay đổi runtime PHẢI tương thích với output này.

**Key patterns**:

1. `__STATE__.__useState(value)` — ViewState wrapper
2. `__STATE__.__.register('key')` — StateManager register
3. `__STATE__.__.canUpdateStateByKey` — boolean flag
4. `__STATE__.__.lockUpdateRealState()` — lock after init
5. `__STATE__.__.updateRealState(state)` — bulk quiet update
6. `__STATE__.__.setters.xxx = fn` — external setter registry
7. `this.__ctrl__.setup(config)` — full config with render + lifecycle callbacks
8. `this.__ctrl__.setUserDefinedConfig({})` — attach methods to View
9. `fragment({ctx, parentElement}, childrenFactory)` — root wrapper
10. `html("tag", {ctx, parentElement, classes, attrs, events}, childrenFactory)` — element
11. `output({ctx, parentElement, stateKeys, isEscapeHTML}, contentFactory)` — text output
12. `reactive("type", {id, ctx, parentElement, parentReactive, stateKeys}, childrenFactory)` — reactive region
13. `__foreach({ctx, parentElement}, list, callback)` — loop
14. `__while({ctx, parentElement, start, max}, callback)` — while loop

---

*Tài liệu này là source of truth cho implementation. Update khi có thay đổi.*
