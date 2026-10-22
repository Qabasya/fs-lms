import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test, { mock } from 'node:test';

// captcha.js хранит состояние на уровне модуля (_loadedAt), поэтому сценарии идут по порядку
// в одном экземпляре модуля, а время — виртуальное: ожидание скрипта Яндекса занимает 6 секунд.
mock.timers.enable({ apis: ['setTimeout', 'Date'] });

// captcha.js на уровне модуля ставит window.__fsSmartCaptchaReady — окно нужно уже при импорте.
globalThis.window = {};

const { initLoginForm } = await import('../../src/js/frontend/services/login-form.js');

/** Минимальная форма входа: кнопка, два скрытых поля и обработчики submit. */
function makeForm() {
    const listeners = [];
    const button = { disabled: false };
    const tokenInput = { value: '' };
    const failureInput = { value: '' };
    const navigations = [];

    const fire = () => {
        let prevented = false;
        listeners.forEach(listener => listener({ preventDefault: () => { prevented = true; } }));
        if (!prevented) {
            navigations.push({ token: tokenInput.value, unavailable: failureInput.value });
        }
    };

    const form = {
        addEventListener: (name, listener) => { if ('submit' === name) { listeners.push(listener); } },
        querySelector: selector => ({
            '#wp-submit': button,
            '[name="captcha_token"]': tokenInput,
            '[name="captcha_unavailable"]': failureInput,
        })[selector] ?? null,
        requestSubmit: fire,
    };

    return { form, fire, navigations, button };
}

/** Прогон микрозадач между сдвигами времени: промисы формы разрешаются в них. */
async function flush() {
    for (let i = 0; i < 20; i++) { await Promise.resolve(); }
}

async function submitWithUnreachableCaptcha(t, waitMs) {
    const previousWindow = globalThis.window;
    const previousDocument = globalThis.document;
    t.after(() => {
        globalThis.window = previousWindow;
        globalThis.document = previousDocument;
    });

    const { form, fire, navigations, button } = makeForm();
    globalThis.window = {
        fs_lms_login_vars: { captcha_key: 'key' },
        setTimeout: (...args) => globalThis.setTimeout(...args),
        clearTimeout: (...args) => globalThis.clearTimeout(...args),
    };
    globalThis.document = { getElementById: id => ('loginform' === id ? form : {}) };

    initLoginForm();
    mock.timers.tick(waitMs);
    fire();
    await flush();
    mock.timers.tick(8000);
    await flush();

    return { navigations, button };
}

test('Яндекс недоступен: форма уходит с причиной not_loaded, а не пустой', async t => {
    const { navigations } = await submitWithUnreachableCaptcha(t, 2000);

    assert.deepEqual(navigations, [{ token: '', unavailable: 'not_loaded' }]);
});

test('Яндекс недоступен давно: причина отправляется сразу', async t => {
    const { navigations } = await submitWithUnreachableCaptcha(t, 20000);

    assert.deepEqual(navigations, [{ token: '', unavailable: 'not_loaded' }]);
});

// Регресс: обработчик входа жил в DOMContentLoaded, а его держал блокирующий скрипт Яндекса —
// на VPN форма уходила без токена и без причины. Вход подключается при выполнении бандла.
test('frontend.js: форма входа подключается вне DOMContentLoaded', () => {
    const source = readFileSync(new URL('../../src/js/frontend/frontend.js', import.meta.url), 'utf8');
    const marker = source.indexOf("addEventListener('DOMContentLoaded'");

    assert.notEqual(marker, -1);
    assert.ok(source.slice(0, marker).includes('initLoginForm();'), 'initLoginForm() должен вызываться до подписки на DOMContentLoaded');
    assert.ok(!source.slice(marker).includes('initLoginForm();'), 'initLoginForm() не должен ждать DOMContentLoaded');
});
