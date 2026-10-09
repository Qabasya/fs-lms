/**
 * Запись гостя на экзамен по ссылке школы (этап 11a.3–11a.5): форма, карусель сеансов, резюме, «Перейти к оплате»,
 * обратный отсчёт брони в корзине и на оформлении, «Проверить статус» на странице «Спасибо».
 *
 * Данные страницы приходят с сервера (`fs_lms_exam_signup_vars`); цену, школу и класс клиент не определяет. `request_key` создаётся
 * один раз при загрузке и переиспользуется при повторе — после ошибки корзины сервер просит новый. Форма при ошибках не очищается.
 */

import { bindPhoneMask } from '../../common/input-masks.js';
import { buildSummary, validateForm, formatLeft, secondsLeft } from './exam-signup-model.js';

function newRequestKey() {
    return ( window.crypto && window.crypto.randomUUID ) ? window.crypto.randomUUID() : `k${ Date.now() }${ Math.random().toString( 16 ).slice( 2 ) }`;
}

function post( vars, action, data ) {
    const body = new URLSearchParams();
    Object.entries( data ).forEach( ( [ k, v ] ) => {
        if ( Array.isArray( v ) ) { v.forEach( item => body.append( `${ k }[]`, item ) ); } else { body.append( k, v ); }
    } );
    body.append( 'action', action );
    body.append( 'security', vars.nonce );

    return fetch( vars.ajax_url, { method: 'POST', credentials: 'same-origin', body } ).then( r => r.json() );
}

export function initExamSignup() {
    const vars = window.fs_lms_exam_signup_vars;
    if ( ! vars ) { return; }

    initSignupForm( vars );
    initHoldCountdown();
    initOrderStatus( vars );
}

/* ── Форма ───────────────────────────────────────────────────────────── */
function initSignupForm( vars ) {
    const form = document.getElementById( 'fs-exam-signup-form' );
    if ( ! form ) { return; }

    let requestKey = newRequestKey();
    let busy = false;
    const slots = Array.from( form.querySelectorAll( '.fs-exam-slot' ) );
    const sessionInput = form.querySelector( '#fs_exam_session_id' );

    bindPhoneMask( form.querySelector( '#fs_exam_phone' ) );
    initCarousel( form );

    const value = name => ( form.elements[ name ] ? form.elements[ name ].value : '' );
    const consents = () => Array.from( form.querySelectorAll( 'input[name="consents[]"]:checked' ) ).map( el => el.value );
    const chosenSlot = () => slots.find( s => s.getAttribute( 'aria-checked' ) === 'true' ) || null;
    const slotData = el => el ? { date: el.dataset.date, weekday: el.dataset.weekday, time: el.dataset.time } : null;

    const collect = () => ( {
        last_name: value( 'last_name' ), first_name: value( 'first_name' ), middle_name: value( 'middle_name' ),
        phone: value( 'phone' ), messenger: value( 'messenger' ), session_id: sessionInput.value, consents: consents(),
    } );

    const refreshSummary = () => {
        const s = buildSummary( collect(), slotData( chosenSlot() ) );
        Object.entries( s ).forEach( ( [ key, text ] ) => {
            const el = form.querySelector( `[data-summary="${ key }"]` );
            if ( el ) { el.textContent = text; }
        } );
    };

    const showError = ( field, text ) => {
        const el = form.querySelector( `[data-error="${ field }"]` );
        if ( el ) { el.textContent = text; el.hidden = ! text; }
    };
    const clearErrors = () => form.querySelectorAll( '[data-error]' ).forEach( el => { el.hidden = true; el.textContent = ''; } );
    const formBox = document.getElementById( 'fs-exam-form-error' );
    const showFormError = text => { formBox.textContent = text; formBox.hidden = ! text; };
    const status = document.getElementById( 'fs-exam-status' );
    const setBusy = ( on, text = '' ) => {
        busy = on;
        form.querySelector( '#fs-exam-submit' ).disabled = on;
        status.hidden = ! on;
        status.classList.toggle( 'is-loading', on );
        status.textContent = text;
    };

    slots.forEach( slot => slot.addEventListener( 'click', () => {
        if ( slot.disabled ) { return; }
        slots.forEach( s => s.setAttribute( 'aria-checked', s === slot ? 'true' : 'false' ) );
        sessionInput.value = slot.dataset.session;
        showError( 'session_id', '' );
        refreshSummary();
    } ) );
    form.addEventListener( 'input', refreshSummary );
    refreshSummary();

    form.addEventListener( 'submit', async ( e ) => {
        e.preventDefault();
        if ( busy ) { return; }

        clearErrors();
        showFormError( '' );
        const errors = validateForm( collect() );
        if ( Object.keys( errors ).length ) {
            Object.entries( errors ).forEach( ( [ field, text ] ) => showError( field, text ) );
            return;
        }

        setBusy( true, 'Занимаем место…' );
        const data = { ...collect(), request_key: requestKey, form_token: value( 'form_token' ) };
        data[ form.querySelector( '.fs-exam-signup__hp input' ).name ] = form.querySelector( '.fs-exam-signup__hp input' ).value;

        try {
            const res = await post( vars, vars.actions.submit, data );
            if ( res.success ) {
                window.location.assign( res.data.redirect );
                return;
            }

            const err = res.data || {};
            if ( err.cabinet_url ) { window.location.assign( err.cabinet_url ); return; }
            if ( err.new_request_key ) { requestKey = newRequestKey(); }
            if ( err.field ) { showError( err.field, err.message ); } else { showFormError( err.message || 'Не удалось отправить форму. Попробуйте ещё раз.' ); }
        } catch {
            // Сеть упала: тот же request_key — повтор вернёт ту же бронь, а не вторую.
            showFormError( 'Нет связи. Проверьте интернет и нажмите ещё раз — место не потеряется.' );
        }
        setBusy( false );
    } );
}

/** Карусель: стрелки листают на карточку, на краях недоступны; перетаскивание выбор не меняет (клик после сдвига глотается). */
function initCarousel( form ) {
    const root = form.querySelector( '#fs-exam-carousel' );
    if ( ! root ) { return; }

    const track = root.querySelector( '.fs-exam-carousel__track' );
    const prev = root.querySelector( '.fs-exam-carousel__arrow--prev' );
    const next = root.querySelector( '.fs-exam-carousel__arrow--next' );
    const step = () => { const card = track.querySelector( '.fs-exam-slot' ); return card ? card.getBoundingClientRect().width + 16 : track.clientWidth; };
    const sync = () => {
        prev.disabled = track.scrollLeft <= 1;
        next.disabled = track.scrollLeft + track.clientWidth >= track.scrollWidth - 1;
    };

    prev.addEventListener( 'click', () => track.scrollBy( { left: -step(), behavior: 'smooth' } ) );
    next.addEventListener( 'click', () => track.scrollBy( { left: step(), behavior: 'smooth' } ) );
    track.addEventListener( 'scroll', sync );
    window.addEventListener( 'resize', sync );

    let startX = 0;
    let moved = false;
    track.addEventListener( 'pointerdown', e => { startX = e.clientX; moved = false; } );
    track.addEventListener( 'pointermove', e => { if ( e.buttons && Math.abs( e.clientX - startX ) > 6 ) { moved = true; } } );
    track.addEventListener( 'click', e => { if ( moved ) { e.stopPropagation(); e.preventDefault(); moved = false; } }, true );
    sync();
}

/* ── Обратный отсчёт брони (корзина, оформление) ─────────────────────── */
function initHoldCountdown() {
    document.querySelectorAll( '[data-exam-hold]' ).forEach( box => {
        const serverSeconds = Number( box.dataset.secondsLeft );
        const started = Date.now();
        const out = box.querySelector( '[data-exam-hold-left]' );
        const tick = () => {
            const left = secondsLeft( serverSeconds, Date.now() - started );
            out.textContent = formatLeft( left );
            if ( left <= 0 ) {
                window.clearInterval( timer );
                box.classList.add( 'is-expired' );
                out.textContent = 'время вышло';
            }
        };
        const timer = window.setInterval( tick, 1000 );
        tick();
    } );
}

/* ── «Проверить статус» на странице «Спасибо» ────────────────────────── */
function initOrderStatus( vars ) {
    const root = document.querySelector( '[data-exam-order]' );
    if ( ! root ) { return; }

    root.addEventListener( 'click', async ( e ) => {
        const btn = e.target.closest( '[data-exam-check]' );
        if ( ! btn ) { return; }

        btn.disabled = true;
        try {
            const res = await post( vars, vars.actions.check_status, { order_id: root.dataset.examOrder, key: root.dataset.examKey } );
            if ( res.success ) { window.location.reload(); return; }
        } catch { /* остаётся кнопка: можно нажать ещё раз */ }
        btn.disabled = false;
    } );
}
