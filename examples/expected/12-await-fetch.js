import { View, ViewController, app, Application } from '@saolabs/client';


const __VIEW_PATH__ = 'examples.12-await-fetch';
const __VIEW_NAMESPACE__ = 'examples.';
const __VIEW_TYPE__ = 'view';
const __VIEW_CONFIG__ = {
    hasSuperView: false,
    viewType: 'view',
    sections: {},
    wrapperConfig: { enable: false, tag: null, subscribe: true, attributes: {} },
    hasAwaitData: true,
    hasFetchData: true,
    usesVars: true,
    hasSections: false,
    hasSectionPreload: false,
    hasPrerender: true,
    renderLongSections: [],
    renderSections: [],
    prerenderSections: []
};



class AwaitFetchViewController extends ViewController {
    constructor(view) {
        super(view, __VIEW_PATH__, __VIEW_TYPE__);
        if (typeof (this).setStaticConfig === 'function') {
            (this).setStaticConfig(__VIEW_CONFIG__);
        } else {
            (this).config = __VIEW_CONFIG__;
        }
    }
}

class AwaitFetchView extends View {
    constructor(__data__ = {}, systemData = {}) {
        super(__VIEW_PATH__, __VIEW_TYPE__, AwaitFetchViewController);
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
        let {users = []} = __data__;
        __STATE__.__.register('users', users);
        __UPDATE_DATA_TRAIT__.users = __next => { users = __next; updateStateByKey('users', __next); };
        const __VARIABLE_LIST__ = ["users"];


        this.__ctrl__.setUserDefinedConfig({

        });

        this.__ctrl__.setup({
            superView: null,
            subscribe: true,
            fetch: {"url": `/api/users`, "method": "GET"},
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
                let parentElement = this.parentElement;
                let parentReactive = null;
                return this.wrapper((parentElement) => [
                    this.html('pr-div-1', 'div', parentElement, { classes: [{ type: 'static', value: 'data-preloader' }], attributes: [{ name: 'ref', value: __VIEW_ID__ }, { name: 'data-view-name', value: __VIEW_PATH__ }] }, (parentElement) => [
                        this.text(this.__text ? this.__text('loading') : 'Loading...')
                    ])
                ]);
                },
            render: function () {
            let parentElement = this.parentElement;
            let parentReactive = null;
            return this.wrapper((parentElement) => [
            this.html(`e1`, "div", parentElement, {}, (parentElement) => [
                this.text('Có '),
                this.output(`e1o1`, parentElement, true, ["users"], (parentElement) => App.Helper.count(users)),
                this.text(' người dùng')
            ])
            ]);
            }
        });

    }
}

// Export factory function
export function AwaitFetchFactory(__data__ = {}, systemData = {}) {
    return new AwaitFetchView(__data__, systemData);
}
export default AwaitFetchFactory;