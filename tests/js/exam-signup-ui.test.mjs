import assert from 'node:assert/strict';
import test from 'node:test';

import { initExamSignup } from '../../src/js/frontend/services/exam-signup.js';

// Проверяем обработчик настоящей формы без сети: DOM-порты представлены только используемыми элементами.
function signupForm(t, response) {
    const previous = { window: globalThis.window, document: globalThis.document, fetch: globalThis.fetch };
    t.after(() => Object.assign(globalThis, previous));
    const formError = { hidden: true, textContent: '' };
    const status = { hidden: true, textContent: '', classList: { toggle() {} } };
    const submit = { disabled: false };
    const listeners = new Map();
    const groups = [];
    const errors = [];
    function field(name, value, options = {}) {
        const classes = new Set();
        const error = { dataset: { error: options.error || name }, hidden: true, textContent: '', hasAttribute: key => key === 'data-error' };
        const group = {
            dataset: { field: options.error || name },
            classList: { add: key => classes.add(key), remove: key => classes.delete(key), contains: key => classes.has(key) },
            querySelector: selector => selector === '.fs-field-error' ? error : input,
        };
        const input = {
            value, type: options.type || 'text', required: options.required || false, checked: options.checked || false,
            maxLength: options.maxLength ?? 100, dataset: { validate: options.type === 'tel' ? 'phone' : 'default' },
            addEventListener() {}, closest: () => group, focus() {},
            get validity() {
                const valueMissing = this.required && (this.type === 'checkbox' ? !this.checked : !this.value);
                return { valid: !valueMissing, valueMissing };
            },
        };
        groups.push(group); errors.push(error);
        return input;
    }
    const elements = {
        last_name: field('last_name', 'Иванов', { required: true }),
        first_name: field('first_name', 'Пётр', { required: true }),
        middle_name: field('middle_name', ''),
        phone: field('phone', '+79001112233', { required: true, type: 'tel', maxLength: -1 }),
        messenger: field('messenger', ''),
        session_id: field('session_id', '7', { type: 'hidden' }),
        consent: field('consent', 'pd_processing', { required: true, type: 'checkbox', checked: true, error: 'consent_pd_processing' }),
        form_token: { value: 'token' },
    };
    const inputs = Object.values(elements).filter(input => input.closest);
    const form = {
        elements, setAttribute() {},
        addEventListener: (name, callback) => listeners.set(name, callback),
        querySelectorAll(selector) {
            if (selector === '[data-field]') return groups;
            if (selector === 'input, select, textarea') return inputs;
            if (selector === 'input[name="consents[]"]:checked') return elements.consent.checked ? [elements.consent] : [];
            return [];
        },
        querySelector(selector) {
            if (selector === '#fs_exam_session_id') return elements.session_id;
            if (selector === '#fs-exam-submit') return submit;
            if (selector === '.fs-exam-signup__hp input') return { name: 'fs_company', value: '' };
            return null;
        },
    };
    globalThis.window = { fs_lms_exam_signup_vars: { ajax_url: '/ajax', nonce: 'nonce', actions: { submit: 'submit' } } };
    globalThis.document = {
        getElementById: id => ({ 'fs-exam-signup-form': form, 'fs-exam-form-error': formError, 'fs-exam-status': status })[id] || null,
        querySelectorAll: () => [],
        querySelector: () => null,
    };
    const requests = [];
    globalThis.fetch = async (url, options) => {
        requests.push({ url, options });
        return { json: async () => response };
    };
    initExamSignup();
    return { errors, formError, submit, elements, requests, send: () => listeners.get('submit')({ preventDefault() {} }) };
}

test('серверная ошибка согласия видна у соответствующего поля', async t => {
    const ui = signupForm(t, { success: false, data: { field: 'consent_pd_processing', message: 'Нужно согласие' } });
    await ui.send();
    assert.equal(ui.requests.length, 1);
    assert.equal(ui.errors.find(error => error.dataset.error === 'consent_pd_processing').hidden, false);
    assert.equal(ui.errors.find(error => error.dataset.error === 'consent_pd_processing').textContent, 'Нужно согласие');
    assert.equal(ui.formError.hidden, true);
    assert.equal(ui.submit.disabled, false);
    assert.equal(ui.elements.last_name.value, 'Иванов');
});

for (const field of ['unknown', 'field-with-"-quote', undefined]) {
    test(`неизвестное поле ${field}: ошибка видна в общем блоке, повтор сохраняет request_key`, async t => {
        const ui = signupForm(t, { success: false, data: { field, message: 'Не удалось записаться' } });
        await ui.send();
        await ui.send();
        assert.equal(ui.formError.hidden, false);
        assert.equal(ui.formError.textContent, 'Не удалось записаться');
        assert.equal(ui.submit.disabled, false);
        assert.equal(ui.elements.phone.value, '+79001112233');
        assert.equal(ui.requests[0].options.body.get('request_key'), ui.requests[1].options.body.get('request_key'));
    });
}

for (const [name, value] of [['last_name', ' '], ['first_name', 'а'.repeat(101)], ['middle_name', 'а'.repeat(101)], ['phone', '+7 900'], ['messenger', 'x'.repeat(101)], ['session_id', '']]) {
    test(`общая валидация блокирует неверное поле ${name}`, async t => {
        const ui = signupForm(t, { success: true });
        ui.elements[name].value = value;
        await ui.send();
        assert.equal(ui.requests.length, 0);
        assert.equal(ui.errors.find(error => error.dataset.error === name).hidden, false);
    });
}

test('обязательное согласие проверяется общим валидатором', async t => {
    const ui = signupForm(t, { success: true });
    ui.elements.consent.checked = false;
    await ui.send();
    assert.equal(ui.requests.length, 0);
    assert.equal(ui.errors.find(error => error.dataset.error === 'consent_pd_processing').hidden, false);
});
