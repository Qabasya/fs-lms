import assert from 'node:assert/strict';
import { registerHooks } from 'node:module';
import { test } from 'node:test';

// Exercise the real mounted submit handlers, replacing unrelated validation/captcha services.
const mocks = {
    'validation-manager.js': 'export const initFormValidation = () => () => true; export const renderFieldError = () => {}; export const clearFieldError = () => {};',
    'input-masks.js': 'export const bindPhoneMask = () => {};',
    'captcha.js': 'export const getCaptchaToken = async () => "token"; export const resetCaptcha = () => {}; export const captchaFailureOf = () => ""; export const captchaFailureAllowsFallback = () => false;',
    'apply-tracking.js': 'export const createApplyTracker = () => ({stage(){},succeeded(){},failed(){},submitAttempt(){},invalid(){}}); export const applyVisitId = () => "fixture";',
    'school-suggest.js': 'export const initSchoolSuggest = () => {};',
    'apply-session.js': 'export const createApplySession = () => ({ensureFresh: async () => {}, prepareOtp: async () => {}});',
    'form-trap.js': 'export const trapField = () => ({}); export const resetTrap = () => {};',
};
registerHooks({
    resolve(specifier, context, next) {
        const source = context.parentURL?.endsWith('/apply-form.js') && mocks[specifier.split('/').pop()];
        return source ? {url: `data:text/javascript,${encodeURIComponent(source)}`, shortCircuit: true} : next(specifier, context);
    },
});

const vars = {ajax_url: '/fixture', actions: {send_otp: 'send', create: 'create'}, nonces: {apply: 'a', verify_otp: 'b'}};
globalThis.window = {fs_lms_apply_vars: vars, scrollTo() {}};
const { initApplyForm } = await import('../../src/js/frontend/services/apply-form.js');

class Element {
    constructor(value = '') {
        this.value = value;
        this.hidden = false;
        this.disabled = false;
        this.textContent = 'Отправить';
        this.handlers = {};
        this.classes = new Set();
        this.classList = {add: name => this.classes.add(name), remove: name => this.classes.delete(name)};
    }
    addEventListener(type, handler) { this.handlers[type] = handler; }
    querySelector() { return this.error || this.countdown || null; }
    prepend(element) { this.error = element; }
    scrollIntoView() {}
    focus() {}
}

function fixture(responses) {
    const nodes = Object.fromEntries(['apply-form', 'otp-step', 'fs-lms-apply-form', 'fs-lms-otp-form', 'fs-apply-submit', 'fs-otp-submit', 'fs_otp_code', 'fs-resend-otp-btn', 'fs_email'].map(id => [id, new Element()]));
    nodes['fs_email'].value = 'fixture@example.test';
    nodes['fs_otp_code'].value = '123456';
    nodes['apply-form'].classes.add('fs-apply-card__step--active');
    nodes['fs-resend-otp-btn'].countdown = new Element();
    const selectors = Object.fromEntries(['.fs-lms-apply-page', '.js-otp-input-block', '.js-otp-success-block', '.js-masked-email'].map(key => [key, new Element()]));
    selectors['.js-otp-success-block'].hidden = true;
    globalThis.document = {getElementById: id => nodes[id] || null, querySelector: selector => selectors[selector] || null, createElement: () => new Element()};
    const requests = [];
    globalThis.fetch = async (url, options) => {
        requests.push(Object.fromEntries(options.body));
        const response = responses.shift();
        if (response instanceof Error) throw response;
        assert.ok(response, 'Unexpected request');
        return {json: async () => response};
    };
    initApplyForm();
    return {nodes, selectors, requests, submit: () => nodes['fs-lms-apply-form'].handlers.submit({preventDefault() {}})};
}

const trusted = {success: true, data: {otp_required: false}};

test('trusted application switches to success only after successful creation', async () => {
    const f = fixture([trusted, {success: true, data: {}}]);
    await f.submit();
    assert.equal(f.nodes['apply-form'].classes.has('fs-apply-card__step--active'), false);
    assert.equal(f.nodes['otp-step'].classes.has('fs-apply-card__step--active'), true);
    assert.equal(f.selectors['.js-otp-success-block'].hidden, false);
    assert.equal(f.selectors['.js-otp-input-block'].hidden, true);
    assert.equal(f.requests[1].otp_code, '');
});

for (const failure of [new Error('Network down'), {success: false, data: {message: 'Сервер отказал'}}]) {
    test(`trusted creation failure keeps visible form, data and working retry (${failure instanceof Error ? 'network' : 'server'})`, async () => {
        const f = fixture([trusted, failure, trusted, {success: true, data: {}}]);
        await f.submit();
        assert.equal(f.nodes['apply-form'].classes.has('fs-apply-card__step--active'), true);
        assert.equal(f.nodes['otp-step'].classes.has('fs-apply-card__step--active'), false);
        assert.equal(f.nodes['fs-lms-apply-form'].error.hidden, false);
        assert.equal(f.nodes['fs-apply-submit'].disabled, false);
        assert.equal(f.nodes['fs-apply-submit'].textContent, 'Отправить');
        assert.equal(f.nodes.fs_email.value, 'fixture@example.test');
        await f.submit();
        assert.equal(f.selectors['.js-otp-success-block'].hidden, false);
        assert.equal(f.requests[3].email, 'fixture@example.test');
    });
}

test('ordinary application requires OTP before creation and restores OTP button on failure', async () => {
    const originalInterval = globalThis.setInterval;
    globalThis.setInterval = () => 1;
    try {
        const f = fixture([{success: true, data: {masked_email: 'f***@example.test'}}, {success: false, data: 'Неверный код'}, {success: true, data: {}}]);
        await f.submit();
        assert.deepEqual(f.requests.map(row => row.action), ['send']);
        assert.equal(f.nodes['otp-step'].classes.has('fs-apply-card__step--active'), true);
        assert.equal(f.selectors['.js-otp-input-block'].hidden, false);
        await f.nodes['fs-lms-otp-form'].handlers.submit({preventDefault() {}});
        assert.equal(f.nodes['fs-lms-otp-form'].error.textContent, 'Неверный код');
        assert.equal(f.nodes['fs-otp-submit'].disabled, false);
        await f.nodes['fs-lms-otp-form'].handlers.submit({preventDefault() {}});
        assert.equal(f.requests[2].otp_code, '123456');
        assert.equal(f.selectors['.js-otp-success-block'].hidden, false);
    } finally {
        globalThis.setInterval = originalInterval;
    }
});
