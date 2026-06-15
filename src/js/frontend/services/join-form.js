/**
 * Форма заполнения данных родителя (/lms/join/{code}).
 *
 * Глобальные переменные: fs_lms_join_vars (локализуются в Enqueue.php)
 */
import { initFormValidation, renderFieldError, clearFieldError } from '../../common/validation-manager.js';
import { initDadataAddress } from './dadata-address.js';
import { createDadataSuggest } from './dadata-suggest.js';
import { bindPhoneMask, formatPassportSN } from '../../common/input-masks.js';

let validateAll   = null;
let _emailInput   = null;

async function checkEmailAvailable( input ) {
    if ( ! input || input.readOnly ) { return true; }

    const email = input.value.trim();
    if ( ! email ) { return true; }

    try {
        const body = new URLSearchParams( {
            action:   vars.actions.check_email,
            security: vars.nonces.check_email,
            email,
        } );
        const res  = await fetch( vars.ajax_url, { method: 'POST', body } );
        const json = await res.json();

        if ( json?.success && json.data?.available === false ) {
            renderFieldError( input, 'Этот email уже зарегистрирован.' );
            return false;
        }
    } catch {}

    clearFieldError( input );
    return true;
}

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

function initPhoneMasks() {
    bindPhoneMask( document.getElementById( 'fs_phone' ) );
    bindPhoneMask( document.getElementById( 'fs_parent_phone' ) );
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

    if ( ! await checkEmailAvailable( _emailInput ) ) {
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

// ── DaData подсказки ──────────────────────────────────────────────────────────

function initDadataSuggests( token ) {
	if ( ! token ) { return; }

	const fioFields = [
		{ id: 'fs_student_last_name',   parts: 'SURNAME' },
		{ id: 'fs_student_first_name',  parts: 'NAME' },
		{ id: 'fs_student_middle_name', parts: 'PATRONYMIC' },
		{ id: 'fs_parent_last_name',    parts: 'SURNAME' },
		{ id: 'fs_parent_first_name',   parts: 'NAME' },
		{ id: 'fs_parent_middle_name',  parts: 'PATRONYMIC' },
	];

	fioFields.forEach( ( { id, parts } ) => {
		const input = document.getElementById( id );
		if ( ! input ) { return; }
		createDadataSuggest( input, {
			endpoint:  'fio',
			buildBody: ( query ) => ( { query, count: 5, parts: [ parts ] } ),
			getValue:  ( s ) => s.value,
		}, token );
	} );

	const issuedBy = document.getElementById( 'fs_doc_issued_by' );
	if ( issuedBy ) {
		createDadataSuggest( issuedBy, {
			endpoint:  'fms_unit',
			buildBody: ( query ) => ( { query, count: 5 } ),
			getValue:  ( s ) => s.value,
		}, token );
	}

	const email = document.getElementById( 'fs_parent_email' );
	if ( email ) {
		createDadataSuggest( email, {
			endpoint:  'email',
			buildBody: ( query ) => ( { query, count: 5 } ),
			getValue:  ( s ) => s.value,
		}, token );
	}
}

// ── Инициализация ─────────────────────────────────────────────────────────────

export function initJoinForm() {
    if ( ! window.fs_lms_join_vars ) { return; }

    const form = document.getElementById( 'fs-lms-join-form' );
    if ( ! form ) { return; }

    validateAll = initFormValidation( form );

    _emailInput = document.getElementById( 'fs_parent_email' );
    if ( _emailInput ) {
        _emailInput.addEventListener( 'blur', () => checkEmailAvailable( _emailInput ) );
    }

    initPhoneMasks();
    initStudentDocType();
    initParentDocType();

    const dadataToken = vars.dadata_token ?? '';
    initDadataAddress( dadataToken );
    initDadataSuggests( dadataToken );

    form.addEventListener( 'submit', handleJoinSubmit );
}
