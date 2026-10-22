import { View, ViewController, app, Application } from '@saolabs/client';


const __VIEW_PATH__ = 'fixture.user-directives';
const __VIEW_NAMESPACE__ = 'fixture.';
const __VIEW_TYPE__ = 'view';
const __VIEW_CONFIG__ = {
    hasSuperView: false,
    viewType: 'view',
    sections: {},
    wrapperConfig: { enable: false, tag: null, subscribe: true, attributes: {} },
    hasAwaitData: false,
    hasFetchData: false,
    usesVars: false,
    hasSections: false,
    hasSectionPreload: false,
    hasPrerender: false,
    renderLongSections: [],
    renderSections: [],
    prerenderSections: []
};


class VViewController extends ViewController {
    constructor(view) {
        super(view, __VIEW_PATH__, __VIEW_TYPE__);
        if (typeof (this).setStaticConfig === 'function') {
            (this).setStaticConfig(__VIEW_CONFIG__);
        } else {
            (this).config = __VIEW_CONFIG__;
        }
    }
}

class VView extends View {
    constructor(__data__ = {}, systemData = {}) {
        super(__VIEW_PATH__, __VIEW_TYPE__, VViewController);
        const App = app("App");
        const $view = this;
        const $app = App;
        const $controller = this.__ctrl__;
        const __STATE__ = this.__ctrl__.states;
        const {__base__, __layout__, __page__, __component__, __template__, __module__, __context__, __partial__, __system__, __env = {}, __helper = {}} = systemData;
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
        const set$n = __STATE__.__.register('n');
        let n = 2;
        const setN = (state) => {
            n = state;
            set$n(state);
        };
        __STATE__.__.setters.setN = setN;
        __STATE__.__.setters.n = setN;
        const update$n = (value) => {
            if(__STATE__.__.canUpdateStateByKey){
                updateStateByKey('n', value);
                n = value;
            }
        };
        const set$on = __STATE__.__.register('on');
        let on = true;
        const setOn = (state) => {
            on = state;
            set$on(state);
        };
        __STATE__.__.setters.setOn = setOn;
        __STATE__.__.setters.on = setOn;
        const update$on = (value) => {
            if(__STATE__.__.canUpdateStateByKey){
                updateStateByKey('on', value);
                on = value;
            }
        };
        const set$items = __STATE__.__.register('items');
        let items = ["a", "b"];
        const setItems = (state) => {
            items = state;
            set$items(state);
        };
        __STATE__.__.setters.setItems = setItems;
        __STATE__.__.setters.items = setItems;
        const update$items = (value) => {
            if(__STATE__.__.canUpdateStateByKey){
                updateStateByKey('items', value);
                items = value;
            }
        };
        const __VARIABLE_LIST__ = [];



        this.__ctrl__.setUserDefinedConfig({

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
                update$n(2);
                update$on(true);
                update$items(items);
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

                // Finally lock state updates
                lockUpdateRealState();
            },
            updateVariableItemData: function(key, value) {
                (this.data ??= {})[key] = value;
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
            this.html(`e1`, "section", parentElement, {}, (parentElement) => [
                this.text('\n'),
                this.text('    '),
                this.html(`e11`, "p", parentElement, {}, (parentElement) => [
                    this.output(`e11o1`, parentElement, true, ["n"], (parentElement) => (n) * 2)
                ]),
                this.text('\n'),
                this.text('    '),
                this.reactive(`e1r1`, "if", parentReactive, parentElement, ["on"], (parentReactive, parentElement) => {
                    const reactiveContents = [];
                    if (on) {
                        reactiveContents.push(
                        this.html(`e1r1k11`, "em", parentElement, {}, (parentElement) => [
                            this.output(`e1r1k11o1`, parentElement, true, ["n"], (parentElement) => n)
                        ]),
                        this.text('\n')
                        );
                    }
                    return reactiveContents;
                }),
                this.text('    '),
                this.reactive(`e1r2`, "if", parentReactive, parentElement, ["on"], (parentReactive, parentElement) => {
                    const reactiveContents = [];
                    if (on) {
                        reactiveContents.push(
                        this.html(`e1r2k11`, "div", parentElement,
                            { classes: [{ type: 'static', value: "box" }], attrs: { "aria-busy": { type: 'static', value: "true" }, "data-tip": { type: 'binding', value: n, factory: () => n, stateKeys: ["n"] } } },
                            (parentElement) => [
                            this.text('\n'),
                            this.text('        '),
                            this.html(`e1r2k111`, "span", parentElement, {}, (parentElement) => [
                                this.output(`e1r2k111o1`, parentElement, true, ["n"], (parentElement) => (n) * 2)
                            ]),
                            this.text('\n'),
                            this.text('    ')
                            ]),
                        this.text('\n')
                        );
                    }
                    return reactiveContents;
                }),
                this.text('    '),
                this.html(`e12`, "ul", parentElement, {}, (parentElement) => [
                    this.text('\n'),
                    this.text('        '),
                    this.reactive(`e12l1`, "foreach", parentReactive, parentElement, ["items"], (parentReactive, parentElement) => {
                        return this.__foreach(items, (item, __loopKey, __loopIndex, __loop, __loopIdentity) => [
                            this.html(`e12l11-${__loopIdentity}`, "li", parentElement,
                                { attrs: { "data-tip": { type: 'binding', value: item, factory: () => item, stateKeys: [] } } },
                                (parentElement) => [
                                this.output(`e12l11o1-${__loopIdentity}`, parentElement, true, [], (parentElement) => item)
                                ]),
                            this.text('\n')
                        ], undefined, true, "section-1-ul-2-foreach-1")
                    }),
                    this.text('    ')
                ]),
                this.text('\n'),
                this.text('    '),
                this.html(`e13`, "p", parentElement,
                    { attrs: { "style": { type: 'static', value: "color: #fff" }, "title": { type: 'static', value: "xem #when" } } },
                    (parentElement) => [
                    this.text('#when ở văn bản giữ nguyên')
                    ]),
                this.text('\n')
            ]),
            this.text('\n')
            ]);
            }
        });

    }
}

// Export factory function
export function VF(__data__ = {}, systemData = {}) {
    return new VView(__data__, systemData);
}
export default VF;