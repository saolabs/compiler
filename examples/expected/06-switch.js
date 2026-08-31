import { View, ViewController, app, Application } from '@saolabs/client';


const __VIEW_PATH__ = 'examples.06-switch';
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



class SwitchViewController extends ViewController {
    constructor(view) {
        super(view, __VIEW_PATH__, __VIEW_TYPE__);
        if (typeof (this).setStaticConfig === 'function') {
            (this).setStaticConfig(__VIEW_CONFIG__);
        } else {
            (this).config = __VIEW_CONFIG__;
        }
    }
}

class SwitchView extends View {
    constructor(__data__ = {}, systemData = {}) {
        super(__VIEW_PATH__, __VIEW_TYPE__, SwitchViewController);
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
        const set$runtime = __STATE__.__.register('runtime');
        let runtime = 'blade';
        const setRuntime = (state) => {
            runtime = state;
            set$runtime(state);
        };
        __STATE__.__.setters.setRuntime = setRuntime;
        __STATE__.__.setters.runtime = setRuntime;
        const update$runtime = (value) => {
            if(__STATE__.__.canUpdateStateByKey){
                updateStateByKey('runtime', value);
                runtime = value;
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
                update$runtime('blade');
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
            this.reactive(`r1`, "switch", parentReactive, parentElement, ["runtime"], (parentReactive, parentElement) => {
                const reactiveContents = [];
                switch (runtime) {
                    case 'blade':
                        reactiveContents.push(
                        this.html(`r1k11`, "span", parentElement, {}, (parentElement) => [
                            this.text('Server')
                        ])
                        );
                        break;
                    case 'js':
                        reactiveContents.push(
                        this.html(`r1k21`, "span", parentElement, {}, (parentElement) => [
                            this.text('Client')
                        ])
                        );
                        break;
                    default:
                        reactiveContents.push(
                        this.html(`r1k31`, "span", parentElement, {}, (parentElement) => [
                            this.text('Không rõ')
                        ])
                        );
                        break;
                }
                return reactiveContents;
            })
            ]);
            }
        });

    }
}

// Export factory function
export function SwitchFactory(__data__ = {}, systemData = {}) {
    return new SwitchView(__data__, systemData);
}
export default SwitchFactory;