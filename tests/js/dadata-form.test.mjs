import assert from 'node:assert/strict';
import { test } from 'node:test';
import { initDadataForm } from '../../src/js/frontend/services/dadata-suggest.js';

class Element {
    constructor() { this.children = []; this.events = {}; this.value = ''; }
    setAttribute() {}
    appendChild(child) { this.children.push(child); }
    addEventListener(name, callback) { this.events[name] = callback; }
    querySelectorAll() { return this.children; }
    dispatchEvent(event) { this.events[event.type]?.({target: this}); }
    focus() {}
}

test('DaData подключает отдельные части ФИО и email, сохраняет ручной ввод без токена', async () => {
    const inputs = ['last_name', 'first_name', 'middle_name', 'email'].map(() => {
        const input = new Element();
        input.parentElement = new Element();
        return input;
    });
    const form = {querySelectorAll(selector) {
        const names = ['last_name', 'first_name', 'middle_name', 'email'];
        return inputs.filter((input, index) => selector.includes(names[index]));
    }};
    initDadataForm(form, '');
    assert.ok(inputs.every(input => input.parentElement.children.length === 0));
    globalThis.document = {createElement: () => new Element()};
    const requests = [];
    globalThis.fetch = async (url, options) => {
        requests.push({url, ...JSON.parse(options.body)});
        return {ok: true, json: async () => ({suggestions: [{value: 'Подсказка'}]})};
    };
    initDadataForm(form, 'fixture-token');
    assert.ok(inputs.every(input => input.parentElement.children.length === 1));
    inputs.forEach(input => { input.value = 'тест'; input.dispatchEvent(new Event('input')); });
    await new Promise(resolve => setTimeout(resolve, 350));
    assert.equal(requests.length, 4);
    assert.deepEqual(requests.slice(0, 3).map(request => request.parts), [['SURNAME'], ['NAME'], ['PATRONYMIC']]);
    assert.ok(requests[3].url.endsWith('/email'));
    const list = inputs[0].parentElement.children[0];
    list.children[0].events.mousedown({preventDefault() {}});
    assert.equal(inputs[0].value, 'Подсказка');
    assert.equal(list.hidden, true);
    delete globalThis.document;
    delete globalThis.fetch;
});
