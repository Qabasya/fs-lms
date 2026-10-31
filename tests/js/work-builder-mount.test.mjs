import assert from 'node:assert/strict';
import { test } from 'node:test';

class Element {
    constructor(tag = 'div') { this.tagName = tag; this.children = []; this.dataset = {}; this.handlers = {}; this.classList = { add() {}, remove() {}, toggle() {} }; }
    appendChild(child) { this.children.push(child); return child; }
    addEventListener(type, handler) { this.handlers[type] = handler; }
    querySelectorAll() { return []; }
    querySelector() { return null; }
    checkValidity() { return Number(this.value) >= Number(this.min) && Number(this.value) <= Number(this.max); }
    reportValidity() { throw new Error('Unexpected invalid field'); }
}

test('actual WorkBuilder mount preserves stored points through readSteps and input change reaches save AJAX', async () => {
    const created = [];
    const requests = [];
    const root = new Element();
    root.dataset = { workId: '5', subject: 'inf' };
    const script = { textContent: JSON.stringify([{key:'item_10',type:'task',payload:{ref:10},_title:'Задача',points:2}]) };
    const nodes = new Map(['[data-slot-list]','[data-editor]','[data-slot-count]','[data-status]'].map(key => [key, new Element()]));
    root.querySelector = selector => selector === '.fs-sb-data' ? script : nodes.get(selector) ?? null;
    globalThis.window = {};
    globalThis.document = {
        createElement: tag => { const element = new Element(tag); created.push(element); return element; },
        querySelectorAll: selector => selector === '.fs-lms-work-builder' ? [root] : [],
        querySelector: () => null,
    };
    globalThis.fs_lms_vars = { subjects: [], ajaxurl: '/admin-ajax.php', ajax_actions: {saveWorkItems:'save_work', getTaskPreview:'preview'}, nonces: {authorWork:'nonce',authorAssessment:'preview_nonce'} };
    globalThis.jQuery = Object.assign(() => ({}), {post: (url, payload) => {
        requests.push(payload);
        return {
            done(callback) { callback({success:true, data: payload.action === 'preview' ? {title:'Задача'} : {count:1}}); return this; },
            fail() { return this; },
        };
    }});
    const { WorkBuilder } = await import('../../src/js/admin/services/work-builder.js');
    WorkBuilder.init();
    await new Promise(resolve => setImmediate(resolve));
    const input = created.find(element => element.tagName === 'input' && element.type === 'number');
    assert.ok(input, 'реальный renderExtraBody добавляет поле');
    assert.equal(input.value, '2', 'серверные баллы не теряются в readSteps');
    input.value = '3';
    input.handlers.change();
    await new Promise(resolve => setImmediate(resolve));
    const saved = requests.find(payload => payload.action === 'save_work');
    assert.ok(saved, 'api.save исполняется после завершения монтажа без TDZ');
    assert.deepEqual(saved.item_ids, [10]);
    assert.deepEqual(saved.task_points, {10:3});
});
