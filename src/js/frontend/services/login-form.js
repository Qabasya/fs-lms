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
 * Блокировка входа (серия неверных паролей): сервер отдаёт форму с отключёнными полями и блоком
 * `[data-login-lock]` с остатком в секундах. Здесь идёт обратный отсчёт; по его концу поля и кнопка
 * («[data-lock-field]») включаются, блок и красная плашка скрываются. Кнопка «Войти» на время паузы
 * остаётся неактивной в любом исходе инициализации. Отсчёт — от абсолютного времени, а не по тикам,
 * поэтому вкладка в фоне и возврат из bfcache не сбивают его.
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
    const lock   = startLoginLock( form );
    // Кнопка «Войти» блокируется вместе с полями: пока поля отключены сервером или идёт отсчёт, она не включается —
    // даже если блок таймера не нашёлся (кнопка не должна быть активной при отключённых полях).
    const isLocked = () => lock.active || true === form.querySelector( '#user_pass' )?.disabled;

    // Капчи нет — обработчик не нужен, но форма должна стать доступной.
    if ( ! vars || ! isCaptchaEnabled() ) {
        if ( button && ! isLocked() ) { button.disabled = false; }
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
        button.disabled  = isLocked();
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

    // Обработчик подключён — форму можно отправлять (если вход не закрыт блокировкой).
    button.disabled = isLocked();
}

/**
 * Обратный отсчёт блокировки входа: таймер в блоке `[data-login-lock]`, по концу — включает форму.
 *
 * @param {HTMLFormElement} form Форма входа
 * @returns {{ active: boolean }} `active` — пока пауза не кончилась (читается лениво: меняется по таймеру)
 */
function startLoginLock( form ) {
    // Блок стоит под формой, а не внутри неё (шаблон auth-page.php): искать надо по документу.
    const lockEl = document.querySelector( '[data-login-lock]' );
    const state  = { active: false };
    const left   = lockEl ? parseInt( lockEl.dataset.loginLock, 10 ) : 0;

    if ( ! lockEl || ! ( left > 0 ) ) { return state; }

    const timerEl  = lockEl.querySelector( '[data-login-lock-timer]' );
    const deadline = Date.now() + left * 1000;
    let intervalId = null;

    state.active = true;

    const unlock = () => {
        state.active = false;
        window.clearInterval( intervalId );
        lockEl.hidden = true;
        document.querySelector( '.fs-auth-card__error' )?.setAttribute( 'hidden', '' );
        form.querySelectorAll( '[data-lock-field]' ).forEach( ( field ) => { field.disabled = false; } );
        form.querySelector( '#user_pass' )?.focus?.();
    };

    const tick = () => {
        const seconds = Math.max( 0, Math.ceil( ( deadline - Date.now() ) / 1000 ) );
        if ( timerEl ) { timerEl.textContent = formatCountdown( seconds ); }
        if ( 0 === seconds ) { unlock(); }
    };

    tick();
    if ( state.active ) { intervalId = window.setInterval( tick, 1000 ); }

    return state;
}

/**
 * «14:32» до часа, «1:05:09» — от часа (блокировка длится до суток).
 *
 * @param {number} totalSeconds Секунды, не меньше нуля
 * @returns {string}
 */
export function formatCountdown( totalSeconds ) {
    const hours   = Math.floor( totalSeconds / 3600 );
    const minutes = Math.floor( ( totalSeconds % 3600 ) / 60 );
    const seconds = totalSeconds % 60;
    const pad     = ( n ) => String( n ).padStart( 2, '0' );

    return hours > 0 ? `${ hours }:${ pad( minutes ) }:${ pad( seconds ) }` : `${ pad( minutes ) }:${ pad( seconds ) }`;
}

/**
 * Продолжение в следующей задаче — уже после обработки текущего события.
 *
 * @returns {Promise<void>}
 */
function nextTask() {
    return new Promise( ( resolve ) => window.setTimeout( resolve, 0 ) );
}
