import { View, ViewController, app, Application } from '@saolabs/client';


const __VIEW_PATH__ = 'examples.14-demo-full';
const __VIEW_NAMESPACE__ = 'examples.';
const __VIEW_TYPE__ = 'view';
const __VIEW_CONFIG__ = {
    hasSuperView: false,
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



class DemoFullViewController extends ViewController {
    constructor(view) {
        super(view, __VIEW_PATH__, __VIEW_TYPE__);
        if (typeof (this).setStaticConfig === 'function') {
            (this).setStaticConfig(__VIEW_CONFIG__);
        } else {
            (this).config = __VIEW_CONFIG__;
        }
    }
}

class DemoFullView extends View {
    constructor(__data__ = {}, systemData = {}) {
        super(__VIEW_PATH__, __VIEW_TYPE__, DemoFullViewController);
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
        let {name = 'Hello', age = 18, items = []} = __data__;
        __STATE__.__.register('name', name);
        __STATE__.__.register('age', age);
        __STATE__.__.register('items', items);
        let {users = [{"id": 1, "name": "Lâm", "email": "lam#domain.com"}, {"id": 2, "name": "Hồng", "email": "hong#domain.com"}], title = "Hồ sơ người dùng"} = __data__;
        __STATE__.__.register('users', users);
        __STATE__.__.register('title', title);
        let status = 1;
        const set$formData = __STATE__.__.register('formData');
        let formData = {"name": name, "age": age};
        const setFormData = (state) => {
            formData = state;
            set$formData(state);
        };
        __STATE__.__.setters.setFormData = setFormData;
        __STATE__.__.setters.formData = setFormData;
        const update$formData = (value) => {
            if(__STATE__.__.canUpdateStateByKey){
                updateStateByKey('formData', value);
                formData = value;
            }
        };
        const set$count = __STATE__.__.register('count');
        let count = 0;
        const setCount = (state) => {
            count = state;
            set$count(state);
        };
        __STATE__.__.setters.setCount = setCount;
        __STATE__.__.setters.count = setCount;
        const update$count = (value) => {
            if(__STATE__.__.canUpdateStateByKey){
                updateStateByKey('count', value);
                count = value;
            }
        };
        const MAX_FOR_LOOP_COUNT = 100;
        const set$editingMode = __STATE__.__.register('editingMode');
        let editingMode = false;
        const setEditingMode = (state) => {
            editingMode = state;
            set$editingMode(state);
        };
        __STATE__.__.setters.setEditingMode = setEditingMode;
        __STATE__.__.setters.editingMode = setEditingMode;
        const update$editingMode = (value) => {
            if(__STATE__.__.canUpdateStateByKey){
                updateStateByKey('editingMode', value);
                editingMode = value;
            }
        };
        __UPDATE_DATA_TRAIT__.name = __next => { name = __next; updateStateByKey('name', __next); };
        __UPDATE_DATA_TRAIT__.age = __next => { age = __next; updateStateByKey('age', __next); };
        __UPDATE_DATA_TRAIT__.items = __next => { items = __next; updateStateByKey('items', __next); };
        __UPDATE_DATA_TRAIT__.users = __next => { users = __next; updateStateByKey('users', __next); };
        __UPDATE_DATA_TRAIT__.title = __next => { title = __next; updateStateByKey('title', __next); };
        const __VARIABLE_LIST__ = ["name", "age", "items", "users", "title"];


        this.__ctrl__.setUserDefinedConfig({
            handleFormSubmit(event) {
                event.preventDefault();
            }
        });

        this.__ctrl__.setup({
            superView: null,
            subscribe: true,
            fetch: null,
            data: __data__,
            viewId: __VIEW_ID__,
            path: __VIEW_PATH__,
            scripts: [],
            styles: [],
            resources: [],
            commitConstructorData: function() {
                // Then update states from data
                update$formData({"name": name, "age": age});
                update$count(0);
                update$editingMode(false);
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
                // Re-derive CHỈ state phụ thuộc data — state literal của instance KHÔNG reset
                if (data.hasOwnProperty('age') || data.hasOwnProperty('name')) { update$formData({"name": name, "age": age}); }
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
            return this.wrapper((parentElement) => [
            this.html(`e1`, "div", parentElement,
                { classes: [{ type: 'static', value: "click-section" }] },
                (parentElement) => [
                this.text('\n'),
                this.text('        '),
                this.html(`e11`, "button", parentElement,
                    { attrs: { "type": { type: 'static', value: "button" } }, events: { click: [(event) => setCount(count + 1)] } },
                    (parentElement) => [
                    this.text('\n'),
                    this.text('            Click me ('),
                    this.output(`e11o1`, parentElement, true, ["count"], (parentElement) => count),
                    this.text(')'),
                    this.text('\n'),
                    this.text('        ')
                    ]),
                this.text('\n'),
                this.text('    ')
                ]),
            this.text('\n'),
            this.text('    '),
            this.html(`e2`, "div", parentElement, {}, (parentElement) => [
                this.text('\n'),
                this.text('        '),
                this.reactive(`e2r1`, "if", parentReactive, parentElement, ["editingMode"], (parentReactive, parentElement) => {
                    const reactiveContents = [];
                    if (editingMode) {
                        reactiveContents.push(
                        this.text('            '),
                        this.html(`e2r1k11`, "div", parentElement,
                            { classes: [{ type: 'static', value: "editor-section" }] },
                            (parentElement) => [
                            this.text('\n'),
                            this.text('                '),
                            this.html(`e2r1k111`, "h1", parentElement, {}, (parentElement) => [
                                this.output(`e2r1k111o1`, parentElement, true, ["name"], (parentElement) => name),
                                this.text(' - '),
                                this.output(`e2r1k111o2`, parentElement, true, ["age"], (parentElement) => age)
                            ]),
                            this.text('\n'),
                            this.text('                '),
                            this.include(`e2r1k11c1`, __template__+'forms.user-form', parentElement, ["age", "name"], (parentElement) => ({"user": {"name": name, "age": age}})),
                            this.text('            ')
                            ]),
                        this.text('\n'),
                        this.text('        ')
                        );
                    }
                    else {
                        reactiveContents.push(
                        this.text('            '),
                        this.html(`e2r1k21`, "div", parentElement,
                            { classes: [{ type: 'static', value: "viewer-section" }] },
                            (parentElement) => [
                            this.text('\n'),
                            this.text('                '),
                            this.html(`e2r1k211`, "h1", parentElement, {}, (parentElement) => [
                                this.output(`e2r1k211o1`, parentElement, true, ["name"], (parentElement) => name),
                                this.text(' - '),
                                this.output(`e2r1k211o2`, parentElement, true, ["age"], (parentElement) => age)
                            ]),
                            this.text('\n'),
                            this.text('                '),
                            this.html(`e2r1k212`, "ul", parentElement, {}, (parentElement) => [
                                this.text('\n'),
                                this.text('                    '),
                                this.reactive(`e2r1k212l1`, "foreach", parentReactive, parentElement, ["items"], (parentReactive, parentElement) => {
                                    return this.__foreach(items, (item, __loopKey, __loopIndex, __loop) => [
                                        this.text('                        '),
                                        this.html(`e2r1k212l11-${__loopIndex}`, "li", parentElement, {}, (parentElement) => [
                                            this.output(`e2r1k212l11o1-${__loopIndex}`, parentElement, true, [], (parentElement) => item)
                                        ]),
                                        this.text('\n'),
                                        this.text('                    ')
                                    ])
                                }),
                                this.text('                ')
                            ]),
                            this.text('\n'),
                            this.text('            ')
                            ]),
                        this.text('\n'),
                        this.text('        ')
                        );
                    }
                    return reactiveContents;
                }),
                this.text('\n'),
                this.text('    ')
            ]),
            this.text('\n'),
            this.text('    '),
            this.html(`e3`, "div", parentElement,
                { classes: [{ type: 'static', value: "users" }] },
                (parentElement) => [
                this.text('\n'),
                this.text('        '),
                this.reactive(`e3l1`, "foreach", parentReactive, parentElement, ["users"], (parentReactive, parentElement) => {
                    return this.__foreach(users, (user, __loopKey, __loopIndex, __loop) => [
                        this.text('            '),
                        this.include(`e3l1c1-${user.id}`, __template__+'users.item', parentElement, ["editingMode"], (parentElement) => ({"user": user, "config": [editingMode]})),
                        this.text('        ')
                    ], (user) => user.id)
                }),
                this.text('    ')
                ]),
            this.text('\n'),
            this.text('    '),
            this.include(`c1`, __template__+'users.list', parentElement, ["users"], (parentElement) => ({"users": users})),
            this.text('    '),
            this.include(`c2`, __template__+'users.group', parentElement, ["users"], (parentElement) => ({
                    "users": users,
                    "title": "Nhóm người dùng",
                    __ONE_CHILDREN_CONTENT__: (parentElement) => [
                    this.reactive(`c2l1`, "foreach", parentReactive, parentElement, ["users"], (parentReactive, parentElement) => {
                        return this.__foreach(users, (user, __loopKey, __loopIndex, __loop) => [
                            this.text('            '),
                            this.include(`c2l1c1-${user.id}`, __template__+'users.item', parentElement, [], (parentElement) => ({"user": user})),
                            this.text('        ')
                        ], (user) => user.id)
                    })
                ]
                })),
            this.text('\n'),
            this.text('    \n'),
            this.text('    '),
            this.html(`e4`, "div", parentElement,
                { classes: [{ type: 'static', value: "status-section" }] },
                (parentElement) => [
                this.text('\n'),
                this.text('        '),
                this.reactive(`e4r1`, "switch", parentReactive, parentElement, [], (parentReactive, parentElement) => {
                    const reactiveContents = [];
                    switch (status) {
                        case 1:
                            reactiveContents.push(
                            this.text('                '),
                            this.html(`e4r1k11`, "span", parentElement,
                                { classes: [{ type: 'static', value: "status-badge" }, { type: 'static', value: "status-draft" }] },
                                (parentElement) => [
                                this.text('Bản nháp')
                                ]),
                            this.text('\n'),
                            this.text('                '),
                            this.text('            ')
                            );
                            break;
                        case 2:
                            reactiveContents.push(
                            this.text('                '),
                            this.html(`e4r1k21`, "span", parentElement,
                                { classes: [{ type: 'static', value: "status-badge" }, { type: 'static', value: "status-published" }] },
                                (parentElement) => [
                                this.text('Đã xuất bản')
                                ]),
                            this.text('\n'),
                            this.text('                '),
                            this.text('            ')
                            );
                            break;
                        default:
                            reactiveContents.push(
                            this.text('                '),
                            this.html(`e4r1k31`, "span", parentElement,
                                { classes: [{ type: 'static', value: "status-badge" }] },
                                (parentElement) => [
                                this.text('Không rõ trạng thái')
                                ]),
                            this.text('\n'),
                            this.text('        ')
                            );
                            break;
                    }
                    return reactiveContents;
                }),
                this.text('        '),
                this.html(`e41`, "small", parentElement, {}, (parentElement) => [
                    this.text('('),
                    this.text(String(statusLabel ?? '')),
                    this.text(')')
                ]),
                this.text('\n'),
                this.text('    ')
                ]),
            this.text('\n'),
            this.text('\n'),
            this.text('    \n'),
            this.text('    '),
            this.html(`e5`, "article", parentElement,
                { classes: [{ type: 'static', value: "article-card" }, { type: 'binding', value: "article-card--long", factory: () => App.Helper.strlen(article.content) > 10, stateKeys: [] }], attrs: { "data-status": { type: 'binding', value: status, factory: () => status, stateKeys: [] }, "title": { type: 'binding', value: article.title, factory: () => article.title, stateKeys: ["title"] } }, styles: { "border-color": { type: 'binding', value: status === 1 ? 'orange' : 'green', factory: () => status === 1 ? 'orange' : 'green', stateKeys: [] } } },
                (parentElement) => [
                this.text('\n'),
                this.text('        '),
                this.html(`e51`, "h2", parentElement, {}, (parentElement) => [
                    this.output(`e51o1`, parentElement, true, ["title"], (parentElement) => article.title)
                ]),
                this.text('\n'),
                this.text('        '),
                this.html(`e52`, "p", parentElement, {}, (parentElement) => [
                    this.text(String(article.content ?? ''))
                ]),
                this.text('\n'),
                this.text('        '),
                this.html(`e53`, "small", parentElement, {}, (parentElement) => [
                    this.text(String(article.author ?? '')),
                    this.text(' — '),
                    this.text(String(article.createdAt ?? ''))
                ]),
                this.text('\n'),
                this.text('    ')
                ]),
            this.text('\n'),
            this.text('\n'),
            this.text('    \n'),
            this.text('    '),
            this.html(`e6`, "form", parentElement,
                { events: { submit: [{"handler":"handleFormSubmit","params":[(event) => event]}] } },
                (parentElement) => [
                this.text('\n'),
                this.text('        '),
                this.html(`e61`, "input", parentElement, { attrs: { "type": { type: 'static', value: "text" }, "value": { type: 'binding', value: statusLabel, factory: () => statusLabel, stateKeys: [] } }, events: { input: [{"handler":"setStatus","params":[(event) => Number(event.target.value)]}] } }),
                this.text('\n'),
                this.text('        '),
                this.html(`e62`, "button", parentElement,
                    { attrs: { "type": { type: 'static', value: "submit" }, "disabled": { type: 'binding', value: MAX_FOR_LOOP_COUNT < 1, factory: () => MAX_FOR_LOOP_COUNT < 1, stateKeys: [] } } },
                    (parentElement) => [
                    this.text('\n'),
                    this.text('            Lưu ('),
                    this.text(String(status ?? '')),
                    this.text(')'),
                    this.text('\n'),
                    this.text('        ')
                    ]),
                this.text('\n'),
                this.text('    ')
                ]),
            this.text('\n')
            ]);
            }
        });

    }
}

// Export factory function
export function DemoFullFactory(__data__ = {}, systemData = {}) {
    return new DemoFullView(__data__, systemData);
}
export default DemoFullFactory;