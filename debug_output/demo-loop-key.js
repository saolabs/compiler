
// View template mock
class DemoLoopKeyView {
    $__setup__(__data__, systemData) {
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
                update${
            postList: posts,
            categoryList: categories
            }(null);
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
                update${
            postList: posts,
            categoryList: categories
            }(null);
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
            this.html(`template-1`, "template", parentElement, {}, (parentElement) => [
                this.html(`template-1-div-1`, "div", parentElement,
                    { classes: [{ type: 'static', value: "flex" }, { type: 'static', value: "flex-col" }, { type: 'static', value: "gap-2" }] },
                    (parentElement) => [
                    this.__for("increment", 0, 10, (__loop) => {
                            let __forOutput = [];
                            for (let i = 0; i < 10; i++) {
                                __loop.setCurrentTimes(i);
                                __forOutput.push(
                                this.html(`template-1-div-1-for-1-${i}-div-1`, "div", parentElement,
                                    { classes: [{ type: 'static', value: "flex" }, { type: 'static', value: "items-center" }, { type: 'static', value: "gap-2" }] },
                                    (parentElement) => [
                                    this.html(`template-1-div-1-for-1-${i}-div-1-h2-1`, "h2", parentElement,
                                        { classes: [{ type: 'static', value: "text-sm" }] },
                                        (parentElement) => [
                                        this.text('Title '),
                                        this.output(`template-1-div-1-for-1-${i}-div-1-h2-1-output-1`, parentElement, true, ["i"], (parentElement) => i)
                                        ]),
                                    this.__foreach(categoryList, (categoryItem, __loopKey, __loopIndex, __loop) => [
                                            this.html(`template-1-div-1-for-1-${i}-div-1-foreach-1-${categoryItem.id}-div-1`, "div", parentElement,
                                                { classes: [{ type: 'static', value: "category-item" }] },
                                                (parentElement) => [
                                                this.html(`template-1-div-1-for-1-${i}-div-1-foreach-1-${categoryItem.id}-div-1-h3-1`, "h3", parentElement,
                                                    { classes: [{ type: 'static', value: "category-name" }] },
                                                    (parentElement) => [
                                                    this.output(`template-1-div-1-for-1-${i}-div-1-foreach-1-${categoryItem.id}-div-1-h3-1-output-1`, parentElement, true, [], (parentElement) => categoryItem.name)
                                                    ]),
                                                this.html(`template-1-div-1-for-1-${i}-div-1-foreach-1-${categoryItem.id}-div-1-div-2`, "div", parentElement,
                                                    { classes: [{ type: 'static', value: "post-list" }] },
                                                    (parentElement) => [
                                                    this.__foreach(categoryItem.posts, (postItem, __loopKey, __loopIndex, __loop) => [
                                                            this.html(`template-1-div-1-for-1-${i}-div-1-foreach-1-${categoryItem.id}-div-1-div-2-foreach-1-${postItem.id}-div-1`, "div", parentElement,
                                                                { classes: [{ type: 'static', value: "post-item" }] },
                                                                (parentElement) => [
                                                                this.html(`template-1-div-1-for-1-${i}-div-1-foreach-1-${categoryItem.id}-div-1-div-2-foreach-1-${postItem.id}-div-1-h4-1`, "h4", parentElement,
                                                                    { classes: [{ type: 'static', value: "post-title" }] },
                                                                    (parentElement) => [
                                                                    this.output(`template-1-div-1-for-1-${i}-div-1-foreach-1-${categoryItem.id}-div-1-div-2-foreach-1-${postItem.id}-div-1-h4-1-output-1`, parentElement, true, [], (parentElement) => postItem.title)
                                                                    ]),
                                                                this.html(`template-1-div-1-for-1-${i}-div-1-foreach-1-${categoryItem.id}-div-1-div-2-foreach-1-${postItem.id}-div-1-p-2`, "p", parentElement,
                                                                    { classes: [{ type: 'static', value: "post-content" }] },
                                                                    (parentElement) => [
                                                                    this.output(`template-1-div-1-for-1-${i}-div-1-foreach-1-${categoryItem.id}-div-1-div-2-foreach-1-${postItem.id}-div-1-p-2-output-1`, parentElement, true, [], (parentElement) => postItem.content)
                                                                    ])
                                                                ])
                                                    ])
                                                    ])
                                                ])
                                    ])
                                    ])
                                );
                            }
                            return __forOutput;
                        })
                    ])
            ])
            ]);
            }
        });
    }
}
