/**
 * Auto-generated View Registry for examples context
 * Generated at: 2026-04-14T02:54:52.389Z
 * 
 * This file imports all compiled views and exports them as a registry object.
 * Usage in app.ts:
 * 
 * import registry from './one/examples/registry.js';
 * App.View.registerViews(registry);
 */

import type { View } from 'saola';

import SaoAwait from './await.js';
import SaoDemo3 from './demo3.js';
import SaoAwaitDynamic from './await-dynamic.js';
import SaoDemo2Layout from './demo2-layout.js';
import SaoCounter from './counter.js';
import SaoTestYieldLayout from './test-yield-layout.js';
import SaoTestYieldPage from './test-yield-page.js';
import SaoTestDirectives from './test-directives.js';
import SaoHome from './home.js';
import SaoFetchBeatiful from './fetch-beatiful.js';
import SaoDemoSystaxError from './demo-systax-error.js';
import SaoDemoAst from './demo-ast.js';
import SaoFetch from './fetch.js';
import SaoApp from './app.js';
import SaoTestUseState from './test-useState.js';
import SaoDemo2ExtendsInclude from './demo2-extends-include.js';
import SaoTodoList from './todo-list.js';
import SaoTestFetchDynamic from './test-fetch-dynamic.js';
import SaoDemo2 from './demo2.js';
import SaoTestNestedReactive from './test-nested-reactive.js';
import SaoDemo2Extends from './demo2-extends.js';
import SaoInput from './input.js';

export const ViewRegistry: Record<string, (data?: any, systemData?: any) => View> = {
    'sao.await': SaoAwait,
    'sao.demo3': SaoDemo3,
    'sao.await-dynamic': SaoAwaitDynamic,
    'sao.demo2-layout': SaoDemo2Layout,
    'sao.counter': SaoCounter,
    'sao.test-yield-layout': SaoTestYieldLayout,
    'sao.test-yield-page': SaoTestYieldPage,
    'sao.test-directives': SaoTestDirectives,
    'sao.home': SaoHome,
    'sao.fetch-beatiful': SaoFetchBeatiful,
    'sao.demo-systax-error': SaoDemoSystaxError,
    'sao.demo-ast': SaoDemoAst,
    'sao.fetch': SaoFetch,
    'sao.app': SaoApp,
    'sao.test-useState': SaoTestUseState,
    'sao.demo2-extends-include': SaoDemo2ExtendsInclude,
    'sao.todo-list': SaoTodoList,
    'sao.test-fetch-dynamic': SaoTestFetchDynamic,
    'sao.demo2': SaoDemo2,
    'sao.test-nested-reactive': SaoTestNestedReactive,
    'sao.demo2-extends': SaoDemo2Extends,
    'sao.input': SaoInput
};

export default ViewRegistry;
