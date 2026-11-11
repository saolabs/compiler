import { View, ViewController, app, Application } from '@saolabs/client';


const __VIEW_PATH__ = 'examples.use-auth';
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


class UseAuthViewController extends ViewController {
    constructor(view) {
        super(view, __VIEW_PATH__, __VIEW_TYPE__);
        if (typeof (this).setStaticConfig === 'function') {
            (this).setStaticConfig(__VIEW_CONFIG__);
        } else {
            (this).config = __VIEW_CONFIG__;
        }
    }
}

class UseAuthView extends View {
    constructor(__data__ = {}, systemData = {}) {
        super(__VIEW_PATH__, __VIEW_TYPE__, UseAuthViewController);
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
        const set$__auth__ = __STATE__.__.register('__auth__');
        let __auth__ = App.Helper.auth().user();
        const set__auth__ = (state) => {
            __auth__ = state;
            set$__auth__(state);
        };
        __STATE__.__.setters.set__auth__ = set__auth__;
        __STATE__.__.setters.__auth__ = set__auth__;
        const update$__auth__ = (value) => {
            if(__STATE__.__.canUpdateStateByKey){
                updateStateByKey('__auth__', value);
                __auth__ = value;
            }
        };
        const __VARIABLE_LIST__ = [];


        $controller.scope.defer(App.Auth.subscribe(() => set__auth__(App.Auth.user())));



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
                update$__auth__(App.Helper.auth().user());
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
            this.reactive(`r1`, "if", parentReactive, parentElement, ["__auth__"], (parentReactive, parentElement) => {
                const reactiveContents = [];
                if (__auth__) {
                    reactiveContents.push(
                    this.text('        '),
                    this.html(`r1k11`, "span", parentElement, {}, (parentElement) => [
                        this.output(`r1k11o1`, parentElement, true, ["__auth__"], (parentElement) => __auth__.name)
                    ]),
                    this.text('\n'),
                    this.text('    ')
                    );
                }
                else {
                    reactiveContents.push(
                    this.text('        '),
                    this.html(`r1k21`, "span", parentElement, {}, (parentElement) => [
                        this.text('Khách')
                    ]),
                    this.text('\n'),
                    this.text('    ')
                    );
                }
                return reactiveContents;
            }),
            this.text('    '),
            this.html(`e1`, "button", parentElement,
                { events: { click: [(event) => set__auth__(null)] } },
                (parentElement) => [
                this.text('Ẩn thông tin trong view')
                ]),
            this.text('\n')
            ]);
            }
        });

    }
}

// Export factory function
export function UseAuth(__data__ = {}, systemData = {}) {
    return new UseAuthView(__data__, systemData);
}
export default UseAuth;