/**
 * @fileoverview Форма входа (/sign-in/): невидимая капча перед отправкой на wp-login.php.
 *
 * Форма постит на wp-login.php штатно. Если капча включена, первый submit
 * перехватывается: запрашивается токен, пишется в captcha_token, и форма
 * отправляется повторно через requestSubmit( кнопка ) — чтобы в POST ушёл wp-submit.
 *
 * Глобальные переменные: fs_lms_login_vars (FrontendAssets::loginVars; captcha_key
 * дописывает модуль SmartCaptcha).
 */

import { isCaptchaEnabled, isCaptchaReady, getCaptchaToken, resetCaptcha } from './captcha.js';

/**
 * @param {HTMLElement|null} box
 * @param {string}           message
 */
function showError( box, message ) {
    if ( ! box ) { return; }
    box.textContent = message;
    box.hidden      = false;
}

export function initLoginForm() {
    const form = document.getElementById( 'loginform' );
    /** @type {{ captcha_key?: string, captcha_unavailable: string }|undefined} */
    const vars = window.fs_lms_login_vars;

    if ( ! form || ! vars || ! isCaptchaEnabled() ) { return; }

    const button     = form.querySelector( '#wp-submit' );
    const tokenInput = form.querySelector( '[name="captcha_token"]' );
    const errorBox   = document.getElementById( 'fs-login-captcha-error' );
    let tokenReady   = false;

    form.addEventListener( 'submit', async ( event ) => {
        // Повторный проход из requestSubmit — токен уже в форме.
        if ( tokenReady ) { return; }

        event.preventDefault();

        // Скрипт Яндекса не загрузился (блокировщик, сеть): без токена сервер вход отклонит.
        if ( ! isCaptchaReady() ) {
            showError( errorBox, vars.captcha_unavailable );
            return;
        }

        if ( errorBox ) { errorBox.hidden = true; }
        button.disabled = true;

        try {
            tokenInput.value = await getCaptchaToken();
        } catch {
            // captcha-dismissed: пользователь закрыл задание, не решив.
            button.disabled = false;
            resetCaptcha();
            return;
        }

        tokenReady      = true;
        button.disabled = false;
        form.requestSubmit( button );
        button.disabled = true;
    } );
}
