
import { View } from 'saola';
import { app } from 'saola';


const __VIEW_PATH__ = 'examples.app';
const __VIEW_NAMESPACE__ = 'examples.';
const __VIEW_TYPE__ = 'view';

class AppView extends View {
    $__config__ = {
        hasSuperView: true,
    viewType: 'view',
    sections: {
        "meta:type":{
            "type":"short",
            "preloader":false,
            "useVars":false,
            "script":{}
        },
        "meta:og:image":{
            "type":"short",
            "preloader":false,
            "useVars":false,
            "script":{}
        },
        "footer":{
            "type":"long",
            "preloader":false,
            "useVars":false,
            "script":{}
        },
        "content":{
            "type":"long",
            "preloader":false,
            "useVars":false,
            "script":{}
        }
    },
    wrapperConfig: { enable: false, tag: null, subscribe: true, attributes: {} },
    hasAwaitData: false,
    hasFetchData: false,
    usesVars: true,
    hasSections: true,
    hasSectionPreload: false,
    hasPrerender: false,
    renderLongSections: ["footer","content"],
    renderSections: [],
    prerenderSections: []
    };

    constructor(App, systemData) {
        super(__VIEW_PATH__, __VIEW_TYPE__);
        this.__ctrl__.setApp(App);
    }

    $__setup__(__data__, systemData) {
        const App = this.__ctrl__.App;
        const __STATE__ = this.__ctrl__.states;
        const {__base__, __layout__, __page__, __component__, __template__, __context__, __partial__, __system__, __env = {}, __helper = {}} = systemData;
        const __VIEW_ID__ = __data__.__SSR_VIEW_ID__ || this.__ctrl__.system.generateViewId();

        const __UPDATE_DATA_TRAIT__ = {};
        let {title: 'test', description: 'Mô tả', user: request().user()} = __data__;
        let test = 'demo';
        const set$userAvatar = __STATE__.__.register('userAvatar');
        let userAvatar = null;
        const setAvatar = (state) => {
            userAvatar = state;
            set$userAvatar(state);
        };
        __STATE__.__.setters.setAvatar = setAvatar;
        __STATE__.__.setters.userAvatar = setAvatar;
        const update$userAvatar = (value) => {
            if(__STATE__.__.canUpdateStateByKey){
                updateStateByKey('userAvatar', value);
                userAvatar = value;
            }
        };
        __UPDATE_DATA_TRAIT__.title: 'test' = value => title: 'test' = value;
        __UPDATE_DATA_TRAIT__.description: 'Mô tả' = value => description: 'Mô tả' = value;
        __UPDATE_DATA_TRAIT__.user: request().user() = value => user: request().user() = value;
        __UPDATE_DATA_TRAIT__.test = value => test = value;
        const __VARIABLE_LIST__ = ["title: 'test'", "description: 'Mô tả'", "user: request().user()", "test"];


        this.__ctrl__.setUserDefined({
            signout() {
                    // Xử lý đăng xuất
                    console.log('User signed out');
                },
                changeAvatar(event) {
                    event.preventDefault();
                    // Xử lý thay đổi avatar
                    const newAvatar = prompt('Enter new avatar URL:');
                    if (newAvatar) {
                        setAvatar(newAvatar);
                        console.log('Avatar updated:', newAvatar);
                    }
                }
        });

        this.__ctrl__.setup({
            superView: __template__ + 'main',
            subscribe: true,
            fetch: null,
            data: __data__,
            viewId: __VIEW_ID__,
            path: __VIEW_PATH__,
            scripts: [],
            styles: [{"type":"code","content":".site-logo {\n        width: 120px;\n        height: auto;\n    }\n    .site-logo.has-login {\n        width: 100px;\n    }\n    .account-image.user-avatar {\n        width: 40px;\n        height: 40px;\n        border-radius: 50%;\n    }\n    .account-menu {\n        display: none;\n        position: absolute;\n        background: white;\n        list-style: none;\n        padding: 10px;\n        border: 1px solid #ccc;\n    }\n    .btn-show-menu:hover + .account-menu {\n        display: block;\n    }"}],
            resources: [],
            commitConstructorData: function() {
                // Then update states from data
                update$userAvatar(App.Helper.getUserAvatar(user));
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
                update$userAvatar(App.Helper.getUserAvatar(user));
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
            this.superViewPath = __template__ + 'main';
            return this.extendView(this.superViewPath, {});
            }
        });
    }
}

/**
 * Factory function for creating and initializing the view
 */
export const App = (App, systemData) => {
    return (data) => {
        const view = new AppView(App, systemData);
        view.$__setup__(data, systemData);
        return view;
    };
};
