import { View, ViewController, app, Application } from '@saolabs/client';


const __VIEW_PATH__ = 'examples.13-syntax';
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



class SyntaxViewController extends ViewController {
    constructor(view) {
        super(view, __VIEW_PATH__, __VIEW_TYPE__);
        if (typeof (this).setStaticConfig === 'function') {
            (this).setStaticConfig(__VIEW_CONFIG__);
        } else {
            (this).config = __VIEW_CONFIG__;
        }
    }
}

class SyntaxView extends View {
    constructor(__data__ = {}, systemData = {}) {
        super(__VIEW_PATH__, __VIEW_TYPE__, SyntaxViewController);
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
        let {label = '', value = 0, items = ['']} = __data__;
        __STATE__.__.register('label', label);
        __STATE__.__.register('value', value);
        __STATE__.__.register('items', items);
        __UPDATE_DATA_TRAIT__.label = __next => { label = __next; updateStateByKey('label', __next); };
        __UPDATE_DATA_TRAIT__.value = __next => { value = __next; updateStateByKey('value', __next); };
        __UPDATE_DATA_TRAIT__.items = __next => { items = __next; updateStateByKey('items', __next); };
        const __VARIABLE_LIST__ = ["label", "value", "items"];


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

                // Finally lock state updates

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
                { classes: [{ type: 'static', value: "my-4" }] },
                (parentElement) => [
                this.text('\n'),
                this.text('        '),
                this.html(`e11`, "label", parentElement, {}, (parentElement) => [
                    this.output(`e11o1`, parentElement, true, ["label"], (parentElement) => label)
                ]),
                this.text('\n'),
                this.text('        '),
                this.html(`e12`, "input", parentElement, { attrs: { "type": { type: 'static', value: "text" }, "v-model": { type: 'static', value: "value" } } }),
                this.text('\n'),
                this.text('    ')
                ]),
            this.text('\n'),
            this.text('    '),
            this.html(`e2`, "div", parentElement,
                { classes: [{ type: 'static', value: "mt-4" }] },
                (parentElement) => [
                this.text('\n'),
                this.text('        '),
                this.html(`e21`, "ul", parentElement, {}, (parentElement) => [
                    this.text('\n'),
                    this.text('            '),
                    this.reactive(`e21l1`, "foreach", parentReactive, parentElement, ["items"], (parentReactive, parentElement) => {
                        return this.__foreach(items, (item, __loopKey, __loopIndex, __loop) => [
                            this.text('                '),
                            this.html(`e21l11-${__loopIndex}`, "li", parentElement,
                                { attrs: { "key": { type: 'binding', value: item, factory: () => item, stateKeys: [] } } },
                                (parentElement) => [
                                this.output(`e21l11o1-${__loopIndex}`, parentElement, true, [], (parentElement) => item)
                                ]),
                            this.text('\n'),
                            this.text('            ')
                        ])
                    }),
                    this.text('        ')
                ]),
                this.text('\n'),
                this.text('    ')
                ]),
            this.text('\n'),
            this.text('    '),
            this.html(`e3`, "div", parentElement,
                { classes: [{ type: 'binding', value: "bg-red-500", factory: () => value < 10, stateKeys: ["value"] }, { type: 'binding', value: "bg-green-500", factory: () => value >= 10, stateKeys: ["value"] }, { type: 'binding', value: "bg-blue-500", factory: () => value >= 20, stateKeys: ["value"] }], styles: { "color": { type: 'binding', value: value > 10 ? 'blue' : 'red', factory: () => value > 10 ? 'blue' : 'red', stateKeys: ["value"] }, "font-size": { type: 'binding', value: value+'px', factory: () => value+'px', stateKeys: ["value"] } } },
                (parentElement) => [
                this.text('\n'),
                this.text('        '),
                this.output(`e3o1`, parentElement, true, ["value"], (parentElement) => value),
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
export function SyntaxFactory(__data__ = {}, systemData = {}) {
    return new SyntaxView(__data__, systemData);
}
export default SyntaxFactory;