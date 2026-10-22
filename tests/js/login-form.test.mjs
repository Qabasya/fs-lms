import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test, { mock } from 'node:test';

// captcha.js хранит состояние на уровне модуля (_loadedAt), поэтому сценарии идут по порядку
// в одном экземпляре модуля, а время — виртуальное: ожидание скрипта Яндекса занимает 6 секунд.
mock.timers.enable({ apis: ['setTimeout', 'Date'] });

// captcha.js на уровне модуля ставит window.__fsSmartCaptchaReady — окно нужно уже при импорте.
globalThis.window = {};

const { initLoginForm } = await import('../../src/js/frontend/services/login-form.js');

/** Прогон микрозадач: промисы формы разрешаются в них. */
async function flush() {
    for (let i = 0; i < 20; i++) { await Promise.resolve(); }
}

/**
 * Минимальная форма входа. Ведёт себя как браузер в одном важном месте: микрозадачи, оставшиеся
 * после обработчика submit, выполняются ещё ВНУТРИ обработки события, а requestSubmit(), вызванный
 * в это время, молча игнорируется (флаг «форма отправляется» ещё не снят).
 */
function makeForm() {
    const listeners = [];
    const button = { disabled: false };
    const tokenInput = { value: '' };
    const failureInput = { value: '' };
    const navigations = [];
    const ignored = [];
    let dispatching = false;

    const fire = async () => {
        dispatching = true;
        let prevented = false;
        listeners.forEach(listener => listener({ preventDefault: () => { prevented = true; } }));
        await flush();
        dispatching = false;
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
        requestSubmit: () => {
            if (dispatching) {
                ignored.push('requestSubmit внутри обработки submit');
                return undefined;
            }
            return fire();
        },
    };

    return { form, fire, navigations, ignored, button };
}

async function submitWithUnreachableCaptcha(t, waitMs) {
    const previousWindow = globalThis.window;
    const previousDocument = globalThis.document;
    t.after(() => {
        globalThis.window = previousWindow;
        globalThis.document = previousDocument;
    });

    const { form, fire, navigations, ignored, button } = makeForm();
    globalThis.window = {
        fs_lms_login_vars: { captcha_key: 'key' },
        setTimeout: (...args) => globalThis.setTimeout(...args),
        clearTimeout: (...args) => globalThis.clearTimeout(...args),
    };
    globalThis.document = { getElementById: id => ('loginform' === id ? form : {}) };

    initLoginForm();
    mock.timers.tick(waitMs);
    await fire();

    // Время идёт порциями, между ними — микрозадачи: так срабатывают и опрос скрипта, и nextTask().
    for (let i = 0; i < 10; i++) {
        mock.timers.tick(1000);
        await flush();
    }

    return { navigations, ignored, button };
}

test('Яндекс недоступен: форма уходит с причиной not_loaded, а не пустой', async t => {
    const { navigations, ignored } = await submitWithUnreachableCaptcha(t, 2000);

    assert.deepEqual(ignored, []);
    assert.deepEqual(navigations, [{ token: '', unavailable: 'not_loaded' }]);
});

// Регресс: сбой известен заранее (скрипт уже упал), промис отклонён сразу, и requestSubmit() шёл
// из микрозадачи внутри обработки submit — браузер его игнорировал: форма висела без запроса.
test('Яндекс недоступен давно: отправка не теряется внутри обработки submit', async t => {
    const { navigations, ignored, button } = await submitWithUnreachableCaptcha(t, 20000);

    assert.deepEqual(ignored, []);
    assert.deepEqual(navigations, [{ token: '', unavailable: 'not_loaded' }]);
    assert.equal(button.disabled, true, 'кнопка остаётся заблокированной до ответа сервера');
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
