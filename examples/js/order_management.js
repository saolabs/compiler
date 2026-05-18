import { View, ViewController, app, Application } from 'saola';


const __VIEW_PATH__ = 'examples.order_management';
const __VIEW_NAMESPACE__ = 'examples.';
const __VIEW_TYPE__ = 'view';
const __VIEW_CONFIG__ = {
    hasSuperView: true,
    viewType: 'view',
    sections: {},
    wrapperConfig: { enable: false, tag: null, subscribe: true, attributes: {} },
    hasAwaitData: false,
    hasFetchData: false,
    usesVars: true,
    hasSections: false,
    hasSectionPreload: false,
    hasPrerender: false,
    renderLongSections: [],
    renderSections: [],
    prerenderSections: []
};



class OrderManagementViewController extends ViewController {
    constructor(view) {
        super(view, __VIEW_PATH__, __VIEW_TYPE__);
        if (typeof (this).setStaticConfig === 'function') {
            (this).setStaticConfig(__VIEW_CONFIG__);
        } else {
            (this).config = __VIEW_CONFIG__;
        }
    }
}

class OrderManagementView extends View {
    constructor(__data__ = {}, systemData = {}) {
        super(__VIEW_PATH__, __VIEW_TYPE__, OrderManagementViewController);
        const App = app("App");
        const __STATE__ = this.__ctrl__.states;
        const {__base__, __layout__, __page__, __component__, __template__, __context__, __partial__, __system__, __env = {}, __helper = {}} = systemData;
        const __VIEW_ID__ = __data__.__SSR_VIEW_ID__ || App.View.generateViewId();

        const useState = (value) => {
            return __STATE__.__useState(value);
        };
        const updateRealState = (state) => {
            __STATE__.__.updateRealState(state);
        };

        const lockUpdateRealState = () => {
            __STATE__.__.lockUpdateRealState();
        };
        const updateStateByKey = (key, state) => {
            __STATE__.__.updateStateByKey(key, state);
        };


        const __UPDATE_DATA_TRAIT__ = {};
        let {pageTitle = 'Quản lý đơn hàng', currentUser = App.Helper.request().user(), filters = {"status": "all", "search": ""}} = __data__;
        const set$orders = __STATE__.__.register('orders');
        let orders = null;
        const setOrders = (state) => {
            orders = state;
            set$orders(state);
        };
        __STATE__.__.setters.setOrders = setOrders;
        __STATE__.__.setters.orders = setOrders;
        const update$orders = (value) => {
            if(__STATE__.__.canUpdateStateByKey){
                updateStateByKey('orders', value);
                orders = value;
            }
        };
        const set$isLoading = __STATE__.__.register('isLoading');
        let isLoading = null;
        const setIsLoading = (state) => {
            isLoading = state;
            set$isLoading(state);
        };
        __STATE__.__.setters.setIsLoading = setIsLoading;
        __STATE__.__.setters.isLoading = setIsLoading;
        const update$isLoading = (value) => {
            if(__STATE__.__.canUpdateStateByKey){
                updateStateByKey('isLoading', value);
                isLoading = value;
            }
        };
        const set$stats = __STATE__.__.register('stats');
        let stats = null;
        const setStats = (state) => {
            stats = state;
            set$stats(state);
        };
        __STATE__.__.setters.setStats = setStats;
        __STATE__.__.setters.stats = setStats;
        const update$stats = (value) => {
            if(__STATE__.__.canUpdateStateByKey){
                updateStateByKey('stats', value);
                stats = value;
            }
        };
        const set$sidebarOpen = __STATE__.__.register('sidebarOpen');
        let sidebarOpen = null;
        const setSidebarOpen = (state) => {
            sidebarOpen = state;
            set$sidebarOpen(state);
        };
        __STATE__.__.setters.setSidebarOpen = setSidebarOpen;
        __STATE__.__.setters.sidebarOpen = setSidebarOpen;
        const update$sidebarOpen = (value) => {
            if(__STATE__.__.canUpdateStateByKey){
                updateStateByKey('sidebarOpen', value);
                sidebarOpen = value;
            }
        };
        const set$debugMode = __STATE__.__.register('debugMode');
        let debugMode = null;
        const setDebugMode = (state) => {
            debugMode = state;
            set$debugMode(state);
        };
        __STATE__.__.setters.setDebugMode = setDebugMode;
        __STATE__.__.setters.debugMode = setDebugMode;
        const update$debugMode = (value) => {
            if(__STATE__.__.canUpdateStateByKey){
                updateStateByKey('debugMode', value);
                debugMode = value;
            }
        };
        const set$totalPages = __STATE__.__.register('totalPages');
        let totalPages = null;
        const setTotalPages = (state) => {
            totalPages = state;
            set$totalPages(state);
        };
        __STATE__.__.setters.setTotalPages = setTotalPages;
        __STATE__.__.setters.totalPages = setTotalPages;
        const update$totalPages = (value) => {
            if(__STATE__.__.canUpdateStateByKey){
                updateStateByKey('totalPages', value);
                totalPages = value;
            }
        };
        const set$currentPage = __STATE__.__.register('currentPage');
        let currentPage = null;
        const setCurrentPage = (state) => {
            currentPage = state;
            set$currentPage(state);
        };
        __STATE__.__.setters.setCurrentPage = setCurrentPage;
        __STATE__.__.setters.currentPage = setCurrentPage;
        const update$currentPage = (value) => {
            if(__STATE__.__.canUpdateStateByKey){
                updateStateByKey('currentPage', value);
                currentPage = value;
            }
        };
        let i = 3;
        let i = i - 1;
        let i = 3;
        let i = i - 1;
        __UPDATE_DATA_TRAIT__.pageTitle = value => pageTitle = value;
        __UPDATE_DATA_TRAIT__.currentUser = value => currentUser = value;
        __UPDATE_DATA_TRAIT__.filters = value => filters = value;
        __UPDATE_DATA_TRAIT__.i = value => i = value;
        __UPDATE_DATA_TRAIT__.i = value => i = value;
        __UPDATE_DATA_TRAIT__.i = value => i = value;
        __UPDATE_DATA_TRAIT__.i = value => i = value;
        const __VARIABLE_LIST__ = ["pageTitle", "currentUser", "filters", "i", "i", "i", "i"];


        this.__ctrl__.setUserDefinedConfig({
            async onMounted() {
                    this.fetchOrders();
                },
                async fetchOrders() {
                    this.isLoading = true;
                    try {
                        // Giả lập API call
                        const data = await api.get('/orders', this.filters);
                        this.orders = data.items;
                        this.stats = data.stats;
                    } catch (e) {
                        console.error(e);
                    } finally {
                        this.isLoading = false;
                    }
                },
                viewDetail(id) {
                    router.push(`/orders/${id}`);
                },
                setStatusFilter(val) {
                    this.filters.status = val;
                    this.fetchOrders();
                },
                searchOrders(val) {
                    this.filters.search = val;
                    this.fetchOrders();
                }
        });

        this.__ctrl__.setup({
            superView: 'layouts.admin',
            subscribe: true,
            fetch: null,
            data: __data__,
            viewId: __VIEW_ID__,
            path: __VIEW_PATH__,
            scripts: [],
            styles: [{"type":"code","content":".admin-wrapper {\n        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);\n        padding: 2rem;\n        background: #f8fafc;\n        min-height: 100vh;\n    }\n    .status-dot {\n        display: inline-block;\n        width: 8px;\n        height: 8px;\n        border-radius: 50%;\n        margin-right: 6px;\n    }\n    .status-pending { background: #f59e0b; }\n    .status-completed { background: #10b981; }\n    .status-cancelled { background: #ef4444; }\n    \n    .spinner {\n        border: 3px solid #f3f3f3;\n        border-top: 3px solid #3b82f6;\n        border-radius: 50%;\n        width: 24px;\n        height: 24px;\n        animation: spin 1s linear infinite;\n        margin: 0 auto 1rem;\n    }\n    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }\n\n    .page-link {\n        padding: 0.5rem 1rem;\n        border: 1px solid #e2e8f0;\n        border-radius: 0.375rem;\n        background: white;\n        cursor: pointer;\n    }\n    .page-link.active {\n        background: #3b82f6;\n        color: white;\n        border-color: #3b82f6;\n    }\n    .btn-icon {\n        padding: 4px;\n        border-radius: 4px;\n        border: none;\n        background: transparent;\n        cursor: pointer;\n    }\n    .btn-icon:hover {\n        background: #f1f5f9;\n    }"}],
            resources: [],
            commitConstructorData: function() {
                // Then update states from data
                update$orders([]);
                update$isLoading(true);
                update$stats({"total": 0, "revenue": 0});
                update$sidebarOpen(true);
                update$debugMode(true);
                update$totalPages(10);
                update$currentPage(1);
                // Finally lock state updates
                lockUpdateRealState();
            },
            updateVariableData: function(data) {
                // Update all variables first
                for (const key in data) {
                    if (data.hasOwnProperty(key)) {
                        // Call updateVariableItemData directly from config
                        if (typeof this.config.updateVariableItemData === 'function') {
                            this.config.updateVariableItemData.call(this, key, data[key]);
                        }
                    }
                }
                // Then update states from data
                update$orders([]);
                update$isLoading(true);
                update$stats({"total": 0, "revenue": 0});
                update$sidebarOpen(true);
                update$debugMode(true);
                update$totalPages(10);
                update$currentPage(1);
                // Finally lock state updates
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
            render: function () {
            let parentElement = this.parentElement;
            let parentReactive = null;
            this.block('block-content', 'content', (parentElement) => [
            this.html(`e085b222`, "div", parentElement,
                { classes: [{ type: 'static', value: "admin-wrapper" }, { type: 'binding', value: "sidebar-collapsed", factory: () => !sidebarOpen, stateKeys: ["sidebarOpen"] }] },
                (parentElement) => [
                this.html(`c417c587`, "header", parentElement,
                    { classes: [{ type: 'static', value: "flex" }, { type: 'static', value: "justify-between" }, { type: 'static', value: "items-center" }, { type: 'static', value: "mb-8" }] },
                    (parentElement) => [
                    this.html(`241d3fd8`, "h1", parentElement, {}, (parentElement) => [
                        this.output(`8121b856`, parentElement, true, [], (parentElement) => pageTitle)
                    ]),
                    this.html(`5cade1a4`, "div", parentElement,
                        { classes: [{ type: 'static', value: "user-profile" }, { type: 'static', value: "flex" }, { type: 'static', value: "items-center" }, { type: 'static', value: "gap-3" }] },
                        (parentElement) => [
                        this.reactive(`b4ef86fc`, "if", parentReactive, parentElement, [], (parentReactive, parentElement) => {
                            const reactiveContents = [];
                            if (currentUser && currentUser.is_admin) {
                                reactiveContents.push(
                                this.html(`4111cf25`, "span", parentElement,
                                    { classes: [{ type: 'static', value: "badge" }, { type: 'static', value: "badge-admin" }] },
                                    (parentElement) => [
                                    this.text('Admin Mode')
                                    ]),
                                this.text('} @else {'),
                                this.html(`c2dbe4b4`, "span", parentElement,
                                    { classes: [{ type: 'static', value: "badge" }, { type: 'static', value: "badge-staff" }] },
                                    (parentElement) => [
                                    this.text('Staff')
                                    ]),
                                this.text('}'),
                                this.reactive(`6395ee19`, "if", parentReactive, parentElement, [], (parentReactive, parentElement) => {
                                    const reactiveContents = [];
                                    if (currentUser) {
                                        reactiveContents.push(
                                        this.html(`acae5bea`, "img", parentElement, { classes: [{ type: 'static', value: "w-10" }, { type: 'static', value: "h-10" }, { type: 'static', value: "rounded-full" }], attrs: { "alt": { type: 'static', value: "avatar" }, "src": { type: 'binding', value: `${currentUser.avatar}`, factory: () => `${currentUser.avatar}`, stateKeys: [] } } }),
                                        this.text('}')
                                        );
                                    }
                                    return reactiveContents;
                                })
                                );
                            }
                            return reactiveContents;
                        })
                        ])
                    ]),
                this.html(`2e937ea2`, "div", parentElement,
                    { classes: [{ type: 'static', value: "grid" }, { type: 'static', value: "grid-cols-4" }, { type: 'static', value: "gap-4" }, { type: 'static', value: "mb-6" }] },
                    (parentElement) => [
                    this.text('@each (key, value in stats) {'),
                    this.html(`f4ba9c28`, "div", parentElement,
                        { classes: [{ type: 'static', value: "stat-card" }, { type: 'static', value: "p-4" }, { type: 'static', value: "bg-white" }, { type: 'static', value: "shadow" }, { type: 'static', value: "rounded-lg" }] },
                        (parentElement) => [
                        this.html(`14578384`, "label", parentElement,
                            { classes: [{ type: 'static', value: "text-gray-500" }, { type: 'static', value: "uppercase" }, { type: 'static', value: "text-xs" }, { type: 'static', value: "font-bold" }] },
                            (parentElement) => [
                            this.output(`07bcf96b`, parentElement, true, [], (parentElement) => key)
                            ]),
                        this.html(`fb6bbef0`, "div", parentElement,
                            { classes: [{ type: 'static', value: "text-2xl" }, { type: 'static', value: "font-semibold" }] },
                            (parentElement) => [
                            this.reactive(`1a0825fc`, "switch", parentReactive, parentElement, [], (parentReactive, parentElement) => {
                                const reactiveContents = [];
                                switch (key) {
                                    case 'revenue':
                                        reactiveContents.push(
                                        this.html(`27fe675b`, "span", parentElement, {}, (parentElement) => [
                                            this.text('$'),
                                            this.output(`69dd3353`, parentElement, true, [], (parentElement) => App.Helper.formatMoney(value))
                                        ]),
                                        this.text('@default:'),
                                        this.html(`354b41ea`, "span", parentElement, {}, (parentElement) => [
                                            this.output(`119c6da9`, parentElement, true, [], (parentElement) => value)
                                        ]),
                                        this.text('}')
                                        );
                                        break;
                                }
                                return reactiveContents;
                            })
                            ])
                        ]),
                    this.text('}')
                    ]),
                this.html(`fb892c29`, "section", parentElement,
                    { classes: [{ type: 'static', value: "filters-bar" }, { type: 'static', value: "mb-6" }, { type: 'static', value: "p-4" }, { type: 'static', value: "bg-gray-50" }, { type: 'static', value: "rounded" }, { type: 'static', value: "flex" }, { type: 'static', value: "gap-4" }] },
                    (parentElement) => [
                    this.html(`d2c3fe70`, "input", parentElement, { attrs: { "type": { type: 'static', value: "text" }, "placeholder": { type: 'static', value: "Tìm mã đơn hàng..." } }, events: { input: [{"handler":"searchOrders","params":[event.target.value]}] } }),
                    this.html(`00f2a266`, "select", parentElement,
                        { events: { change: [{"handler":"setStatusFilter","params":[event.target.value]}] } },
                        (parentElement) => [
                        this.text('@each (status in [\'all\', \'pending\', \'shipping\', \'completed\', \'cancelled\']) {'),
                        this.html(`f2ce61c0`, "option", parentElement,
                            { attrs: { "selected": { type: 'static', value: true }, "filters": { type: 'static', value: true }, "status": { type: 'static', value: true }, "value": { type: 'binding', value: `${status}`, factory: () => `${status}`, stateKeys: [] } } },
                            (parentElement) => [
                            this.output(`0f69fa05`, parentElement, true, [], (parentElement) => App.Helper.text('status.' + status))
                            ]),
                        this.text('}')
                        ])
                    ]),
                this.html(`0be9bda4`, "div", parentElement,
                    { classes: [{ type: 'static', value: "table-responsive" }, { type: 'static', value: "bg-white" }, { type: 'static', value: "rounded-xl" }, { type: 'static', value: "shadow-sm" }, { type: 'static', value: "overflow-hidden" }] },
                    (parentElement) => [
                    this.reactive(`7d177d0b`, "if", parentReactive, parentElement, ["isLoading"], (parentReactive, parentElement) => {
                        const reactiveContents = [];
                        if (isLoading) {
                            reactiveContents.push(
                            this.html(`f6be0d28`, "div", parentElement,
                                { classes: [{ type: 'static', value: "p-20" }, { type: 'static', value: "text-center" }] },
                                (parentElement) => [
                                this.html(`af7434a2`, "div", parentElement, { classes: [{ type: 'static', value: "spinner" }] }),
                                this.html(`be5bab06`, "p", parentElement, {}, (parentElement) => [
                                    this.text('Đang tải dữ liệu đơn hàng...')
                                ])
                                ]),
                            this.text('} @elseif($orders && count($orders) > 0) {'),
                            this.html(`1947d87a`, "table", parentElement,
                                { classes: [{ type: 'static', value: "w-full" }, { type: 'static', value: "text-left" }] },
                                (parentElement) => [
                                this.html(`14b61282`, "thead", parentElement,
                                    { classes: [{ type: 'static', value: "bg-gray-100" }, { type: 'static', value: "border-b" }] },
                                    (parentElement) => [
                                    this.html(`311b2b99`, "tr", parentElement, {}, (parentElement) => [
                                        this.html(`6ea6413f`, "th", parentElement, {}, (parentElement) => [
                                            this.text('ID')
                                        ]),
                                        this.html(`7d748134`, "th", parentElement, {}, (parentElement) => [
                                            this.text('Khách hàng')
                                        ]),
                                        this.html(`82f1866c`, "th", parentElement, {}, (parentElement) => [
                                            this.text('Sản phẩm')
                                        ]),
                                        this.html(`17debd00`, "th", parentElement, {}, (parentElement) => [
                                            this.text('Trạng thái')
                                        ]),
                                        this.html(`bd4e6746`, "th", parentElement, {}, (parentElement) => [
                                            this.text('Tổng tiền')
                                        ]),
                                        this.html(`b48a274e`, "th", parentElement, {}, (parentElement) => [
                                            this.text('Thao tác')
                                        ])
                                    ])
                                    ]),
                                this.html(`c2245727`, "tbody", parentElement, {}, (parentElement) => [
                                    this.text('@each (order in orders) {'),
                                    this.html(`50e283f2`, "tr", parentElement,
                                        { classes: [{ type: 'static', value: "hover:bg-gray-50" }, { type: 'static', value: "transition-colors" }, { type: 'static', value: "border-b" }] },
                                        (parentElement) => [
                                        this.html(`227c3816`, "td", parentElement,
                                            { classes: [{ type: 'static', value: "font-mono" }, { type: 'static', value: "text-blue-600" }] },
                                            (parentElement) => [
                                            this.text('#'),
                                            this.output(`c669d743`, parentElement, true, [], (parentElement) => order.id)
                                            ]),
                                        this.html(`9da54501`, "td", parentElement, {}, (parentElement) => [
                                            this.html(`aac1830f`, "div", parentElement,
                                                { classes: [{ type: 'static', value: "font-bold" }] },
                                                (parentElement) => [
                                                this.output(`006f33ff`, parentElement, true, [], (parentElement) => order.customer.name)
                                                ]),
                                            this.html(`83832293`, "div", parentElement,
                                                { classes: [{ type: 'static', value: "text-xs" }, { type: 'static', value: "text-gray-400" }] },
                                                (parentElement) => [
                                                this.output(`02516c80`, parentElement, true, [], (parentElement) => order.customer.email)
                                                ])
                                        ]),
                                        this.html(`2e96d88a`, "td", parentElement, {}, (parentElement) => [
                                            this.html(`c2091ad0`, "ul", parentElement,
                                                { classes: [{ type: 'static', value: "item-list" }] },
                                                (parentElement) => [
                                                this.text('@each (item in order.items) {'),
                                                this.html(`b288174c`, "li", parentElement,
                                                    { classes: [{ type: 'static', value: "text-sm" }] },
                                                    (parentElement) => [
                                                    this.output(`2b0b3f5a`, parentElement, true, [], (parentElement) => item.qty),
                                                    this.text('x '),
                                                    this.output(`49ea343d`, parentElement, true, [], (parentElement) => item.name)
                                                    ]),
                                                this.text('}')
                                                ])
                                        ]),
                                        this.html(`01b63702`, "td", parentElement, {}, (parentElement) => [
                                            this.html(`729c69cb`, "span", parentElement, { classes: [{ type: 'static', value: "status-dot" }, { type: 'static', value: "status-' + $order->status" }] }),
                                            this.output(`3cbe6d00`, parentElement, true, [], (parentElement) => order.status_label)
                                        ]),
                                        this.html(`9a406095`, "td", parentElement,
                                            { classes: [{ type: 'static', value: "font-bold" }] },
                                            (parentElement) => [
                                            this.text('$'),
                                            this.output(`e4e38ac3`, parentElement, true, [], (parentElement) => order.total_amount)
                                            ]),
                                        this.html(`19e253d3`, "td", parentElement,
                                            { classes: [{ type: 'static', value: "actions" }] },
                                            (parentElement) => [
                                            this.html(`6a9a31ae`, "button", parentElement,
                                                { classes: [{ type: 'static', value: "btn-icon" }], events: { click: [{"handler":"viewDetail","params":[order.id]}] } },
                                                (parentElement) => [
                                                this.text('👁️')
                                                ]),
                                            this.reactive(`e76bb09d`, "if", parentReactive, parentElement, [], (parentReactive, parentElement) => {
                                                const reactiveContents = [];
                                                if (order.status == 'pending' && currentUser.can('edit_orders')) {
                                                    reactiveContents.push(
                                                    this.html(`99261a55`, "button", parentElement,
                                                        { classes: [{ type: 'static', value: "btn-icon" }, { type: 'static', value: "text-green-500" }], events: { click: [{"handler":"approveOrder","params":[order.id]}] } },
                                                        (parentElement) => [
                                                        this.text('✅')
                                                        ]),
                                                    this.text('}'),
                                                    this.html(`ce53726e`, "button", parentElement,
                                                        { classes: [{ type: 'static', value: "btn-icon" }, { type: 'static', value: "text-red-500" }], events: { click: [{"handler":"deleteOrder","params":[order.id]}] } },
                                                        (parentElement) => [
                                                        this.text('🗑️')
                                                        ])
                                                    );
                                                }
                                                return reactiveContents;
                                            })
                                            ])
                                        ]),
                                    this.text('}')
                                ])
                                ]),
                            this.html(`dc18f357`, "footer", parentElement,
                                { classes: [{ type: 'static', value: "pagination" }, { type: 'static', value: "p-4" }, { type: 'static', value: "flex" }, { type: 'static', value: "justify-center" }, { type: 'static', value: "gap-2" }] },
                                (parentElement) => [
                                this.reactive(`e0c9362e`, "for", parentReactive, parentElement, ["totalPages"], (parentReactive, parentElement) => {
                                    return this.__for("increment", 1, totalPages, (__loop) => {
                                        let __forOutput = [];
                                        for (let i = 1; i <= totalPages; i++) {
                                            __loop.setCurrentTimes(i);
                                            __forOutput.push(
                                            this.html(`4ebaee7c-${i}`, "button", parentElement,
                                                { classes: [{ type: 'static', value: "page-link" }, { type: 'binding', value: "active", factory: () => i == currentPage, stateKeys: ["currentPage"] }], events: { click: [{"handler":"goToPage","params":[() => i]}] } },
                                                (parentElement) => [
                                                this.output(`90d930f7-${i}`, parentElement, true, ["i"], (parentElement) => i)
                                                ]),
                                            this.text('}')
                                            );
                                        }
                                        return __forOutput;
                                    })
                                })
                                ]),
                            this.text('} @else {'),
                            this.html(`2a93ae03`, "div", parentElement,
                                { classes: [{ type: 'static', value: "empty-state" }, { type: 'static', value: "p-20" }, { type: 'static', value: "text-center" }] },
                                (parentElement) => [
                                this.html(`6ed8b0c1`, "img", parentElement, { classes: [{ type: 'static', value: "mx-auto" }, { type: 'static', value: "mb-4" }], attrs: { "src": { type: 'static', value: "/static/empty-orders.svg" } } }),
                                this.html(`ee8a8daa`, "h3", parentElement, {}, (parentElement) => [
                                    this.text('Không tìm thấy đơn hàng nào')
                                ]),
                                this.html(`52b2337c`, "p", parentElement, {}, (parentElement) => [
                                    this.text('Thử thay đổi bộ lọc hoặc từ khóa tìm kiếm của bạn.')
                                ]),
                                this.html(`093d532b`, "button", parentElement,
                                    { classes: [{ type: 'static', value: "btn-primary" }, { type: 'static', value: "mt-4" }], events: { click: [{"handler":"resetFilters","params":[]}] } },
                                    (parentElement) => [
                                    this.text('Xóa bộ lọc')
                                    ])
                                ]),
                            this.text('}')
                            );
                        }
                        return reactiveContents;
                    })
                    ]),
                this.reactive(`97d74020`, "if", parentReactive, parentElement, ["debugMode"], (parentReactive, parentElement) => {
                    const reactiveContents = [];
                    if (debugMode) {
                        reactiveContents.push(
                        this.html(`44e075bd`, "div", parentElement,
                            { classes: [{ type: 'static', value: "debug-panel" }, { type: 'static', value: "fixed" }, { type: 'static', value: "bottom-0" }, { type: 'static', value: "right-0" }, { type: 'static', value: "p-4" }, { type: 'static', value: "bg-black" }, { type: 'static', value: "text-green-400" }, { type: 'static', value: "font-mono" }, { type: 'static', value: "text-xs" }, { type: 'static', value: "opacity-75" }] },
                            (parentElement) => [
                            this.__while((loopCtx) => {
                                    loopCtx.setCount(0);
                                let __whileOutput = [];
                                while (i > 0) {
                                    loopCtx.setCurrentTimes(i);
                                    __whileOutput.push(
                                        this.html(`2b0c4e57-${i}`, "div", parentElement, {}, (parentElement) => [
                                            this.text('[DEBUG] System health check: OK ('),
                                            this.output(`c2e92979-${i}`, parentElement, true, ["i"], (parentElement) => i),
                                            this.text(')')
                                        ]),
                                        this.text('}')
                                    );
                                }
                                return __whileOutput;
                            }, 0)
                            ]),
                        this.text('}')
                        );
                    }
                    return reactiveContents;
                })
                ])
            ]);
            this.superViewPath = __layout__ + 'layouts.admin';
            return this.extendView(this.superViewPath, {});
            }
        });

    }
}

// Export factory function
export function OrderManagement(__data__ = {}, systemData = {}) {
    return new OrderManagementView(__data__, systemData);
}
export default OrderManagement;
