import assert from 'node:assert/strict';
import { test } from 'node:test';
import { canHideMenu, orderedMenu, reorderMenu, menuItemHtml } from '../../src/js/profile/menu-customization.js';

const items = ['home','grades','attendance'].map(key => ({key,label:key}));
test('saved order appends new menu entries without forcing a reset and ignores obsolete entries', () => {
    assert.deepEqual(orderedMenu(items,{menuOrder:['grades','removed','home']}).map(item=>item.key), ['grades','home','attendance']);
    assert.deepEqual(orderedMenu(items,{}).map(item=>item.key), ['home','grades','attendance']);
});
test('staff hidden entries stay accessible to editor, show crossed eye, and reset restores all entries', () => {
    const layout={menuHidden:['grades']};
    assert.equal(orderedMenu(items,layout,false,true).some(item=>item.key==='grades'),false);
    const hidden=orderedMenu(items,layout,true,true).find(item=>item.key==='grades');
    assert.equal(hidden.hidden,true);
    assert.match(menuItemHtml(hidden,'',true,true),/is-menu-hidden/);
    assert.match(menuItemHtml(hidden,'',true,true),/Показать пункт/);
    assert.equal(orderedMenu(items,{menuHidden:[]},false,true).length,3);
});
test('student and parent can reorder but cannot hide even with forged stored visibility', () => {
    for (const role of ['lms_student','lms_parent']) {
        assert.equal(canHideMenu(role),false);
        assert.equal(orderedMenu(items,{menuHidden:['home']},false,canHideMenu(role)).length,3);
        assert.doesNotMatch(menuItemHtml(items[0],'',true,canHideMenu(role)),/data-menu-eye/);
    }
});
test('moving exam links changes order only inside their block', () => {
    assert.deepEqual(reorderMenu(['home','grades','exam-plan','exam-results'], ['exam-plan','exam-results'], ['exam-results','exam-plan']), ['home','grades','exam-results','exam-plan']);
});

test('editor eye restores hidden entry and touch arrows commit order without activating links', async () => {
    const { wireMenuEditor } = await import('../../src/js/profile/menu-customization.js');
    const callbacks = {};
    const saved = [];
    const eye = {dataset:{menuEye:'grades'}, addEventListener: (name, callback) => {callbacks.eye=callback;}};
    const nodes = ['home','grades'].map(key=>({dataset:{menuKey:key}}));
    const block = {querySelectorAll:()=>nodes};
    const row = {dataset:{menuKey:'grades'},closest:()=>block};
    const arrow = {dataset:{menuShift:'-1'},closest:()=>row,addEventListener:(name,callback)=>{callbacks.arrow=callback;}};
    const root = {querySelectorAll: selector => selector === '[data-menu-eye]' ? [eye] : selector === '[data-menu-shift]' ? [arrow] : []};
    wireMenuEditor(root,{layout:{menuOrder:['home','grades'],menuHidden:['grades']}, editing:true,mayHide:true,commit:patch=>saved.push(patch)});
    let stopped = 0;
    callbacks.eye({stopPropagation(){stopped++;}});
    callbacks.arrow({stopPropagation(){stopped++;}});
    assert.equal(stopped,2);
    assert.deepEqual(saved,[{menuHidden:[]},{menuOrder:['grades','home']}]);
    saved.length=0;
    wireMenuEditor(root,{layout:{menuHidden:[]},editing:true,mayHide:false,commit:patch=>saved.push(patch)});
    callbacks.eye({stopPropagation(){}});
    assert.deepEqual(saved,[]);
});
