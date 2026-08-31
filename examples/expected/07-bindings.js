import { View, ViewController, app, Application } from '@saolabs/client';


const __VIEW_PATH__ = 'examples.07-bindings';
const __VIEW_NAMESPACE__ = 'examples.';
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



class BindingsViewController extends ViewController {
    constructor(view) {
        super(view, __VIEW_PATH__, __VIEW_TYPE__);
        if (typeof (this).setStaticConfig === 'function') {
            (this).setStaticConfig(__VIEW_CONFIG__);
        } else {
            (this).config = __VIEW_CONFIG__;
        }
    }
}

class BindingsView extends View {
    constructor(__data__ = {}, systemData = {}) {
        super(__VIEW_PATH__, __VIEW_TYPE__, BindingsViewController);
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
        const set$active = __STATE__.__.register('active');
        let active = true;
        const setActive = (state) => {
            active = state;
            set$active(state);
        };
        __STATE__.__.setters.setActive = setActive;
        __STATE__.__.setters.active = setActive;
        const update$active = (value) => {
            if(__STATE__.__.canUpdateStateByKey){
                updateStateByKey('active', value);
                active = value;
            }
        };
        const set$width = __STATE__.__.register('width');
        let width = 120;
        const setWidth = (state) => {
            width = state;
            set$width(state);
        };
        __STATE__.__.setters.setWidth = setWidth;
        __STATE__.__.setters.width = setWidth;
        const update$width = (value) => {
            if(__STATE__.__.canUpdateStateByKey){
                updateStateByKey('width', value);
                width = value;
            }
        };
        const set$label = __STATE__.__.register('label');
        let label = 'nút';
        const setLabel = (state) => {
            label = state;
            set$label(state);
        };
        __STATE__.__.setters.setLabel = setLabel;
        __STATE__.__.setters.label = setLabel;
        const update$label = (value) => {
            if(__STATE__.__.canUpdateStateByKey){
                updateStateByKey('label', value);
                label = value;
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
                update$active(true);
                update$width(120);
                update$label('nút');
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
                { classes: [{ type: 'static', value: "box" }, { type: 'binding', value: "box-active", factory: () => active, stateKeys: ["active"] }], attrs: { "data-label": { type: 'binding', value: label, factory: () => label, stateKeys: ["label"] }, "title": { type: 'binding', value: label, factory: () => label, stateKeys: ["label"] } }, styles: { "width": { type: 'binding', value: width, factory: () => width, stateKeys: ["width"] } } },
                (parentElement) => [
                this.text('Hộp')
                ]),
            this.html(`e2`, "input", parentElement, { attrs: { "type": { type: 'static', value: "text" }, "v-model": { type: 'static', value: "label" } }, bind: { key: 'label' } }),
            this.html(`e3`, "span", parentElement,
                { attrs: { "title": { type: 'binding', value: label, factory: () => label, stateKeys: ["label"] } } },
                (parentElement) => [
                this.text('rút gọn hoạt động ở đây')
                ])
            ]);
            }
        });

    }
}

// Export factory function
export function BindingsFactory(__data__ = {}, systemData = {}) {
    return new BindingsView(__data__, systemData);
}
export default BindingsFactory;