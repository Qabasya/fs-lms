/**
 * @fileoverview Форма входа (/sign-in/): невидимая капча перед отправкой на wp-login.php.
 *
 * Форма постит на wp-login.php штатно. Если капча включена, первый submit
 * перехватывается: запрашивается токен, пишется в captcha_token, и форма
 * отправляется повторно через requestSubmit( кнопка ) — чтобы в POST ушёл wp-submit. Повтор —
 * строго в следующей задаче (nextTask()): внутри обработки submit браузер его игнорирует.
 *
 * Капча недоступна из части сетей (VPN, блокировщик): если она не загрузилась / не открылась
 * вовремя, форма уходит без токена с причиной в captcha_unavailable — сервер пропускает такой вход
 * по смягчённому правилу (CaptchaService::check()), пароль и лимит попыток проверяются как обычно.
 * Закрытое пользователем задание не пропускается.
 *
 * Глобальные переменные: fs_lms_login_vars (FrontendAssets::loginVars; captcha_key
 * дописывает модуль SmartCaptcha).
 */

import { isCaptchaEnabled, getCaptchaToken, resetCaptcha, captchaFailureOf, captchaFailureAllowsFallback } from './captcha.js';

export function initLoginForm() {
    const form = document.getElementById( 'loginform' );
    /** @type {{ captcha_key?: string }|undefined} */
    const vars = window.fs_lms_login_vars;

    if ( ! form || ! vars || ! isCaptchaEnabled() ) { return; }

    const button       = form.querySelector( '#wp-submit' );
    const tokenInput   = form.querySelector( '[name="captcha_token"]' );
    const failureInput = form.querySelector( '[name="captcha_unavailable"]' );
    let tokenReady     = false;

    form.addEventListener( 'submit', async ( event ) => {
        // Повторный проход из requestSubmit — токен уже в форме.
        if ( tokenReady ) { return; }

        event.preventDefault();

        button.disabled = true;

        try {
            tokenInput.value = await getCaptchaToken();
        } catch ( error ) {
            const failure = captchaFailureOf( error );

            // Пользователь закрыл задание, не решив: вход не пропускаем.
            if ( ! captchaFailureAllowsFallback( failure ) ) {
                button.disabled = false;
                resetCaptcha();
                return;
            }

            // Капча не дошла (VPN, блокировщик, сеть): форма уходит без токена с причиной.
            tokenInput.value = '';
            if ( failureInput ) { failureInput.value = failure; }
        }

        tokenReady = true;

        // Не в том же стеке, что обработчик submit. Если сбой капчи известен заранее (скрипт Яндекса
        // уже не загрузился), промис отклонён сразу, и весь путь досюда — микрозадачи ВНУТРИ обработки
        // исходного submit. Браузер игнорирует requestSubmit() во время submit-события той же формы:
        // отправка терялась, запрос на wp-login.php не уходил, а кнопка оставалась заблокированной.
        await nextTask();

        button.disabled = false;
        form.requestSubmit( button );
        button.disabled = true;
    } );
}

/**
 * Продолжение в следующей задаче — уже после обработки текущего события.
 *
 * @returns {Promise<void>}
 */
function nextTask() {
    return new Promise( ( resolve ) => window.setTimeout( resolve, 0 ) );
}
