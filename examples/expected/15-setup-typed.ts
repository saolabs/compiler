import { View, ViewController, app, Application } from '@saolabs/client';
import type { ViewConfigThis } from '@saolabs/client';


const __VIEW_PATH__ = 'examples.15-setup-typed';
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

/**
 * Props của view — sinh tự động từ @props/@vars, không sửa tay.
 * Optional hết vì khai báo nào cũng có default.
 */
export interface SetupTypedProps {
    initial?: number;
    title?: string;
    /** viewId server gán khi hydrate */
    __SSR_VIEW_ID__?: string;
    [key: string]: any;
}


class SetupTypedViewController extends ViewController {
    constructor(view: View) {
        super(view, __VIEW_PATH__, __VIEW_TYPE__);
        if (typeof (this as any).setStaticConfig === 'function') {
            (this as any).setStaticConfig(__VIEW_CONFIG__);
        } else {
            (this as any).config = __VIEW_CONFIG__;
        }
    }
}

class SetupTypedView extends View {
    constructor(__data__: any = {}, systemData: any = {}) {
        super(__VIEW_PATH__, __VIEW_TYPE__, SetupTypedViewController);
        const App: Application = app("App") as Application;
        const $view: SetupTypedView = this;
        const $app: Application = App;
        const $controller: SetupTypedViewController = this.__ctrl__;
        const __STATE__ = this.__ctrl__.states;
        const {__base__, __layout__, __page__, __component__, __template__, __module__, __context__, __partial__, __system__, __env = {}, __helper = {}} = systemData;
        const __VIEW_ID__ = __data__.__SSR_VIEW_ID__ || App.View.generateViewId();

        const useState = (value: any) => {
            return __STATE__.__useState(value);
        };
        const updateRealState = (state: any) => {
            __STATE__.__.updateRealState(state);
        };

        const lockUpdateRealState = () => {
            __STATE__.__.lockUpdateRealState();
        };
        const updateStateByKey = (key: string, state: any) => {
            __STATE__.__.updateStateByKey(key, state);
        };


        const __UPDATE_DATA_TRAIT__: any = {};
        let {initial = 0, title = 'Bộ đếm'}: { initial?: number; title?: string } = __data__;
        __STATE__.__.register('initial', initial);
        __STATE__.__.register('title', title);
        let cardPath: string = __base__+'components.card';
        const set$count = __STATE__.__.register('count');
        let count: number = initial;
        const setCount = (state: number) => {
            count = state;
            set$count(state);
        };
        __STATE__.__.setters.setCount = setCount;
        __STATE__.__.setters.count = setCount;
        const update$count = (value: number) => {
            if(__STATE__.__.canUpdateStateByKey){
                updateStateByKey('count', value);
                count = value;
            }
        };
        const set$label = __STATE__.__.register('label');
        let label: string = '';
        const setLabel = (state: string) => {
            label = state;
            set$label(state);
        };
        __STATE__.__.setters.setLabel = setLabel;
        __STATE__.__.setters.label = setLabel;
        const update$label = (value: string) => {
            if(__STATE__.__.canUpdateStateByKey){
                updateStateByKey('label', value);
                label = value;
            }
        };
        const step: number = 1;
        const get$doubled = __STATE__.__.computed('doubled', (): number => count * 2, ["count"]);
        __UPDATE_DATA_TRAIT__.initial = (__next: any) => { initial = __next; updateStateByKey('initial', __next); };
        __UPDATE_DATA_TRAIT__.title = (__next: any) => { title = __next; updateStateByKey('title', __next); };
        const __VARIABLE_LIST__: any = ["initial", "title"];


        let clicks = 0;

            function increment() {
                clicks++;
                setCount(count + step);
                setLabel(clicks + ' lần bấm');
            }

            function reset() {
                clicks = 0;
                setCount(initial);
                setLabel('');
            }



        this.__ctrl__.setUserDefinedConfig({
            increment,
            reset
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
            commitConstructorData: function(this: ViewConfigThis) {
                // Then update states from data
                update$count(initial);
                update$label('');
                // Finally lock state updates
                lockUpdateRealState();
            },
            updateVariableData: function(this: ViewConfigThis, data: any) {
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
                if (data.hasOwnProperty('initial')) { update$count(initial); }
                // Finally lock state updates
                lockUpdateRealState();
            },
            updateVariableItemData: function(this: ViewConfigThis, key: string, value: any) {
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
            return this.wrapper((parentElement: any) => [
            this.html(`e1`, "section", parentElement, {}, (parentElement: any) => [
                this.text('\n'),
                this.text('        '),
                this.html(`e11`, "h1", parentElement, {}, (parentElement: any) => [
                    this.output(`e11o1`, parentElement, true, ["title"], (parentElement: any) => title)
                ]),
                this.text('\n'),
                this.text('        '),
                this.html(`e12`, "img", parentElement, { attrs: { "alt": { type: 'static', value: "" }, "src": { type: 'binding', value: App.Helper.asset('static/examples/assets/images/logo.svg'), factory: () => App.Helper.asset('static/examples/assets/images/logo.svg'), stateKeys: [] } } }),
                this.html(`e13`, "img", parentElement, { attrs: { "alt": { type: 'static', value: "" }, "src": { type: 'binding', value: App.Helper.asset('static/examples/assets/images/icon.svg'), factory: () => App.Helper.asset('static/examples/assets/images/icon.svg'), stateKeys: [] } } }),
                this.text('\n'),
                this.text('\n'),
                this.text('        '),
                this.html(`e14`, "button", parentElement,
                    { events: { click: [{"handler":"increment","params":[]}] } },
                    (parentElement: any) => [
                    this.text('Tăng')
                    ]),
                this.text('\n'),
                this.text('        '),
                this.html(`e15`, "button", parentElement,
                    { events: { click: [{"handler":"reset","params":[]}] } },
                    (parentElement: any) => [
                    this.text('Đặt lại')
                    ]),
                this.text('\n'),
                this.text('\n'),
                this.text('        '),
                this.html(`e16`, "p", parentElement, {}, (parentElement: any) => [
                    this.text('count = '),
                    this.output(`e16o1`, parentElement, true, ["count"], (parentElement: any) => count),
                    this.text(' · doubled = '),
                    this.output(`e16o2`, parentElement, true, ["doubled"], (parentElement: any) => get$doubled())
                ]),
                this.text('\n'),
                this.text('        '),
                this.reactive(`e1r1`, "if", parentReactive, parentElement, ["label"], (parentReactive: any, parentElement: any) => {
                    const reactiveContents = [];
                    if (label !== '') {
                        reactiveContents.push(
                        this.text('            '),
                        this.html(`e1r1k11`, "p", parentElement, {}, (parentElement: any) => [
                            this.output(`e1r1k11o1`, parentElement, true, ["label"], (parentElement: any) => label)
                        ]),
                        this.text('\n'),
                        this.text('        ')
                        );
                    }
                    return reactiveContents;
                }),
                this.text('\n'),
                this.text('        '),
                this.include(`e1c1`, cardPath, parentElement, [], (parentElement: any) => ({})),
                this.text('    ')
            ]),
            this.text('\n')
            ]);
            }
        });

    }
}

// Export factory function
export function SetupTypedFactory(__data__ = {}, systemData = {}): SetupTypedView {
    return new SetupTypedView(__data__, systemData);
}
export default SetupTypedFactory;