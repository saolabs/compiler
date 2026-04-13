# Saola V2 Output Format

## Overview

Saola compiler giờ đã generate file JS với class-based structure thay vì function wrapper đơn thuần. Cấu trúc mới giúp:
- Tổ chức code rõ ràng hơn
- Dễ extend và customize
- Tương thích với saola framework architecture

## Output Structure

### File Header

```javascript
import { View } from 'saola';
import { app } from 'saola';

// nều có code trước export default trong script setup thì thêm vào đây

const __VIEW_PATH__ = 'admin.pages.users';
const __VIEW_NAMESPACE__ = 'admin.pages.';
const __VIEW_TYPE__ = 'view';
```

### Class Definition

```javascript
class AdminPagesUsersView extends View {
    $__config__ = {};
    
    constructor(App, systemData) {
        super(__VIEW_PATH__, __VIEW_TYPE__);
        this.__ctrl__.setApp(App);
    }

    $__setup__($$$DATA$$$, systemData) {
        // Toàn bộ logic wrapper code ở đây
        const {App, View, ...} = systemData;
        const __VIEW_ID__ = $$$DATA$$$.__SSR_VIEW_ID__ || App.View.generateViewId();
        
        // Variables, states, setup logic...
        self.setup('admin.pages.users', {}, {
            // View configuration
            prerender: function() { ... },
            render: function() { ... }
        });
        
        return self;
    }
}
```

### Export Factory Function

```javascript
// Export factory function
export function AdminPagesUsers($$$DATA$$$ = {}, systemData = {}) {
    const App = app.make("App");
    const view = new AdminPagesUsersView(App, systemData);
    view.$__setup__($$$DATA$$$, systemData);
    return view;
}
```

## Key Constants

### `__VIEW_PATH__`
Full view path từ namespace đến file name
- Example: `'admin.pages.users'`
- Example: `'web.templates.counter'`

### `__VIEW_NAMESPACE__`
Namespace path (view path loại bỏ file name)
- Example: `'admin.pages.'` (từ admin.pages.users)
- Example: `'admin.templates.'` (từ admin.templates.counter)
- Example: `'web.pages.'` (từ web.pages.home)

### `__VIEW_TYPE__`
Loại view: `'view'`, `'layout'`, `'component'`, `'template'`, etc.

## Class Naming Convention

Class name = Function name + "View"
- `AdminPagesUsers` → `AdminPagesUsersView`
- `WebPagesHome` → `WebPagesHomeView`
- `AdminTemplatesCounter` → `AdminTemplatesCounterView`

## Implementation Details

### Location in Code
File: `compiler/python/main_compiler.py`
Lines: ~1007-1099 (generate_view_engine_wrapper function)

### Key Changes
1. Always import from `'saola'` (not `'onelaraveljs'`)
2. Calculate `__VIEW_NAMESPACE__` by splitting view_name and removing last part
3. Wrap all logic trong `$__setup__()` method
4. Export factory function tạo instance và gọi `$__setup__()`

## Example Output

```javascript
import { View } from 'saola';
import { app } from 'saola';

const __VIEW_PATH__ = 'admin.pages.users';
const __VIEW_NAMESPACE__ = 'admin.pages.';
const __VIEW_TYPE__ = 'view';

class AdminPagesUsersView extends View {
    $__config__ = {};
    
    constructor(App, systemData) {
        super(__VIEW_PATH__, __VIEW_TYPE__);
        this.__ctrl__.setApp(App);
    }

    $__setup__($$$DATA$$$, systemData) {
        const {App, View, __base__, __layout__, __page__, __component__, __template__, __context__, __partial__, __system__, __env = {}, __helper = {}} = systemData;
        const __VIEW_ID__ = $$$DATA$$$.__SSR_VIEW_ID__ || App.View.generateViewId();
        
        const __UPDATE_DATA_TRAIT__ = {};
        let {posts = [], isAdmin = false} = $$$DATA$$$;
        __UPDATE_DATA_TRAIT__.posts = value => posts = value;
        __UPDATE_DATA_TRAIT__.isAdmin = value => isAdmin = value;
        
        // State declarations...
        const set$users = __STATE__.__.register('users');
        let users = null;
        const setUsers = (state) => {
            users = state;
            set$users(state);
        };
        
        self.setup('admin.pages.users', {}, {
            superView: null,
            hasSuperView: false,
            viewType: 'view',
            hasAwaitData: true,
            hasFetchData: false,
            subscribe: true,
            data: $$$DATA$$$,
            viewId: __VIEW_ID__,
            path: __VIEW_PATH__,
            usesVars: true,
            
            commitConstructorData: function() {
                // Update states from data
                update$users([...]);
                lockUpdateRealState();
            },
            
            updateVariableData: function(data) {
                // Update all variables first
                for (const key in data) {
                    if (data.hasOwnProperty(key)) {
                        if (typeof this.config.updateVariableItemData === 'function') {
                            this.config.updateVariableItemData.call(this, key, data[key]);
                        }
                    }
                }
                // Then update states
                update$users([...]);
                lockUpdateRealState();
            },
            
            updateVariableItemData: function(key, value) {
                this.data[key] = value;
                if (typeof __UPDATE_DATA_TRAIT__[key] === "function") {
                    __UPDATE_DATA_TRAIT__[key](value);
                }
            },
            
            prerender: function() {
                return null;
            },
            
            render: function() {
                let __outputRenderedContent__ = '';
                try {
                    __outputRenderedContent__ = `
                        <div class="user-management">
                            <h2>User Management</h2>
                            ${this.__watch(...)}
                        </div>
                    `;
                } catch(e) {
                    __outputRenderedContent__ = this.__showError(e.message);
                    console.warn(e);
                }
                return __outputRenderedContent__;
            }
        });
        
        return self;
    }
}

// Export factory function
export function AdminPagesUsers($$$DATA$$$ = {}, systemData = {}) {
    const App = app.make("App");
    const view = new AdminPagesUsersView(App, systemData);
    view.$__setup__($$$DATA$$$, systemData);
    return view;
}
```

## Testing

Đã test với:
- ✅ admin.pages.users → `AdminPagesUsersView`
- ✅ admin.pages.dashboard → `AdminPagesDashboardView`
- ✅ admin.templates.counter → `AdminTemplatesCounterView`
- ✅ web.pages.home → `WebPagesHomeView`

Tất cả đều compile thành công với correct class names và namespaces.

## Future Enhancements

Phần comment "nều có code trước export default trong script setup thì thêm vào đây" sẽ được sử dụng cho:
- Custom imports
- Helper functions
- Constants
- Script setup code (từ @script directive)
