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
 * Пока ждём капчу, рядом с кнопкой крутится индикатор (`[data-login-status]`): ожидание при недоступном
 * скрипте Яндекса занимает несколько секунд, и без него форма выглядит зависшей.
 *
 * Кнопка «Войти» в разметке неактивна (форма идёт в DOM раньше бандла в футере — между ними
 * браузер успевал отправить её без обработчика): включается здесь, в любом исходе инициализации.
 * После возврата кнопкой «Назад» (страница из bfcache) состояние формы сбрасывается — иначе
 * кнопка остаётся заблокированной, а токен прошлой попытки — в скрытом поле.
 *
 * Глобальные переменные: fs_lms_login_vars (FrontendAssets::loginVars; captcha_key
 * дописывает модуль SmartCaptcha).
 */

import { isCaptchaEnabled, getCaptchaToken, resetCaptcha, captchaFailureOf, captchaFailureAllowsFallback } from './captcha.js';

export function initLoginForm() {
    const form = document.getElementById( 'loginform' );
    /** @type {{ captcha_key?: string }|undefined} */
    const vars = window.fs_lms_login_vars;

    if ( ! form ) { return; }

    const button = form.querySelector( '#wp-submit' );

    // Капчи нет — обработчик не нужен, но форма должна стать доступной.
    if ( ! vars || ! isCaptchaEnabled() ) {
        if ( button ) { button.disabled = false; }
        return;
    }

    const tokenInput   = form.querySelector( '[name="captcha_token"]' );
    const failureInput = form.querySelector( '[name="captcha_unavailable"]' );
    const status       = form.querySelector( '[data-login-status]' );
    const showStatus   = ( visible ) => { if ( status ) { status.hidden = ! visible; } };
    let tokenReady     = false;

    window.addEventListener?.( 'pageshow', ( event ) => {
        if ( ! event.persisted ) { return; }
        tokenReady       = false;
        showStatus( false );
        tokenInput.value = '';
        if ( failureInput ) { failureInput.value = ''; }
        button.disabled  = false;
        resetCaptcha();
    } );

    form.addEventListener( 'submit', async ( event ) => {
        // Повторный проход из requestSubmit — токен уже в форме.
        if ( tokenReady ) { return; }

        event.preventDefault();

        button.disabled = true;
        showStatus( true );

        try {
            tokenInput.value = await getCaptchaToken();
        } catch ( error ) {
            const failure = captchaFailureOf( error );

            // Пользователь закрыл задание, не решив: вход не пропускаем.
            if ( ! captchaFailureAllowsFallback( failure ) ) {
                showStatus( false );
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

    // Обработчик подключён — форму можно отправлять.
    button.disabled = false;
}

/**
 * Продолжение в следующей задаче — уже после обработки текущего события.
 *
 * @returns {Promise<void>}
 */
function nextTask() {
    return new Promise( ( resolve ) => window.setTimeout( resolve, 0 ) );
}
