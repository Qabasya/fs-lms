/**
 * @fileoverview Невидимая Yandex SmartCaptcha для форм заявки и входа.
 *
 * @module captcha
 * @description Рендерит невидимый виджет в #fs-captcha-slot и выдаёт токен
 *              по требованию (на submit) через промис. Скрипт Яндекса грузится
 *              с ?onload=__fsSmartCaptchaReady — поэтому колбэк выставляется
 *              на уровне модуля, до загрузки внешнего скрипта.
 *
 *              Сервис Яндекса недоступен из части сетей (VPN, блокировщики): скрипт
 *              не грузится, невидимая проверка зависает, задание не показывается.
 *              Промис токена отклоняется с причиной из CAPTCHA_FAILURE — форма по
 *              ней решает, отправлять ли запрос без токена (смягчённое правило
 *              сервера, CaptchaService::check()).
 */

/**
 * Причины, по которым токен не получен. Значения совпадают с PHP-enum
 * Inc\Enums\Auth\CaptchaFailure — их же принимает сервер.
 */
export const CAPTCHA_FAILURE = Object.freeze( {
    NOT_LOADED: 'not_loaded',
    TIMEOUT:    'timeout',
    NETWORK:    'network',
    DISMISSED:  'dismissed',
} );

/** Невидимая проверка обычно укладывается в 1–2 с; дольше — сеть Яндекса не отвечает. */
const SILENT_TIMEOUT_MS = 8000;

/** Если задание показано — пользователю нужно время его решить. */
const CHALLENGE_TIMEOUT_MS = 60000;

/** Сколько ждём загрузки скрипта Яндекса, прежде чем считать капчу недоступной. */
const SCRIPT_WAIT_MS = 6000;

/** @type {number|null} ID виджета SmartCaptcha. */
let _widgetId = null;

/** @type {{ resolve: Function, reject: Function }|null} Ожидающий промис токена. */
let _pending = null;

/** @type {number|null} Таймер ожидания токена. */
let _timer = null;

/** @type {Array<(event: string, detail?: string) => void>} Слушатели событий капчи (трекинг формы). */
const _listeners = [];

/** Момент загрузки модуля — от него считаем ожидание скрипта Яндекса. */
const _loadedAt = Date.now();

/**
 * Подписка на события капчи: `shown` — показано задание, `failed` — токен не получен
 * (detail — значение CAPTCHA_FAILURE).
 *
 * @param {(event: string, detail?: string) => void} listener
 */
export function onCaptchaEvent( listener ) {
    _listeners.push( listener );
}

function emit( event, detail ) {
    _listeners.forEach( ( listener ) => listener( event, detail ) );
}

/**
 * Код причины из отклонённого промиса getCaptchaToken().
 *
 * @param {unknown} error
 * @returns {string} Значение CAPTCHA_FAILURE; DISMISSED, если причина неизвестна.
 */
export function captchaFailureOf( error ) {
    const code = error instanceof Error ? error.message : '';
    return Object.values( CAPTCHA_FAILURE ).includes( code ) ? code : CAPTCHA_FAILURE.DISMISSED;
}

/**
 * Можно ли отправить форму без токена: капча не дошла до браузера (не вина пользователя).
 *
 * @param {string} failure Значение CAPTCHA_FAILURE
 * @returns {boolean}
 */
export function captchaFailureAllowsFallback( failure ) {
    return CAPTCHA_FAILURE.DISMISSED !== failure;
}

function clearTimer() {
    if ( null !== _timer ) {
        window.clearTimeout( _timer );
        _timer = null;
    }
}

/**
 * Отклоняет ожидающий запрос токена с причиной.
 * @param {string} failure Значение CAPTCHA_FAILURE
 */
function failPending( failure ) {
    clearTimer();
    if ( ! _pending ) { return; }

    const { reject } = _pending;
    _pending = null;
    emit( 'failed', failure );
    reject( new Error( failure ) );
}

/**
 * Клиентский ключ из переменных текущей формы (заявка или вход).
 * @returns {string}
 */
function siteKey() {
    const vars = window.fs_lms_apply_vars || window.fs_lms_login_vars;
    return ( vars && vars.captcha_key ) || '';
}

/**
 * @returns {boolean} Капча подключена (задан клиентский ключ).
 */
export function isCaptchaEnabled() {
    return '' !== siteKey();
}

/**
 * @returns {boolean} Скрипт Яндекса загрузился и виджет отрисован.
 */
export function isCaptchaReady() {
    return null !== _widgetId && !! window.smartCaptcha;
}

/**
 * Колбэк успешного прохождения — Яндекс передаёт сюда токен.
 * @param {string} token
 */
function onToken( token ) {
    clearTimer();
    window._fsCaptchaToken = token;
    if ( _pending ) {
        _pending.resolve( token );
        _pending = null;
    }
}

/**
 * Рендер невидимого виджета. Вызывается Яндексом через __fsSmartCaptchaReady.
 */
export function initCaptcha() {
    if ( ! isCaptchaEnabled() || ! window.smartCaptcha ) { return; }

    const slot = document.getElementById( 'fs-captcha-slot' );
    if ( ! slot || null !== _widgetId ) { return; }

    _widgetId = window.smartCaptcha.render( slot, {
        sitekey:    siteKey(),
        invisible:  true,
        // Плашку о политике обработки данных не показываем (решение 2026-09-12);
        // CSS-страховка на случай, если Яндекс проигнорирует параметр, — в _apply-form.scss.
        hideShield: true,
        callback:   onToken,
    } );

    if ( 'function' === typeof window.smartCaptcha.subscribe ) {
        // Невидимая проверка не справилась — Яндекс показал задание: ждём решения дольше.
        window.smartCaptcha.subscribe( _widgetId, 'challenge-visible', () => {
            if ( ! _pending ) { return; }
            emit( 'shown' );
            clearTimer();
            _timer = window.setTimeout( () => failPending( CAPTCHA_FAILURE.TIMEOUT ), CHALLENGE_TIMEOUT_MS );
        } );

        // Пользователь закрыл challenge, не решив — отклоняем ожидающий промис.
        window.smartCaptcha.subscribe( _widgetId, 'challenge-hidden', () => {
            if ( _pending && ! window._fsCaptchaToken ) {
                failPending( CAPTCHA_FAILURE.DISMISSED );
            }
        } );

        // Сеть до Яндекса оборвалась посреди проверки (типичный VPN).
        window.smartCaptcha.subscribe( _widgetId, 'network-error', () => {
            failPending( CAPTCHA_FAILURE.NETWORK );
        } );
    }
}

// Колбэк для ?onload=__fsSmartCaptchaReady из URL скрипта Яндекса.
window.__fsSmartCaptchaReady = initCaptcha;

/**
 * Запрашивает свежий токен капчи. Если капча не подключена — резолвит ''.
 * Если подключена, но до браузера не дошла или не ответила вовремя — отклоняется
 * с причиной (см. captchaFailureOf()).
 *
 * @returns {Promise<string>}
 */
export function getCaptchaToken() {
    if ( ! isCaptchaEnabled() ) {
        return Promise.resolve( '' );
    }

    if ( ! isCaptchaReady() ) {
        return waitForScript().then( () => requestToken() );
    }

    return requestToken();
}

/**
 * Скрипт Яндекса мог ещё грузиться (медленная сеть) — даём ему SCRIPT_WAIT_MS с момента
 * открытия страницы; не загрузился — капча недоступна.
 *
 * @returns {Promise<void>}
 */
function waitForScript() {
    return new Promise( ( resolve, reject ) => {
        const poll = () => {
            if ( isCaptchaReady() ) {
                resolve();
            } else if ( Date.now() - _loadedAt >= SCRIPT_WAIT_MS ) {
                emit( 'failed', CAPTCHA_FAILURE.NOT_LOADED );
                reject( new Error( CAPTCHA_FAILURE.NOT_LOADED ) );
            } else {
                window.setTimeout( poll, 250 );
            }
        };
        poll();
    } );
}

/**
 * @returns {Promise<string>}
 */
function requestToken() {
    return new Promise( ( resolve, reject ) => {
        _pending = { resolve, reject };
        window._fsCaptchaToken = '';
        clearTimer();
        _timer = window.setTimeout( () => failPending( CAPTCHA_FAILURE.TIMEOUT ), SILENT_TIMEOUT_MS );
        window.smartCaptcha.execute( _widgetId );
    } );
}

/**
 * Сбрасывает виджет — токен одноразовый, перед повторной отправкой нужен reset.
 */
export function resetCaptcha() {
    if ( null !== _widgetId && window.smartCaptcha ) {
        window.smartCaptcha.reset( _widgetId );
    }
    window._fsCaptchaToken = '';
}
