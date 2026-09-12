/**
 * @fileoverview Невидимая Yandex SmartCaptcha для форм заявки и входа.
 *
 * @module captcha
 * @description Рендерит невидимый виджет в #fs-captcha-slot и выдаёт токен
 *              по требованию (на submit) через промис. Скрипт Яндекса грузится
 *              с ?onload=__fsSmartCaptchaReady — поэтому колбэк выставляется
 *              на уровне модуля, до загрузки внешнего скрипта.
 */

/** @type {number|null} ID виджета SmartCaptcha. */
let _widgetId = null;

/** @type {{ resolve: Function, reject: Function }|null} Ожидающий промис токена. */
let _pending = null;

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

    // Пользователь закрыл challenge, не решив — отклоняем ожидающий промис.
    if ( 'function' === typeof window.smartCaptcha.subscribe ) {
        window.smartCaptcha.subscribe( _widgetId, 'challenge-hidden', () => {
            if ( _pending && ! window._fsCaptchaToken ) {
                _pending.reject( new Error( 'captcha-dismissed' ) );
                _pending = null;
            }
        } );
    }
}

// Колбэк для ?onload=__fsSmartCaptchaReady из URL скрипта Яндекса.
window.__fsSmartCaptchaReady = initCaptcha;

/**
 * Запрашивает свежий токен капчи. Если капча не подключена — резолвит ''.
 *
 * @returns {Promise<string>}
 */
export function getCaptchaToken() {
    if ( ! isCaptchaEnabled() || null === _widgetId || ! window.smartCaptcha ) {
        return Promise.resolve( '' );
    }

    return new Promise( ( resolve, reject ) => {
        _pending = { resolve, reject };
        window._fsCaptchaToken = '';
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
