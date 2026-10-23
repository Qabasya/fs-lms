import assert from 'node:assert/strict';
import test, { mock } from 'node:test';

// Время виртуальное и стоит на нуле: ожидание скрипта Яндекса (SCRIPT_WAIT_MS) само не истечёт —
// значит, отказ без прокрутки времени может прийти только от события error самого скрипта.
mock.timers.enable({ apis: ['setTimeout', 'Date'] });
globalThis.window = {
    fs_lms_login_vars: { captcha_key: 'key' },
    setTimeout: (...args) => globalThis.setTimeout(...args),
    clearTimeout: (...args) => globalThis.clearTimeout(...args),
};

const { getCaptchaToken, onCaptchaScriptError } = await import('../../src/js/frontend/services/captcha.js');

async function flush() {
    for (let i = 0; i < 20; i++) { await Promise.resolve(); }
}

/** Исход запроса токена после прогона микрозадач: 'pending' | 'resolved' | причина отказа. */
async function outcome() {
    let state = 'pending';
    getCaptchaToken().then(() => { state = 'resolved'; }, error => { state = error.message; });
    await flush();
    return state;
}

test('пока скрипт Яндекса может ещё догрузиться, запрос токена ждёт', async () => {
    assert.equal(await outcome(), 'pending');
});

test('ошибка чужого ресурса капчу недоступной не делает', async () => {
    onCaptchaScriptError({ target: { id: 'other-script' } });
    onCaptchaScriptError({});

    assert.equal(await outcome(), 'pending');
});

// Блокировщик/сбой DNS обрывают загрузку сразу: ждать SCRIPT_WAIT_MS нет смысла.
test('скрипт Яндекса оборвался с ошибкой: отказ сразу, без ожидания таймаута', async () => {
    onCaptchaScriptError({ target: { id: 'fs-lms-smartcaptcha-js' } });

    assert.equal(await outcome(), 'not_loaded');
});
