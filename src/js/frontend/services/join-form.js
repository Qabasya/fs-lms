/**
 * Форма заполнения данных родителя (/lms/join/{code}).
 *
 * Глобальные переменные: fs_lms_join_vars (локализуются в Enqueue.php)
 */
import { initFormValidation } from '../../common/validation-manager.js';
import { initDadataAddress } from './dadata-address.js';

let validateAll = null;

/** @type {{ ajax_url: string, actions: { submit_parent: string }, nonces: { parent_submit: string } }} */
const vars = window.fs_lms_join_vars;

// ── AJAX-утилиты ─────────────────────────────────────────────────────────────

function extractError( res, fallback ) {
    if ( typeof res?.data === 'string' ) { return res.data; }
    return res?.data?.message ?? fallback;
}

async function ajaxPost( action, data ) {
    const body = new URLSearchParams( { action, ...data } );
    const res  = await fetch( vars.ajax_url, { method: 'POST', body } );
    return res.json();
}

// ── Маска телефона ────────────────────────────────────────────────────────────

function formatPhone( input ) {
    let value = input.value;

    if ( ! value ) {
        input.value = '+7(';
        return;
    }

    if ( ! value.startsWith( '+7(' ) ) {
        let digits = value.replace( /\D/g, '' );
        if ( digits.startsWith( '7' ) || digits.startsWith( '8' ) ) {
            digits = digits.substring( 1 );
        }
        value = '+7(' + digits;
    }

    const prefix   = '+7(';
    const digits   = value.substring( prefix.length ).replace( /\D/g, '' ).substring( 0, 10 );
    let formatted  = prefix;

    if ( digits.length > 0 ) { formatted += digits.substring( 0, 3 ); }
    if ( digits.length >= 3 ) { formatted += ')-'; }
    if ( digits.length > 3 )  { formatted += digits.substring( 3, 6 ); }
    if ( digits.length >= 6 ) { formatted += '-'; }
    if ( digits.length > 6 )  { formatted += digits.substring( 6, 8 ); }
    if ( digits.length >= 8 ) { formatted += '-'; }
    if ( digits.length > 8 )  { formatted += digits.substring( 8, 10 ); }

    input.value = formatted;
}

function bindPhoneMask( input ) {
    if ( ! input ) { return; }

    if ( input.value ) { formatPhone( input ); }

    input.addEventListener( 'focus', ( e ) => {
        if ( ! e.target.value ) { e.target.value = '+7('; }
    } );

    input.addEventListener( 'input', ( e ) => formatPhone( e.target ) );

    input.addEventListener( 'keydown', ( e ) => {
        if ( e.target.value === '+7(' && ( e.key === 'Backspace' || e.key === 'Delete' ) ) {
            e.preventDefault();
        }
    } );
}

function initPhoneMasks() {
    bindPhoneMask( document.getElementById( 'fs_phone' ) );
    bindPhoneMask( document.getElementById( 'fs_parent_phone' ) );
}

// ── Маска паспорта (серия-номер) ──────────────────────────────────────────────

function formatPassportSN( input ) {
    let value = input.value.replace( /\D/g, '' ).substring( 0, 10 );
    if ( value.length > 4 ) {
        value = value.substring( 0, 4 ) + ' ' + value.substring( 4 );
    }
    input.value = value;
}
// ── Маска ИНН ──────────────────────────────────────────────
function initInnMask() {
    document.querySelectorAll('[data-validate~="inn"]').forEach( input => {
        input.addEventListener( 'input', () => {
            input.value = input.value
                .replace( /\D/g, '' )
                .substring( 0, 12 );
        } );
    } );
}

// ── Утилита очистки ошибки поля ───────────────────────────────────────────────

function clearFieldError( input ) {
    const group = input.closest( '.fs-form-group' );
    if ( ! group ) { return; }
    group.classList.remove( 'form-invalid' );
    const error = group.querySelector( '.fs-field-error' );
    if ( error ) { error.remove(); }
}

// ── Переключение типа документа ученика ───────────────────────────────────────

function initStudentDocType() {
    const typeField   = document.getElementById( 'fs_student_doc_type' );
    const numberField = document.getElementById( 'fs_student_doc_number' );
    const label       = document.getElementById( 'fs_student_doc_number_label' );

    if ( ! typeField || ! numberField || ! label ) { return; }

    const applyState = () => {
        numberField.value = '';
        clearFieldError( numberField );

        if ( typeField.value === 'pass' ) {
            label.textContent            = 'Данные паспорта ученика:';
            numberField.placeholder      = '1234 567890';
            numberField.dataset.validate = 'passportSN';
        } else {
            label.textContent           = 'Данные свидетельства о рождении:';
            numberField.placeholder     = 'Серия и номер свидетельства';
            delete numberField.dataset.validate;
        }
    };

    numberField.addEventListener( 'input', () => {
        if ( typeField.value === 'pass' ) {
            formatPassportSN( numberField );
        }
    } );

    typeField.addEventListener( 'change', applyState );
    applyState();
}

// ── Переключение типа документа родителя ─────────────────────────────────────

function initParentDocType() {
    const typeField   = document.getElementById( 'fs_doc_type' );
    const numberField = document.getElementById( 'fs_doc_number' );
    const label       = document.getElementById( 'fs_doc_number_label' );

    if ( ! typeField || ! numberField || ! label ) { return; }

    const applyState = () => {
        numberField.value = '';
        clearFieldError( numberField );

        if ( typeField.value === 'pass' ) {
            label.textContent            = 'Серия и номер паспорта:';
            numberField.placeholder      = '1234 567890';
            numberField.dataset.validate = 'passportSN';
            numberField.inputMode        = 'numeric';
        } else {
            label.textContent           = 'Серия и номер документа:';
            numberField.placeholder     = 'Данные паспорта иностранного государства';
            delete numberField.dataset.validate;
            numberField.inputMode       = 'text';
        }
    };

    numberField.addEventListener( 'input', () => {
        if ( typeField.value === 'pass' ) {
            formatPassportSN( numberField );
        }
    } );

    typeField.addEventListener( 'change', applyState );
    applyState();
}

// ── UI-утилиты ────────────────────────────────────────────────────────────────

function showError( container, message ) {
    let el = container.querySelector( '.fs-join-card__error' );
    if ( ! el ) {
        el = document.createElement( 'p' );
        el.className = 'fs-join-card__error';
        container.prepend( el );
    }
    el.textContent = message;
    el.hidden = false;
}

function clearError( container ) {
    const el = container.querySelector( '.fs-join-card__error' );
    if ( el ) { el.hidden = true; }
}

function setLoading( btn, loading ) {
    if ( ! btn._origText ) { btn._origText = btn.textContent; }
    btn.disabled    = loading;
    btn.textContent = loading ? 'Отправка...' : btn._origText;
}

// ── Сбор данных ──────────────────────────────────────────────────────────────

function collectFormData() {
    const get = ( name ) => document.querySelector( `[name="${ name }"]` )?.value?.trim() ?? '';

    return {
        security:            vars.nonces.parent_submit,
        join_code:           get( 'join_code' ),
        student_last_name:   get( 'student_last_name' ),
        student_first_name:  get( 'student_first_name' ),
        student_middle_name: get( 'student_middle_name' ),
        school:              get( 'school' ),
        grade:               get( 'grade' ),
        student_birth_date:  get( 'student_birth_date' ),
        student_phone:       get( 'student_phone' ),
        student_doc_type:    get( 'student_doc_type' ),
        student_doc_number:  get( 'student_doc_number' ),
        student_inn:         get( 'student_inn' ),
        parent_last_name:    get( 'parent_last_name' ),
        parent_first_name:   get( 'parent_first_name' ),
        parent_middle_name:  get( 'parent_middle_name' ),
        parent_birth_date:   get( 'parent_birth_date' ),
        doc_type:            get( 'doc_type' ),
        doc_number:          get( 'doc_number' ),
        doc_issued_by:       get( 'doc_issued_by' ),
        doc_issued_date:     get( 'doc_issued_date' ),
        inn:                 get( 'inn' ),
        address:             get( 'address' ),
        phone:               get( 'phone' ),
        email:               get( 'email' ),
    };
}

// ── Переход к экрану успеха ───────────────────────────────────────────────────

function showSuccess() {
    const form    = document.getElementById( 'fs-lms-join-form' );
    const success = document.querySelector( '.js-join-success-block' );
    if ( form )    { form.style.display    = 'none'; }
    if ( success ) { success.style.display = ''; }
}

// ── Обработчик отправки ───────────────────────────────────────────────────────

async function handleJoinSubmit( e ) {
    e.preventDefault();

    if ( validateAll && ! validateAll() ) {
        return;
    }

    const form = document.getElementById( 'fs-lms-join-form' );
    const btn  = document.getElementById( 'fs-join-submit' );

    clearError( form );
    setLoading( btn, true );

    let res;
    try {
        res = await ajaxPost( vars.actions.submit_parent, collectFormData() );
    } catch {
        setLoading( btn, false );
        showError( form, 'Ошибка соединения. Попробуйте позже.' );
        return;
    }

    setLoading( btn, false );

    if ( ! res?.success ) {
        showError( form, extractError( res, 'Ошибка при отправке формы.' ) );
        return;
    }

    showSuccess();
}

// ── Инициализация ─────────────────────────────────────────────────────────────

export function initJoinForm() {
    if ( ! window.fs_lms_join_vars ) { return; }

    const form = document.getElementById( 'fs-lms-join-form' );
    if ( ! form ) { return; }

    validateAll = initFormValidation( form );

    initPhoneMasks();
    initStudentDocType();
    initParentDocType();
    initDadataAddress( vars.dadata_token ?? '' );

    form.addEventListener( 'submit', handleJoinSubmit );
}
