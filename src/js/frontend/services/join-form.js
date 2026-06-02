/**
 * Форма заполнения данных родителя (/lms/join/{code}).
 *
 * Глобальные переменные: fs_lms_join_vars (локализуются в Enqueue.php)
 */

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
        relation_type:       get( 'relation_type' ),
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

function validateForm( data ) {
    const required = [
        'student_last_name', 'student_first_name', 'school', 'grade', 'student_birth_date',
        'student_doc_type', 'student_doc_number',
        'parent_last_name', 'parent_first_name', 'parent_birth_date', 'relation_type',
        'doc_type', 'doc_number', 'email',
    ];

    if ( required.some( key => ! data[ key ] ) ) { return false; }

    const consent = document.querySelector( '[name="consent_parent"]' );
    if ( consent && ! consent.checked ) { return false; }

    return true;
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

    const form = document.getElementById( 'fs-lms-join-form' );
    const btn  = document.getElementById( 'fs-join-submit' );

    clearError( form );

    const data = collectFormData();

    if ( ! validateForm( data ) ) {
        showError( form, 'Пожалуйста, заполните все обязательные поля.' );
        return;
    }

    setLoading( btn, true );

    let res;
    try {
        res = await ajaxPost( vars.actions.submit_parent, data );
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
    if ( ! document.getElementById( 'fs-lms-join-form' ) ) { return; }

    document.getElementById( 'fs-lms-join-form' )
        .addEventListener( 'submit', handleJoinSubmit );
}