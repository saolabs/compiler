# Core Directives Development Wiki

> **Mục đích**: Tài liệu chi tiết về cách các Blade directives hoạt động bên trong hệ thống Saola + Laravel Core. Dành cho developers phát triển hoặc maintain hệ thống.
> 
> **Cập nhật**: 7/3/2026 | **Phiên bản**: 1.0

---

## Mục lục

1. [Kiến trúc Hydration](#kiến-trúc-hydration)
2. [Các Global Variables](#các-global-variables)
3. [Core Services](#core-services)
4. [Directive Reference](#directive-reference)
   - [Reactive Directives](#reactive-directives)
   - [State Directives](#state-directives)
   - [Binding Directives](#binding-directives)
   - [Event Directives](#event-directives)
   - [Layout Directives](#layout-directives)
   - [Component Directives](#component-directives)
5. [Hydration ID System](#hydration-id-system)
6. [Marker Registry](#marker-registry)

---

## Kiến trúc Hydration

```
┌─────────────────────────────────────────────────────────┐
│ 1. SERVER RENDERING (Blade PHP)                         │
├─────────────────────────────────────────────────────────┤
│ • @pageStart → Page wrapper marker + ssrData JSON       │
│ • @vars/@useState → Khởi tạo state values               │
│ • @startMarker/@startReactive → Comment markers HTML    │
│ • {{ $expr }} → PHP echo (escaped)                      │
│ • @endMarker/@endReactive → Close markers               │
│ • @pageEnd → Append JS payload (VIEW_ID, states, etc)   │
└────────────┬────────────────────────────────────────────┘
             │ Browser downloads HTML
             ▼
┌─────────────────────────────────────────────────────────┐
│ 2. CLIENT HYDRATION (JavaScript)                        │
├─────────────────────────────────────────────────────────┤
│ • App.init() → Read APP_CONFIGS from inline script      │
│ • ViewController.setup() → Initialize state objects     │
│ • ViewController.render() → Build virtual DOM tree      │
│ • Match virtual markers ←→ real DOM markers             │
│ • Attach event handlers from registry                   │
│ • Subscribe to state changes → Reactive updates         │
└─────────────────────────────────────────────────────────┘
```

---

## Các Global Variables

### `$__VIEW_ID__` (PHP/Blade)

**Định nghĩa**: Unique identifier cho mỗi view/page được render.

**Kiểu**: `string` (UUID hoặc hash)

**Sinh bởi**: `ViewContextService::getViewId()`

**Dùng trong**:
- Marker ID prefix: `$__VIEW_ID__ . '-' . 'marker-id'`
- Data attributes: `data-hydrate="$__VIEW_ID__-div-1"`
- State subscriptions: Để track dependencies

**Ví dụ**:
```blade
@vars(['VIEW_ID' => $__VIEW_ID__])
<!-- HTML: data-hydrate="abc123-div-1" -->

@startMarker('output', $__VIEW_ID__ . '-output-1')
  {{ $userEmail }}
@endMarker('output', $__VIEW_ID__ . '-output-1')
```

### `$__helper` (PHP/Blade)

**Định nghĩa**: Global view helper instance - singleton trên mỗi request.

**Kiểu**: `ViewHelperService` (từ `onelaravel/core`)

**Truy cập**: Tự động inject bởi Blade

**Main methods**:
```php
// Markers
$__helper->startMarker($type, $id, $attributes = []);
$__helper->endMarker($type, $id);

// Reactive
$__helper->startReactive($type, $id, $dependencyKeys = [], $options = []);
$__helper->endReactive($type, $id);

// State
$__helper->setState($key, $value);
$__helper->addViewData($key, $value);

// Events
$__helper->addEventListener($eventType, $selector, $handlerName);

// Output tracking
$__helper->addOutputComponent($expr, $dependencies);
```

**Liên kết**: `ViewStorageManager::getInstance()` - lưu dữ liệu toàn request

---

## Core Services

### 1. ViewHelperService.php

**Vị trí**: `core/src/App/View/Services/ViewHelperService.php`

**Trách nhiệm**: Interface chính giữa Blade template và hệ thống xử lý view.

**Key methods**:

```php
public function startMarker(string $name, string $id, array $attrs = []): string
{
    // Thêm marker vào registry
    $this->storage->addMarkerRegistry($name, $id, $attrs);
    
    // Return HTML comment marker:
    // <!--o:SHORTCUT:id-->
    return $this->storage->getMarkerOpenTag($name, $id);
}

public function startReactive(
    string $type,
    string $id,
    array $stateKeys = [],
    array $options = []
): string
{
    // Lưu reactive section với dependencies
    $this->storage->addReactiveRegistry($type, $id, $stateKeys, $options);
    
    // Return opening marker
    return $this->storage->getMarkerOpenTag('reactive', $id);
}

public function setState(string $key, mixed $value): void
{
    // Lưu state value để serialize vào PAGE_END
    $this->storage->addState($key, $value);
}

public function addEventListener(
    string $eventType,
    string $selector,
    string $handlerName
): void
{
    // Đăng ký event handler
    $this->storage->addEvent([
        'type' => $eventType,
        'selector' => $selector,
        'handler' => $handlerName
    ]);
}
```

### 2. ViewStorageManager.php

**Vị trí**: `core/src/App/View/Services/ViewStorageManager.php`

**Trách nhiệm**: Lưu trữ toàn bộ dữ liệu view cho request hiện tại.

**Key data structures**:

```php
// Marker shortcuts (short codes)
$markerTagShortcut = [
    "view"        => 'v',    // <!--o:v:ID-->
    "component"   => 'c',    // <!--o:c:ID-->
    "block"       => 'b',    // <!--o:b:ID-->
    "reactive"    => 'r',    // <!--o:r:ID-->
    "foreach"     => 'fe',   // <!--o:fe:ID-->
    "if"          => 'if',   // <!--o:if:ID-->
    "while"       => 'wh',   // <!--o:wh:ID-->
    "switch"      => 'sw',   // <!--o:sw:ID-->
    "include"     => 'inc',  // <!--o:inc:ID-->
    "output"      => 'o',    // <!--o:o:ID-->
    "yield"       => 'y',    // <!--o:y:ID-->
    "blockoutlet"  => 'bo',   // <!--o:bo:ID-->
];

// Registries
$markerRegistry = [
    'id' => [
        'type' => 'output',
        'attributes' => ['escapeHTML' => true],
        'dependencies' => ['userEmail']
    ]
];

$reactiveRegistry = [
    'id' => [
        'type' => 'if',
        'stateKeys' => ['count'],
        'options' => ['type' => 'if']
    ]
];

$states = [
    'count' => 0,
    'users' => [...]
];

$events = [
    ['type' => 'click', 'selector' => 'button.submit', 'handler' => 'handleSubmit'],
];
```

**Public methods**:

```php
// Markers
getMarkerOpenTag(string $name, string $id): string  // <!--o:SHORTCUT:ID-->
getMarkerCloseTag(string $name, string $id): string // <!--/o:SHORTCUT:ID-->
addMarkerRegistry(string $name, string $id, array $attrs = []): void
addReactiveRegistry(string $type, string $id, array $stateKeys, array $opts): void

// States
addState(string $key, mixed $value): void
getStates(): array

// Events
addEvent(array $event): void
getEvents(): array

// Export
toArray(): array  // Serialize cho PAGE_END JSON
```

### 3. BladeDirectiveServiceProvider.php

**Vị trị**: `core/src/App/Providers/BladeDirectiveServiceProvider.php`

**Trách nhiệm**: Đăng ký tất cả custom Blade directives.

**Quá trình**:
1. `boot()` method: Inject từng service
2. Gọi service's `registerDirectives()` method
3. Directive nhận expression, xử lý, return HTML output

**Ví dụ quy trình**:
```php
// Trong provider:
Blade::directive('useState', function ($expression) {
    // $expression = "[$count, $setCount] = useState(0)"
    return "<?php [\$count, \$setCount] = \$__app->make('UserService')->useState({$expression}); ?>";
});
```

---

## Directive Reference

### Reactive Directives

#### `@startReactive` / `@endReactive` (OLD format)

**Format**:
```blade
@startReactive('type', 'rc-' . $__VIEW_ID__ . '-id-N', ['stateKey1', 'stateKey2'], ["type" => "reactive", "escapeHTML" => true])
  <!-- Content to track -->
@endReactive('type', 'rc-' . $__VIEW_ID__ . '-id-N')
```

**Sinh bởi**: `ReactiveDirectiveService`

**Dùng bởi**: Compiler V1 hoặc hand-written blade

**Xử lý**:
```php
// Open tag
Blade::directive('startReactive', function ($expression) {
    // Parse: type, id, stateKeys, options
    return sprintf(
        '<?php echo $__helper->startReactive(%s); ?>',
        $expression
    );
});

// $__helper->startReactive('output', 'rc-ID-output-1', ['email'], ['type' => 'output'])
// Returns: <!--o:r:$__VIEW_ID__-output-1-->
// AND stores in $markerRegistry for JS side
```

**HTML output**:
```html
<!--o:r:abc123-output-1-->
  john@example.com
<!--/o:r:abc123-output-1-->
```

**JS side** (saola runtime):
```javascript
// ViewController matches markers
this.reactive('r', 'abc123-output-1', {
    stateKeys: ['email'],
    render: () => `<span>${this.state.email}</span>`
});
```

---

#### `@startMarker` / `@endMarker` (NEW format - V2)

**Format**:
```blade
@startMarker('type', 'hydrate-id', ['option' => 'value'])
  <!-- Content -->
@endMarker('type', 'hydrate-id')
```

**Sinh bởi**: Compiler V2 (saola)

**Xử lý**:
```php
Blade::directive('startMarker', function ($expression) {
    // Args: $name, $id, $attrs = []
    return sprintf(
        '<?php echo $__helper->startMarker(%s); ?>',
        $expression
    );
});

// $__helper->startMarker('output', 'div-1-output-1')
// For non-'view' markers, prepends VIEW_ID: 'abc123-div-1-output-1'
// Returns: <!--o:o:abc123-div-1-output-1-->
```

**Key difference** từ `@startReactive`:
- Hydration ID không có `rc-` prefix trong source
- `MarkerRegistryDirectiveService` tự động prepend `$__VIEW_ID__`
- Support thêm marker types: component, include, while, etc.

---

### State Directives

#### `@useState([$state, $setState] = useState(value))`

**Mục đích**: Khai báo reactive state variable.

**Xử lý**:
```php
Blade::directive('useState', function ($expression) {
    // $expression = "[$count, $setCount] = useState(0)"
    return "<?php 
        \$__viewData = explode(',', '$declaration');
        \$__helper->setState('count', 0);
        \$count = 0;
        \$setCount = function(\$v) { \$__helper->setState('count', \$v); };
    ?>";
});
```

**Kết quả**:
```php
// Biến $count, $setCount có thể dùng trong template
{{ $count }}

// State được lưu vào ViewStorageManager
$__helper->setState('count', 0);
```

**JS side - App.init() nhận state từ ssrData**:
```javascript
// APP_CONFIGS.view contain:
{ 
    ssrData: { 
        count: 0, 
        email: "john@example.com"
    }
}

// ViewController khôi phục:
this.state = APP_CONFIGS.view.ssrData;
this.subscriptions['count'] = [];  // Setup reactive tracking
```

#### `@const` / `@let` / `@vars`

**`@const`**: Variable không thay đổi trong template
```blade
@const($user = Auth::user())
// Compile: <?php $user = Auth::user(); ?>
```

**`@let`**: Variable local cho template scope
```blade
@let($total = $amount * $quantity)
{{ $total }}
```

**`@vars`**: Export variables để JS dùng (via ssrData)
```blade
@vars(['config' => config('app')])
// JS nhận: APP_CONFIGS.view.vars.config
```

**Xử lý**:
```php
Blade::directive('vars', function ($expression) {
    // Array được gửi tới $__helper->addViewData()
    return sprintf(
        '<?php $__helper->addViewData(%s); ?>',
        $expression
    );
});
```

---

### Binding Directives

#### `@bind($variable)`

**Mục đích**: Two-way binding input ↔ state.

**HTML output**:
```blade
<input @bind($count) />

<!-- Compile to: -->
<input 
    value="{{ $count }}" 
    data-bind="count"
    @input="$__handler('updateState', 'count', $event.target.value)"
/>
```

**Xử lý**:
```php
Blade::directive('bind', function ($expression) {
    // $expression = '$count'
    // Lưu binding registry để JS biết
    $__helper->addEventListener('bind', "[$data-bind={$expression}]", "updateState");
    
    // Render: value="{{ $count }}" data-bind="count" @input="..."
    return "value=\"{{ {$expression} }}\" data-bind=\"{$expression}\"";
});
```

**JS side**:
```javascript
// Event listener setup
element.addEventListener('input', (e) => {
    // Update state: this.state.count = e.target.value
    // This triggers reactivity → re-render dependent sections
});
```

---

### Event Directives

#### `@click`, `@change`, `@submit`, `@keydown`, etc.

**Mục đích**: Attach event handlers.

**Format**:
```blade
<button @click="handleClick($event, userId)">Click me</button>
```

**Xử lý** (EventDirectiveService):
```php
Blade::directive('click', function ($expression) {
    // $expression = "handleClick($event, userId)"
    // Đăng ký event handler
    $__helper->addEventListener('click', 'this', $expression);
    
    // Return HTML attribute
    return "@click=\"{$expression}\"";
});
```

**Registry entry**:
```php
$events[] = [
    'type' => 'click',
    'selector' => 'button',
    'handler' => 'handleClick',
    'args' => ['$event', 'userId']
];
```

**JS side - ViewController.js**:
```javascript
// From APP_CONFIGS:
this.events = [
    { type: 'click', selector: 'button', handler: 'handleClick' }
];

// Attach handler:
element.addEventListener('click', (event) => {
    this.handleClick(event, this.state.userId);
});
```

---

### Layout Directives

#### `@pageStart` / `@pageEnd`

**`@pageStart`**: Wrapper khởi đầu page - declare VIEW_ID và khởi tạo markers.

**Xử lý**:
```php
Blade::directive('pageStart', function ($expression) {
    // Generate unique VIEW_ID
    $viewId = uniqid();
    
    // Initialize ViewStorageManager
    $storage = ViewStorageManager::getInstance();
    $storage->setViewId($viewId);
    
    // Return opening wrapper
    return sprintf(
        '<?php echo $__helper->startMarker("view", "%s"); ?>',
        $viewId
    );
});

// HTML output:
// <!--o:v:abc123-->
```

**`@pageEnd`**: Đóng page + serialize ssrData.

**Xử lý**:
```php
Blade::directive('pageEnd', function () {
    $storage = ViewStorageManager::getInstance();
    
    // Collect all data
    $ssrData = [
        'views' => $storage->getMarkerRegistry(),
        'reactives' => $storage->getReactiveRegistry(),
        'states' => $storage->getStates(),
        'events' => $storage->getEvents(),
        'view_id' => $storage->getViewId()
    ];
    
    // Output as inline script
    return sprintf(
        '</div>
        <script>
            window.APP_CONFIGS = %s;
        </script>',
        json_encode($ssrData)
    );
});
```

---

### Component Directives

#### `@wrapper` / `@endWrapper`

**Mục đích**: Define reusable component wrapper.

**Format**:
```blade
@wrapper
  <div class="component-wrapper">
    {{ $slot }}
  </div>
@endWrapper
```

**Xử lý**:
```php
Blade::directive('wrapper', function () {
    $__helper->startMarker('component', uniqid('wrapper-'));
    return '<?php $__helper->startMarker("component", "wrapper"); ?>';
});

Blade::directive('endWrapper', function () {
    return '<?php $__helper->endMarker("component", "wrapper"); ?>';
});
```

#### `@children`

**Mục đích**: Render component children.

**Xử lý**:
```php
Blade::directive('children', function () {
    // $__ONE_CHILDREN_CONTENT__ được set bởi component system
    return '<?php echo $__ONE_CHILDREN_CONTENT__; ?>';
});
```

---

#### `@block` / `@useBlock` / `@endblock`

**`@block(name)`**: Khai báo block slot.
```blade
@block('content')
  Default content
@endblock
```

**`@useBlock(name, layout)`**: Reference block từ layout.
```blade
@useBlock('content')  <!-- Render component đăng ký tại 'content' -->
```

**Xử lý**:
```php
Blade::directive('block', function ($expression) {
    // Register block
    $__helper->addEventListener('block', $expression, 'render');
    return sprintf(
        '<?php echo $__helper->startMarker("block", %s); ?>',
        $expression
    );
});

Blade::directive('endblock', function () {
    return '<?php echo $__helper->endMarker("block"); ?>';
});
```

---

## Hydration ID System

### ID Generation Pattern (V2 Compiler)

**Format**: `PARENT_PATH-ELEMENT_TYPE-INDEX`

```
div-1-h4-1-span-1-output-1

^   ^ ^  ^ ^    ^ ^      ^
|   | |  | |    | |      └─ Marker sequence (output-1, if-2, etc)
|   | |  | |    | └─ Element type (span)
|   | |  | |    └─ Element index
|   | |  | └─ Element type (h4)
|   | |  └─ Element index
|   | └─ Element type (div)
|   └─ Parent index
└─ Container (div)
```

### Runtime VIEW_ID Prepending

**Blade phase**:
```blade
@startMarker('output', 'div-1-output-1')
<!-- Compiler generates: -->
<!--o:o:$__VIEW_ID__-div-1-output-1-->
```

**PHP rendering**:
```php
// $__VIEW_ID__ = 'abc123'
echo "<!--o:o:abc123-div-1-output-1-->";
```

**JS hydration**:
```javascript
// Extract ID from HTML comment
const id = 'abc123-div-1-output-1';

// Match with virtual tree
this.output(id, '<!-- rendered content -->');
```

---

## Marker Registry

### Data Structure (stored by ViewStorageManager)

```php
$markerRegistry = [
    'div-1-output-1' => [
        'type' => 'output',
        'attributes' => [
            'escapeHTML' => true,
            'nullable' => false
        ],
        'dependencies' => ['userEmail']
    ],
    'div-1-if-2' => [
        'type' => 'if',
        'attributes' => [
            'condition' => 'count($items) > 0'
        ],
        'dependencies' => ['items']
    ],
    'component-1' => [
        'type' => 'component',
        'attributes' => [
            'name' => 'components.user-card'
        ],
        'dependencies' => []
    ]
];
```

### Export Format (PAGE_END JSON)

```javascript
{
    "APP_CONFIGS": {
        "view": {
            "view_id": "abc123",
            "ssrData": {
                "userEmail": "john@example.com",
                "items": [...]
            },
            "markerRegistry": {
                "div-1-output-1": { type: "output", ... },
                "div-1-if-2": { type: "if", ... }
            },
            "reactiveRegistry": {
                "div-1-if-2": { 
                    "type": "if",
                    "stateKeys": ["items"],
                    "condition": "count(items) > 0"
                }
            },
            "events": [
                { type: "click", selector: "button.submit", handler: "handleSubmit" }
            ]
        }
    }
}
```

### Shortcut Codes (Comment format)

```
<!--o:v:ID-->           View wrapper
<!--o:c:ID-->           Component
<!--o:b:ID-->           Block
<!--o:r:ID-->           Reactive (foreach, if, while, switch)
<!--o:fe:ID-->          Foreach loop
<!--o:if:ID-->          If block
<!--o:wh:ID-->          While loop
<!--o:sw:ID-->          Switch statement
<!--o:inc:ID-->         Include (sub-view)
<!--o:o:ID-->           Output ({{ expr }})
<!--o:y:ID-->           Yield (block outlet)
<!--o:bo:ID-->          Block outlet marker
```

---

## ngữ cảnh các phiên bản format

### Format CŨ (V1 - @startReactive)

Được dùng bởi:
- Hand-written blade files
- Compiler V1 output
- onelaravel user project (hiện tại)

**Đặc điểm**:
- Inline `$__VIEW_ID__` concat: `'rc-' . $__VIEW_ID__ . '-output-1'`
- Chỉ hỗ trợ reactive types: output, if, foreach, while, switch
- State dependencies inline: `['email', 'count']`

**Ví dụ**:
```blade
@startReactive('output', 'rc-' . $__VIEW_ID__ . '-output-1', ['email'], ["type" => "output"])
  {{ Auth::user()->email }}
@endReactive('output', 'rc-' . $__VIEW_ID__ . '-output-1')
```

### Format MỚI (V2 - @startMarker)

Được sinh bởi:
- Compiler V2 saola (hiện tại)
- Khuyến khích cho tương lai

**Đặc điểm**:
- Bare hydration ID: `'div-1-output-1'`
- Auto prepend VIEW_ID bởi `MarkerRegistryDirectiveService`
- Support toàn bộ marker types: component, include, block, reactive, etc.
- Cleaner separation: Logic ở directive, không ở template

**Ví dụ**:
```blade
@startMarker('output', 'div-1-output-1')
  {{ Auth::user()->email }}
@endMarker('output', 'div-1-output-1')
```

---

## Migration Path

**Current state**: 
- ✅ Format CŨ hoạt động tốt (ReactiveDirectiveService registered)
- ⚠️ Format MỚI chưa ready (MarkerRegistryDirectiveService **not registered**)

**Để enable Format MỚI**:

1. **In `BladeDirectiveServiceProvider.php`**, thêm:
```php
use One\App\View\Compilers\MarkerRegistryDirectiveService;

// Register in services array or boot()
MarkerRegistryDirectiveService::class,
```

2. **Fix `WrapperDirectiveService.php`**, thêm camelCase:
```php
Blade::directive('endWrapper', function ($expression) { ... });  // camelCase
```

3. Test build với compiler V2 output

---

## Debugging Tips

### 1. Kiểm tra ssrData trong browser

```javascript
// DevTools console
console.log(window.APP_CONFIGS.view.ssrData);
```

### 2. Trace marker registry

```php
// In blade template
@php dd($__helper->getMarkerRegistry()); @endphp
```

### 3. Check compiled blade output

```bash
# View generated .blade.php
cat resources/views/{context}/{file}.blade.php
```

### 4. Verify state values

```blade
<!-- Debug state -->
@php
  foreach($__helper->getStates() as $key => $value) {
    echo "<!-- State: {$key} = " . json_encode($value) . " -->\n";
  }
@endphp
```

---

## References

- **Core package**: `onelaravel/core` (source: `/Users/doanln/Desktop/2026/Projects/core/`)
- **Compiler**: `saola` (source:`/Users/doanln/Desktop/2026/Projects/saola/`)
- **User project**: `onelaravel` (source: `/Users/doanln/Desktop/2026/Projects/onelaravel/`)
- **Blade docs**: https://laravel.com/docs/blade
