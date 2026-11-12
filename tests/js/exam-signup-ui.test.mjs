import assert from 'node:assert/strict';
import test from 'node:test';

import { initExamSignup } from '../../src/js/frontend/services/exam-signup.js';

// Проверяем обработчик настоящей формы без сети: DOM-порты представлены только используемыми элементами.
function signupForm(t, response) {
    const previous = { window: globalThis.window, document: globalThis.document, fetch: globalThis.fetch };
    t.after(() => Object.assign(globalThis, previous));
    const formError = { hidden: true, textContent: '', scrollIntoView() { this.scrolled = true; }, focus() {} };
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
        messenger: field('messenger', '@ivan', { required: true }),
        session_id: field('session_id', '7', { type: 'hidden' }),
        consent: field('consent', 'pd_processing', { required: true, type: 'checkbox', checked: true, error: 'consent_pd_processing' }),
        transfer: field('transfer', 'pd_transfer', { required: true, type: 'checkbox', checked: true, error: 'consent_pd_transfer' }),
        form_token: { value: 'token' },
    };
    const inputs = Object.values(elements).filter(input => input.closest);
    // Ловушка: autofilled имитирует :autofill (значение вписал браузер), иначе значение «вписал скрипт».
    const trap = {
        name: 'fs_ref_id', value: '', autofilled: false,
        matches(selector) { return this.autofilled && selector === ':autofill'; },
    };
    const form = {
        elements, setAttribute() {},
        addEventListener: (name, callback) => listeners.set(name, callback),
        querySelectorAll(selector) {
            if (selector === '[data-field]') return groups;
            if (selector === 'input, select, textarea') return inputs;
            if (selector === 'input[name="consents[]"]:checked') return [elements.consent, elements.transfer].filter(input => input.checked);
            return [];
        },
        querySelector(selector) {
            if (selector === '#fs_exam_session_id') return elements.session_id;
            if (selector === '#fs-exam-submit') return submit;
            if (selector === '[data-fs-trap]') return trap;
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
    return { errors, formError, submit, elements, trap, requests, send: () => listeners.get('submit')({ preventDefault() {} }) };
}

test('X-ACCESS без адреса кабинета показывает и прокручивает сообщение', async t => {
    const ui = signupForm(t, { success: false, data: { code: 'X-ACCESS', field: 'cabinet', message: 'Запись доступна в личном кабинете.' } });
    await ui.send();
    assert.equal(ui.formError.hidden, false);
    assert.equal(ui.formError.textContent, 'Запись доступна в личном кабинете.');
    assert.equal(ui.formError.scrolled, true);
});

test('X-ACCESS с адресом кабинета переносит с отметкой для уведомления', async t => {
    const ui = signupForm(t, { success: false, data: { code: 'X-ACCESS', field: 'cabinet', cabinet_url: '/profile/?screen=learner-exams' } });
    let destination = '';
    globalThis.window.location = { origin: 'https://example.test', assign: url => { destination = url; } };
    await ui.send();
    const url = new URL(destination, 'https://example.test');
    assert.equal(url.searchParams.get('screen'), 'learner-exams');
    assert.equal(url.searchParams.get('exam_signup_notice'), 'cabinet');
});

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

for (const [name, value] of [['last_name', ' '], ['first_name', 'а'.repeat(101)], ['middle_name', 'а'.repeat(101)], ['phone', '+7 900'], ['messenger', 'x'.repeat(101)], ['messenger', ''], ['session_id', '']]) {
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

test('согласие на передачу результата обязательно для формы записи', async t => {
    const ui = signupForm(t, { success: true });
    ui.elements.transfer.checked = false;
    await ui.send();
    assert.equal(ui.requests.length, 0);
    assert.equal(ui.errors.find(error => error.dataset.error === 'consent_pd_transfer').hidden, false);
});

for (const field of ['last_name', 'unknown']) {
    test(`ошибка ${field}: разметка сервера остаётся буквальным текстом`, async t => {
        const message = '<img src=x onerror="alert(1)"> & <script>alert(2)</script>';
        const ui = signupForm(t, { success: false, data: { field, message } });
        await ui.send();
        const box = field === 'last_name' ? ui.errors.find(error => error.dataset.error === field) : ui.formError;
        assert.equal(box.hidden, false);
        assert.equal(box.textContent, message);
        assert.equal(box.innerHTML, undefined);
        assert.equal(ui.submit.disabled, false);
    });
}

test('ловушка: значение, вписанное автозаполнением браузера, не уходит на сервер', async t => {
    const ui = signupForm(t, { success: true, data: { redirect: '/cart/' } });
    globalThis.window.location = { assign() {} };
    ui.trap.value = 'Школа №1';
    ui.trap.autofilled = true;
    await ui.send();
    assert.equal(ui.requests.length, 1);
    assert.equal(ui.requests[0].options.body.get('fs_ref_id'), '');
});

test('ловушка: значение, вписанное скриптом, уходит на сервер', async t => {
    const ui = signupForm(t, { success: true, data: { redirect: '/cart/' } });
    globalThis.window.location = { assign() {} };
    ui.trap.value = 'spam';
    await ui.send();
    assert.equal(ui.requests[0].options.body.get('fs_ref_id'), 'spam');
});

test('ловушка: после отказа поле очищено, повторная отправка проходит', async t => {
    const ui = signupForm(t, { success: false, data: { message: 'Не удалось отправить форму. Нажмите кнопку ещё раз.' } });
    ui.trap.value = 'расширение вписало';
    await ui.send();
    assert.equal(ui.trap.value, '');
    await ui.send();
    assert.equal(ui.requests[1].options.body.get('fs_ref_id'), '');
});
