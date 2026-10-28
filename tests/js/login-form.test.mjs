import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test, { mock } from 'node:test';

// captcha.js хранит состояние на уровне модуля (_loadedAt), поэтому сценарии идут по порядку
// в одном экземпляре модуля, а время — виртуальное: ожидание скрипта Яндекса занимает 6 секунд.
mock.timers.enable({ apis: ['setTimeout', 'setInterval', 'Date'] });

// captcha.js на уровне модуля ставит window.__fsSmartCaptchaReady — окно нужно уже при импорте.
globalThis.window = {};

const { initLoginForm, formatCountdown } = await import('../../src/js/frontend/services/login-form.js');

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
    const status = { hidden: true };
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
            '[data-login-status]': status,
        })[selector] ?? null,
        requestSubmit: () => {
            if (dispatching) {
                ignored.push('requestSubmit внутри обработки submit');
                return undefined;
            }
            return fire();
        },
    };

    return { form, fire, navigations, ignored, button, status };
}

/** Показан ли индикатор ожидания сразу после нажатия «Войти» (заполняет submitWithUnreachableCaptcha). */
let statusAfterSubmit = null;

async function submitWithUnreachableCaptcha(t, waitMs) {
    const previousWindow = globalThis.window;
    const previousDocument = globalThis.document;
    t.after(() => {
        globalThis.window = previousWindow;
        globalThis.document = previousDocument;
    });

    const { form, fire, navigations, ignored, button, status } = makeForm();
    globalThis.window = {
        fs_lms_login_vars: { captcha_key: 'key' },
        setTimeout: (...args) => globalThis.setTimeout(...args),
        clearTimeout: (...args) => globalThis.clearTimeout(...args),
    };
    globalThis.document = { getElementById: id => ('loginform' === id ? form : {}), querySelector: () => null };

    initLoginForm();
    mock.timers.tick(waitMs);
    await fire();
    statusAfterSubmit = status.hidden;

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
    assert.equal(statusAfterSubmit, false, 'пока форма ждёт капчу, рядом с кнопкой виден индикатор');
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

/** Окружение формы для проверки кнопки и bfcache: окно запоминает подписки на pageshow. */
function setupForm(t, vars) {
    const previousWindow = globalThis.window;
    const previousDocument = globalThis.document;
    t.after(() => {
        globalThis.window = previousWindow;
        globalThis.document = previousDocument;
    });

    const made = makeForm();
    const pageshow = [];
    made.button.disabled = true; // как в разметке: кнопка неактивна до подключения обработчика
    globalThis.window = {
        ...(vars ? { fs_lms_login_vars: vars } : {}),
        setTimeout: (...args) => globalThis.setTimeout(...args),
        clearTimeout: (...args) => globalThis.clearTimeout(...args),
        addEventListener: (name, listener) => { if ('pageshow' === name) { pageshow.push(listener); } },
    };
    globalThis.document = { getElementById: id => ('loginform' === id ? made.form : {}), querySelector: () => null };

    return { ...made, pageshow };
}

// Регресс: форма стоит в DOM раньше бандла в футере, и «Войти» успевали нажать без обработчика.
test('кнопка «Войти» включается, когда обработчик капчи подключён', t => {
    const { button } = setupForm(t, { captcha_key: 'key' });

    assert.equal(button.disabled, true);
    initLoginForm();
    assert.equal(button.disabled, false);
});

test('капча не подключена: форма всё равно становится доступной', t => {
    const { button } = setupForm(t, {});

    initLoginForm();
    assert.equal(button.disabled, false);
});

// Назад из bfcache: страница возвращается с прошлым состоянием JS — кнопка заблокирована, токен старый.
test('возврат кнопкой «Назад» (bfcache) сбрасывает состояние формы', async t => {
    const { form, button, pageshow } = setupForm(t, { captcha_key: 'key' });
    const tokenInput = form.querySelector('[name="captcha_token"]');

    initLoginForm();
    tokenInput.value = 'old-token';
    button.disabled = true;

    pageshow.forEach(listener => listener({ persisted: false }));
    assert.equal(button.disabled, true, 'обычная загрузка состояние не трогает');

    pageshow.forEach(listener => listener({ persisted: true }));
    assert.equal(button.disabled, false);
    assert.equal(tokenInput.value, '');
});

/**
 * Форма в режиме блокировки входа: поля и кнопка отключены, блок `[data-login-lock]` несёт остаток
 * в секундах, красная плашка уже на странице. Подключённые поля — `[data-lock-field]`.
 *
 * Как в настоящем DOM (auth-page.php): блок и плашка стоят ВНЕ формы, поэтому `form.querySelector()`
 * их не видит — найти можно только через `document`. Прошлая заглушка отдавала блок из формы, и баг
 * «таймер не стартует, поля так и остаются отключёнными» тесты не поймали.
 */
function setupLockedForm(t, seconds, vars) {
    const made = setupForm(t, vars);
    const timer = { textContent: '' };
    const lockEl = {
        dataset: { loginLock: String(seconds) },
        hidden: false,
        querySelector: selector => ('[data-login-lock-timer]' === selector ? timer : null),
    };
    const fields = [{ disabled: true }, { disabled: true }];
    const password = { disabled: true, focused: false, focus() { this.focused = true; } };
    const plate = { hidden: false, setAttribute(name) { if ('hidden' === name) { this.hidden = true; } } };
    const baseQuery = made.form.querySelector;

    made.form.querySelector = selector => ({ '#user_pass': password })[selector] ?? baseQuery(selector);
    made.form.querySelectorAll = selector => ('[data-lock-field]' === selector ? [...fields, password, made.button] : []);
    globalThis.document.querySelector = selector => ({
        '[data-login-lock]': lockEl,
        '.fs-auth-card__error': plate,
    })[selector] ?? null;
    globalThis.window.setInterval = (...args) => globalThis.setInterval(...args);
    globalThis.window.clearInterval = (...args) => globalThis.clearInterval(...args);

    return { ...made, timer, lockEl, fields, password, plate };
}

test('формат обратного отсчёта: минуты до часа, часы с часа', () => {
    assert.equal(formatCountdown(0), '00:00');
    assert.equal(formatCountdown(872), '14:32');
    assert.equal(formatCountdown(3599), '59:59');
    assert.equal(formatCountdown(3725), '1:02:05');
    assert.equal(formatCountdown(86400), '24:00:00');
});

test('блокировка: форма отключена, таймер идёт и включает её по концу паузы', t => {
    const { button, timer, lockEl, fields, password, plate } = setupLockedForm(t, 90, {});

    initLoginForm();
    assert.equal(button.disabled, true, 'кнопка «Войти» остаётся неактивной на время паузы');
    assert.equal(timer.textContent, '01:30');

    mock.timers.tick(30000);
    assert.equal(timer.textContent, '01:00');
    assert.ok(fields.every(field => field.disabled) && password.disabled, 'поля отключены, пока идёт пауза');

    mock.timers.tick(60000);
    assert.equal(button.disabled, false);
    assert.ok(fields.every(field => !field.disabled) && !password.disabled, 'по концу паузы поля включаются');
    assert.equal(lockEl.hidden, true, 'блок с припиской скрыт');
    assert.equal(plate.hidden, true, 'красная плашка «повторите позже» скрыта');
    assert.equal(password.focused, true, 'фокус возвращается в поле пароля');
});

test('блокировка при включённой капче: кнопку не включает ни подключение обработчика, ни bfcache', t => {
    const { button, pageshow } = setupLockedForm(t, 600, { captcha_key: 'key' });

    initLoginForm();
    assert.equal(button.disabled, true, 'обработчик капчи подключён, но пауза не кончилась');

    pageshow.forEach(listener => listener({ persisted: true }));
    assert.equal(button.disabled, true, 'возврат из bfcache во время паузы не включает кнопку');

    mock.timers.tick(600000);
    assert.equal(button.disabled, false, 'по концу паузы кнопка включается');
});

test('без блокировки форма включается сразу, как раньше', t => {
    const { button } = setupForm(t, {});

    initLoginForm();
    assert.equal(button.disabled, false);
});

// Регресс: блок таймера не нашёлся, а поля отключены сервером — «Войти» не должна включаться одна.
test('поля отключены, а блок таймера не найден: кнопка «Войти» всё равно остаётся отключённой', t => {
    for (const vars of [{}, { captcha_key: 'key' }]) {
        const { form, button } = setupForm(t, vars);
        const baseQuery = form.querySelector;
        form.querySelector = selector => ('#user_pass' === selector ? { disabled: true } : baseQuery(selector));

        initLoginForm();
        assert.equal(button.disabled, true, `vars=${JSON.stringify(vars)}: кнопка следует за отключёнными полями`);
    }
});
