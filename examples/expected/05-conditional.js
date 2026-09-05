import { View, ViewController, app, Application } from '@saolabs/client';


const __VIEW_PATH__ = 'examples.05-conditional';
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



class ConditionalViewController extends ViewController {
    constructor(view) {
        super(view, __VIEW_PATH__, __VIEW_TYPE__);
        if (typeof (this).setStaticConfig === 'function') {
            (this).setStaticConfig(__VIEW_CONFIG__);
        } else {
            (this).config = __VIEW_CONFIG__;
        }
    }
}

class ConditionalView extends View {
    constructor(__data__ = {}, systemData = {}) {
        super(__VIEW_PATH__, __VIEW_TYPE__, ConditionalViewController);
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
        const set$status = __STATE__.__.register('status');
        let status = 'idle';
        const setStatus = (state) => {
            status = state;
            set$status(state);
        };
        __STATE__.__.setters.setStatus = setStatus;
        __STATE__.__.setters.status = setStatus;
        const update$status = (value) => {
            if(__STATE__.__.canUpdateStateByKey){
                updateStateByKey('status', value);
                status = value;
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
                update$status('idle');
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
            this.html(`e1`, "div", parentElement, {}, (parentElement) => [
                this.text('\n'),
                this.text('    '),
                this.reactive(`e1r1`, "if", parentReactive, parentElement, ["status"], (parentReactive, parentElement) => {
                    const reactiveContents = [];
                    if (status === 'ready') {
                        reactiveContents.push(
                        this.text('        '),
                        this.html(`e1r1k11`, "p", parentElement,
                            { classes: [{ type: 'static', value: "ok" }] },
                            (parentElement) => [
                            this.text('Sẵn sàng')
                            ]),
                        this.text('\n'),
                        this.text('    ')
                        );
                    }
                    else if (status === 'idle') {
                        reactiveContents.push(
                        this.text('        '),
                        this.html(`e1r1k21`, "p", parentElement,
                            { classes: [{ type: 'static', value: "idle" }] },
                            (parentElement) => [
                            this.text('Đang chờ')
                            ]),
                        this.text('\n'),
                        this.text('    ')
                        );
                    }
                    else {
                        reactiveContents.push(
                        this.text('        '),
                        this.html(`e1r1k31`, "p", parentElement,
                            { classes: [{ type: 'static', value: "err" }] },
                            (parentElement) => [
                            this.text('Lỗi')
                            ]),
                        this.text('\n'),
                        this.text('    ')
                        );
                    }
                    return reactiveContents;
                }),
                this.text('    ')
            ]),
            this.text('\n')
            ]);
            }
        });

    }
}

// Export factory function
export function ConditionalFactory(__data__ = {}, systemData = {}) {
    return new ConditionalView(__data__, systemData);
}
export default ConditionalFactory;