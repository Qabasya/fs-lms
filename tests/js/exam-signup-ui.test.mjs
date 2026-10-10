import assert from 'node:assert/strict';
import test from 'node:test';

import { initExamSignup } from '../../src/js/frontend/services/exam-signup.js';

// Проверяем обработчик настоящей формы без сети: DOM-порты представлены только используемыми элементами.
function signupForm(t, response) {
    const previous = { window: globalThis.window, document: globalThis.document, fetch: globalThis.fetch };
    t.after(() => Object.assign(globalThis, previous));
    const errors = ['phone', 'consent_pd_processing'].map(error => ({ dataset: { error }, hidden: true, textContent: '' }));
    const formError = { hidden: true, textContent: '' };
    const status = { hidden: true, textContent: '', classList: { toggle() {} } };
    const submit = { disabled: false };
    const listeners = new Map();
    const elements = Object.fromEntries(Object.entries({
        last_name: 'Иванов', first_name: 'Пётр', middle_name: '', phone: '+79001112233',
        messenger: '', form_token: 'token',
    }).map(([name, value]) => [name, { value }]));
    const form = {
        elements,
        addEventListener: (name, callback) => listeners.set(name, callback),
        querySelectorAll(selector) {
            if (selector === '[data-error]') return errors;
            if (selector === 'input[name="consents[]"]:checked') return [{ value: 'pd_processing' }];
            return [];
        },
        querySelector(selector) {
            if (selector === '#fs_exam_session_id') return { value: '7' };
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
    assert.equal(ui.errors[1].hidden, false);
    assert.equal(ui.errors[1].textContent, 'Нужно согласие');
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
