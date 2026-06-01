/**
 * Двухэтапная форма подачи заявки на обучение (/lms/apply).
 *
 * Этап 1: валидация полей → (капча если настроена) → send_otp → переход на этап OTP
 * Этап 2: ввод OTP → create (ajaxCreateApplication) → экран успеха
 *
 * Глобальные переменные: fs_lms_apply_vars (локализуются в Enqueue.php)
 */

import { PhoneValidator } from '../../common/validators/PhoneValidator';


/** @type {{ ajax_url: string, captcha_key: string, actions: { send_otp: string, create: string }, nonces: { apply: string, verify_otp: string } }} */
const vars = window.fs_lms_apply_vars;

/** Данные формы этапа 1, сохраняются для передачи на этапе 2 */
let _formData = null;

// ── Сбор данных ──────────────────────────────────────────────────────────────

function collectFormData() {
    const lastName   = document.getElementById( 'fs_last_name' )?.value.trim()  ?? '';
    const firstName  = document.getElementById( 'fs_first_name' )?.value.trim() ?? '';
    const middleName = document.getElementById( 'fs_middle_name' )?.value.trim() ?? '';

    // Телефон
    const rawPhone   = document.getElementById( 'fs_phone' )?.value.trim() ?? '';
    const cleanPhone = rawPhone.replace( /[()\-]/g, '' ); // Из "+7(999)-123-45-67" делает "+79991234567"

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

function validateStep1( data ) {
    const required = [ 'last_name', 'first_name', 'email', 'phone','birth_date', 'school',  'grade', 'username', 'password' ];

    // 1. Проверяем заполненность базовых полей
    const allRequiredFilled = required.every( key => data[ key ] !== '' );
    if ( ! allRequiredFilled ) {
        return false;
    }

    // 2. Валидация телефона через наш новый PhoneValidator
    const phoneInput = document.getElementById( 'fs_phone' );
    if ( phoneInput ) {
        const phoneError = PhoneValidator.validate( phoneInput );

        if ( phoneError ) {
            // Если есть ошибка, подсвечиваем поле и выводим сообщение из валидатора
            setFieldInvalid( phoneInput, phoneError );
            return false;
        } else {
            // Если всё чисто, убираем старые алерты валидации
            setFieldValid( phoneInput );
        }
    }

    return true;
}

// ── Маскирование телефона ───────────────────────────────────────────────────

function handlePhoneInput( e ) {
    const input = e.target;
    let value = input.value;

    // 1. Если пользователь полностью стёр данные, принудительно возвращаем "+7("
    if ( ! value ) {
        input.value = '+7(';
        return;
    }

    // 2. Если пользователь пытается ввести что-то до "+7(", сбрасываем это поведение
    if ( ! value.startsWith( '+7(' ) ) {
        // Выделяем только цифры, игнорируя начальные +7, 7, 8
        let digits = value.replace( /\D/g, '' );
        if ( digits.startsWith( '7' ) || digits.startsWith( '8' ) ) {
            digits = digits.substring( 1 );
        }
        value = '+7(' + digits;
    }

    // 3. Форматируем только цифровую часть после "+7("
    const prefix = '+7(';
    const maxDigitsAfterPrefix = 10;
    let pureDigits = value.substring( prefix.length ).replace( /\D/g, '' ).substring( 0, maxDigitsAfterPrefix );

    let formatted = prefix;

    if ( pureDigits.length > 0 ) {
        formatted += pureDigits.substring( 0, 3 );
    }
    if ( pureDigits.length >= 3 ) {
        formatted += ')-';
    } else if ( e.type === 'blur' && pureDigits.length > 0 ) {
        // На случай потери фокуса дополняем закрывающей скобкой
        formatted += ')';
    }

    if ( pureDigits.length > 3 ) {
        formatted += pureDigits.substring( 3, 6 );
    }
    if ( pureDigits.length >= 6 ) {
        formatted += '-';
    }
    if ( pureDigits.length > 6 ) {
        formatted += pureDigits.substring( 6, 8 );
    }
    if ( pureDigits.length >= 8 ) {
        formatted += '-';
    }
    if ( pureDigits.length > 8 ) {
        formatted += pureDigits.substring( 8, 10 );
    }

    input.value = formatted;
}

// ── AJAX-утилиты ─────────────────────────────────────────────────────────────

/** wp_send_json_error() без контекста кладёт строку в data, с контекстом — объект */
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

function showSuccess() {
    document.querySelector( '.js-otp-input-block' ).style.display  = 'none';
    document.querySelector( '.js-otp-success-block' ).style.display = '';
}

// ── Обработчики событий ───────────────────────────────────────────────────────

async function handleApplySubmit( e ) {
    e.preventDefault();

    const form = document.getElementById( 'fs-lms-apply-form' );
    const btn  = document.getElementById( 'fs-apply-submit' );
    const data = collectFormData();

    clearError( form );

    if ( ! validateStep1( data ) ) {
        showError( form, 'Пожалуйста, заполните все поля.' );
        return;
    }

    setLoading( btn, true );

    // captcha_key пустой → NullCaptchaProvider, токен не нужен
    const captchaToken = vars.captcha_key ? ( window._fsCaptchaToken ?? '' ) : '';

    let res;
    try {
        res = await ajaxPost( vars.actions.send_otp, {
            security:      vars.nonces.apply,
            email:         data.email,
            captcha_token: captchaToken,
        } );
    } catch {
        setLoading( btn, false );
        showError( form, 'Ошибка соединения. Попробуйте позже.' );
        return;
    }

    setLoading( btn, false );

    if ( ! res?.success ) {
        showError( form, extractError( res, 'Ошибка при отправке кода.' ) );
        return;
    }

    _formData = data;
    showOtpStep( res.data.masked_email );
}

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

    showSuccess();
}

async function handleResendOtp() {
    if ( ! _formData ) { return; }

    const captchaToken = vars.captcha_key ? ( window._fsCaptchaToken ?? '' ) : '';

    let res;
    try {
        res = await ajaxPost( vars.actions.send_otp, {
            security:      vars.nonces.apply,
            email:         _formData.email,
            captcha_token: captchaToken,
        } );
    } catch {
        return;
    }

    if ( res?.success ) {
        const btn         = document.getElementById( 'fs-resend-otp-btn' );
        const countdownEl = btn.querySelector( '.js-otp-countdown' );
        startCountdown( btn, countdownEl );
    }
}

// ── Инициализация ─────────────────────────────────────────────────────────────

export function initApplyForm() {
    if ( ! window.fs_lms_apply_vars ) { return; }
    if ( ! document.getElementById( 'fs-lms-apply-form' ) ) { return; }

    document.getElementById( 'fs-lms-apply-form' )
        .addEventListener( 'submit', handleApplySubmit );

    document.getElementById( 'fs-lms-otp-form' )
        .addEventListener( 'submit', handleOtpSubmit );

    document.getElementById( 'fs-resend-otp-btn' )
        .addEventListener( 'click', handleResendOtp );

    const phoneInput = document.getElementById( 'fs_phone' );
    if ( phoneInput ) {
        phoneInput.addEventListener( 'focus', (e) => {
            if ( ! e.target.value ) {
                e.target.value = '+7(';
            }
        } );

        phoneInput.addEventListener( 'input', handlePhoneInput );

        // При ручном размыкании (blur) тоже прогоняем валидацию, чтобы подсветить ошибку сразу
        phoneInput.addEventListener( 'blur', () => {
            if ( phoneInput.value && phoneInput.value !== '+7(' ) {
                const error = PhoneValidator.validate( phoneInput );
                if ( error ) {
                    setFieldInvalid( phoneInput, error );
                }
            }
        } );

        phoneInput.addEventListener( 'keydown', (e) => {
            const value = e.target.value;
            if ( value === '+7(' && ( e.key === 'Backspace' || e.key === 'Delete' ) ) {
                e.preventDefault();
            }
        } );
    }
}