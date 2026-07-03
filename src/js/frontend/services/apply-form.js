/**
 * Двухэтапная форма подачи заявки на обучение (/lms/apply).
 *
 * Этап 1: валидация полей → (капча если настроена) → send_otp → переход на этап OTP
 * Этап 2: ввод OTP → create (ajaxCreateApplication) → экран успеха
 *
 * Глобальные переменные: fs_lms_apply_vars (локализуются в Enqueue.php)
 */

import { initFormValidation, renderFieldError, clearFieldError } from '../../common/validation-manager.js';
import { bindPhoneMask } from '../../common/input-masks.js';
import { getCaptchaToken, resetCaptcha, initCaptcha } from './captcha.js';

/** @type {{ ajax_url: string, captcha_key: string, hp_field: string, form_token: string, actions: { send_otp: string, create: string }, nonces: { apply: string, verify_otp: string } }} */
const vars = window.fs_lms_apply_vars;

/** Данные формы этапа 1, сохраняются для передачи на этапе 2 */
let _formData = null;

/** Код направления, введённый в гейте (если включена привязка к направлению) */
let _directionCode = '';

/**
 * Читает значение honeypot-поля (должно быть пустым у людей).
 * @returns {string}
 */
function readHoneypot() {
    const name = vars.hp_field || 'fs_company';
    return document.querySelector( `[name="${ name }"]` )?.value ?? '';
}

// ── Сбор данных ──────────────────────────────────────────────────────────────

function collectFormData() {
    const lastName   = document.getElementById( 'fs_last_name' )?.value.trim()  ?? '';
    const firstName  = document.getElementById( 'fs_first_name' )?.value.trim() ?? '';
    const middleName = document.getElementById( 'fs_middle_name' )?.value.trim() ?? '';

    const rawPhone   = document.getElementById( 'fs_phone' )?.value.trim() ?? '';
    const cleanPhone = rawPhone.replace( /[()\-]/g, '' );

    return {
        last_name:   lastName,
        first_name:  firstName,
        middle_name: middleName,
        full_name:   [ lastName, firstName, middleName ].filter( Boolean ).join( ' ' ),
        email:       document.getElementById( 'fs_email' )?.value.trim()      ?? '',
        phone:       cleanPhone,
        username:    document.getElementById( 'fs_username' )?.value.trim()   ?? '',
        password:    document.getElementById( 'fs_password' )?.value          ?? '',
        birth_date:  document.getElementById( 'fs_birth_date' )?.value        ?? '',
        school:      document.getElementById( 'fs_school' )?.value.trim()     ?? '',
        grade:       document.getElementById( 'fs_grade' )?.value             ?? '',
    };
}

// ── AJAX-утилиты ─────────────────────────────────────────────────────────────

function extractError( res, fallback ) {
    if ( typeof res?.data === 'string' ) { return res.data; }
    return res?.data?.message ?? fallback;
}

// ── UI-утилиты ────────────────────────────────────────────────────────────────

function showError( container, message ) {
    let el = container.querySelector( '.fs-apply-card__error' );
    if ( ! el ) {
        el = document.createElement( 'p' );
        el.className = 'fs-apply-card__error';
        container.prepend( el );
    }
    el.textContent = message;
    el.hidden = false;
}

function clearError( container ) {
    const el = container.querySelector( '.fs-apply-card__error' );
    if ( el ) { el.hidden = true; }
}

function setLoading( btn, loading ) {
    if ( ! btn._origText ) { btn._origText = btn.textContent; }
    btn.disabled    = loading;
    btn.textContent = loading ? 'Отправка...' : btn._origText;
}

function startCountdown( btn, countdownEl, seconds = 60 ) {
    let remaining = seconds;
    btn.disabled  = true;
    countdownEl.textContent = `(${ remaining })`;

    const timer = setInterval( () => {
        remaining--;
        countdownEl.textContent = `(${ remaining })`;
        if ( remaining <= 0 ) {
            clearInterval( timer );
            btn.disabled = false;
            countdownEl.textContent = '';
        }
    }, 1000 );
}

async function ajaxPost( action, data ) {
    const body = new URLSearchParams( { action, ...data } );
    const res  = await fetch( vars.ajax_url, { method: 'POST', body } );
    return res.json();
}

// ── Переходы между этапами ───────────────────────────────────────────────────

function showOtpStep( maskedEmail ) {
    document.getElementById( 'apply-form' ).classList.remove( 'fs-apply-card__step--active' );
    document.getElementById( 'otp-step' ).classList.add( 'fs-apply-card__step--active' );
    document.querySelector( '.js-masked-email' ).textContent = maskedEmail;

    const resendBtn   = document.getElementById( 'fs-resend-otp-btn' );
    const countdownEl = resendBtn.querySelector( '.js-otp-countdown' );
    startCountdown( resendBtn, countdownEl );
}

function showSuccess( notice ) {
    document.querySelector( '.js-otp-input-block' ).style.display  = 'none';
    document.querySelector( '.js-otp-success-block' ).style.display = '';

    // Необязательное серверное сообщение + спиннер (например, статус создания доменной учётки).
    if ( notice ) {
        const statusEl = document.querySelector( '.js-apply-status' );
        const noticeEl = document.querySelector( '.js-apply-notice' );
        if ( noticeEl ) { noticeEl.textContent = notice; }
        if ( statusEl ) { statusEl.hidden = false; }
    }
}

/**
 * Generic-поллинг статуса: если ответ apply содержит `poll`, опрашиваем указанное действие,
 * обновляя сообщение, пока статус не станет терминальным (done/failed) или не истечёт лимит.
 * Ядро не знает, что именно создаётся (это инструктирует модуль через ответ).
 *
 * @param {{action:string,nonce:string,ref:number,interval?:number,max?:number}} [poll]
 */
function startStatusPoll( poll ) {
    if ( ! poll || ! poll.action ) { return; }

    const noticeEl  = document.querySelector( '.js-apply-notice' );
    const spinnerEl = document.querySelector( '.js-apply-spinner' );
    const interval  = poll.interval || 2500;
    const max       = poll.max || 40;
    let count = 0;

    const stop = () => {
        if ( spinnerEl ) { spinnerEl.hidden = true; }
    };

    const tick = async () => {
        count++;
        let res;
        try {
            res = await ajaxPost( poll.action, { ref: poll.ref, security: poll.nonce } );
        } catch {
            // сетевый сбой — попробуем на следующем тике
        }

        const state   = res?.data?.state;
        const message = res?.data?.message;
        if ( message && noticeEl ) { noticeEl.textContent = message; }

        if ( state === 'done' || state === 'failed' || count >= max ) {
            stop();
            return;
        }
        window.setTimeout( tick, interval );
    };

    window.setTimeout( tick, interval );
}

// ── Обработчики событий ───────────────────────────────────────────────────────

async function handleOtpSubmit( e ) {
    e.preventDefault();

    const form    = document.getElementById( 'fs-lms-otp-form' );
    const btn     = document.getElementById( 'fs-otp-submit' );
    const otpCode = document.getElementById( 'fs_otp_code' )?.value.trim() ?? '';

    clearError( form );

    if ( ! otpCode ) {
        showError( form, 'Введите код подтверждения.' );
        return;
    }

    setLoading( btn, true );

    let res;
    try {
        res = await ajaxPost( vars.actions.create, {
            security: vars.nonces.verify_otp,
            ..._formData,
            otp_code: otpCode,
            direction_code: _directionCode,
        } );
    } catch {
        setLoading( btn, false );
        showError( form, 'Ошибка соединения. Попробуйте позже.' );
        return;
    }

    setLoading( btn, false );

    if ( ! res?.success ) {
        showError( form, extractError( res, 'Ошибка при подтверждении кода.' ) );
        return;
    }

    showSuccess( res.data?.notice );
    startStatusPoll( res.data?.poll );
}

async function handleResendOtp() {
    if ( ! _formData ) { return; }

    let captchaToken = '';
    try {
        captchaToken = await getCaptchaToken();
    } catch {
        return;
    }

    let res;
    try {
        res = await ajaxPost( vars.actions.send_otp, {
            security:      vars.nonces.apply,
            email:         _formData.email,
            captcha_token: captchaToken,
            form_token:    vars.form_token ?? '',
            [ vars.hp_field || 'fs_company' ]: readHoneypot(),
        } );
    } catch {
        resetCaptcha();
        return;
    }

    if ( res?.success ) {
        const btn         = document.getElementById( 'fs-resend-otp-btn' );
        const countdownEl = btn.querySelector( '.js-otp-countdown' );
        startCountdown( btn, countdownEl );
    } else {
        resetCaptcha();
    }
}

// ── Инициализация ─────────────────────────────────────────────────────────────

async function checkUsernameAvailable( input ) {
    const username = input.value.trim();
    if ( ! username ) { return true; }

    try {
        const body = new URLSearchParams( {
            action:   vars.actions.check_username,
            security: vars.nonces.check_username,
            username,
        } );
        const res  = await fetch( vars.ajax_url, { method: 'POST', body } );
        const json = await res.json();

        if ( json?.success && json.data?.available === false ) {
            renderFieldError( input, 'Этот логин уже занят.' );
            return false;
        }
    } catch {}

    clearFieldError( input );
    return true;
}

/**
 * Серверный гейт кода направления. Форма НЕ присутствует в DOM, пока сервер не
 * подтвердит код: vars.actions.validate_code возвращает HTML формы только на верный
 * код, JS вставляет его в слот и навешивает поведение. Снять гейт через DevTools/
 * adblock нельзя — до верного кода разметки формы попросту нет.
 */
function initGate() {
    const gate = document.getElementById( 'fs-apply-gate' );
    if ( ! gate ) { return; }

    const input = document.getElementById( 'fs-direction-code-input' );
    const btn   = document.getElementById( 'fs-direction-gate-submit' );
    const err   = document.getElementById( 'fs-direction-gate-error' );
    const slot  = document.getElementById( 'fs-apply-form-slot' );

    const submit = async () => {
        const code = input.value.trim();
        err.hidden = true;

        if ( ! code ) {
            err.textContent = 'Введите код направления.';
            err.hidden = false;
            return;
        }

        btn.disabled = true;

        let res;
        try {
            res = await ajaxPost( vars.actions.validate_code, {
                security:       vars.nonces.apply,
                direction_code: code,
            } );
        } catch {
            btn.disabled = false;
            err.textContent = 'Ошибка соединения. Попробуйте позже.';
            err.hidden = false;
            return;
        }

        if ( ! res?.success || ! res.data?.form_html ) {
            btn.disabled = false;
            err.textContent = extractError( res, 'Неверный код направления.' );
            err.hidden = false;
            return;
        }

        // Код верный — форма появляется в DOM только сейчас.
        _directionCode = code;
        slot.innerHTML = res.data.form_html;
        gate.remove();

        // #6: показываем название направления под заголовком карточки.
        const dirEl = document.getElementById( 'fs-apply-direction' );
        if ( dirEl && res.data.direction_name ) {
            dirEl.textContent = res.data.direction_name;
            dirEl.hidden = false;
        }

        bindFormBehaviors();

        // Капча рендерится в #fs-captcha-slot, который существует только после
        // инъекции формы. initCaptcha идемпотентен — повторный вызов безопасен.
        initCaptcha();

        document.getElementById( 'fs_last_name' )?.focus();
    };

    btn.addEventListener( 'click', submit );
    input.addEventListener( 'keydown', ( e ) => {
        if ( 'Enter' === e.key ) {
            e.preventDefault();
            submit();
        }
    } );
    input.focus();
}

/**
 * Навешивает поведение на форму заявки (валидация, маска, проверка логина, сабмиты).
 * Вызывается либо сразу (форма инлайн), либо после инъекции формы из гейта.
 */
function bindFormBehaviors() {
    const applyForm = document.getElementById( 'fs-lms-apply-form' );
    if ( ! applyForm ) { return; }

    const validateAll = initFormValidation( applyForm );

    const usernameInput = document.getElementById( 'fs_username' );
    if ( usernameInput ) {
        usernameInput.addEventListener( 'blur', () => checkUsernameAvailable( usernameInput ) );
    }

    applyForm.addEventListener( 'submit', async ( e ) => {
        e.preventDefault();

        const btn  = document.getElementById( 'fs-apply-submit' );
        const data = collectFormData();

        clearError( applyForm );

        if ( ! validateAll() ) { return; }

        if ( usernameInput && ! await checkUsernameAvailable( usernameInput ) ) { return; }

        setLoading( btn, true );

        let captchaToken = '';
        try {
            captchaToken = await getCaptchaToken();
        } catch {
            setLoading( btn, false );
            showError( applyForm, 'Проверка капчи не пройдена. Попробуйте ещё раз.' );
            return;
        }

        let res;
        try {
            res = await ajaxPost( vars.actions.send_otp, {
                security:      vars.nonces.apply,
                email:         data.email,
                captcha_token: captchaToken,
                form_token:    vars.form_token ?? '',
                [ vars.hp_field || 'fs_company' ]: readHoneypot(),
            } );
        } catch {
            setLoading( btn, false );
            resetCaptcha();
            showError( applyForm, 'Ошибка соединения. Попробуйте позже.' );
            return;
        }

        setLoading( btn, false );

        if ( ! res?.success ) {
            resetCaptcha();
            showError( applyForm, extractError( res, 'Ошибка при отправке кода.' ) );
            return;
        }

        _formData = data;
        showOtpStep( res.data.masked_email );
    } );

    document.getElementById( 'fs-lms-otp-form' )
        ?.addEventListener( 'submit', handleOtpSubmit );

    document.getElementById( 'fs-resend-otp-btn' )
        ?.addEventListener( 'click', handleResendOtp );

    bindPhoneMask( document.getElementById( 'fs_phone' ) );
}

/**
 * Точка входа формы заявки. Если включена привязка к направлению — показываем
 * серверный гейт (форма придёт по AJAX после верного кода); иначе форма уже в DOM.
 */
export function initApplyForm() {
    if ( ! window.fs_lms_apply_vars ) { return; }
    if ( ! document.querySelector( '.fs-lms-apply-page' ) ) { return; }

    if ( vars.bind_to_subject ) {
        initGate();
    } else {
        bindFormBehaviors();
    }
}