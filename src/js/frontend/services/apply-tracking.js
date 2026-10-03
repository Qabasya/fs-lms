/**
 * @fileoverview Что происходило с формой заявки (/lms/apply) до отправки кода.
 *
 * События уходят в журнал «Аутентификация» (AjaxHook::TrackApplyForm): started — первый
 * ввод, invalid — отправка не прошла проверку, captcha_shown — капча показала задание,
 * captcha_failed — токен не получен (не загрузилась / не открылась / сеть / закрыли;
 * из-за VPN или блокировщика), submit_failed — сервер отказал или не ответил,
 * left — страницу закрыли, не завершив заявку.
 * Значения полей не отправляются (там ПД) — только имена полей и счётчики.
 *
 * Заявки к этому моменту ещё нет, поэтому записи связывает ID визита: его же получает
 * запрос отправки кода (visit), и он виден в записи об OTP.
 *
 * Глобальные переменные: fs_lms_apply_vars (actions.track, nonces.track).
 */

import { onCaptchaEvent } from './captcha.js';

/** @type {{ ajax_url: string, hp_field?: string, actions: { track?: string }, nonces: { track?: string } }|undefined} */
const vars = window.fs_lms_apply_vars;

/** ID визита: связывает события одной сессии формы; сервер принимает a-z0-9, до 16 символов. */
const VISIT = Math.random().toString( 36 ).slice( 2, 10 );

/** @returns {string} ID визита для запросов формы. */
export function applyVisitId() {
    return VISIT;
}

const NOOP_TRACKER = {
    submitAttempt() {},
    invalid() {},
    failed() {},
    stage() {},
    succeeded() {},
};

/**
 * @param {HTMLFormElement} form
 */
function fieldStats( form ) {
    const inputs = [ ...form.querySelectorAll( 'input:not([type="hidden"]), select, textarea' ) ]
        .filter( ( input ) => input.name && ( vars?.hp_field || 'fs_company' ) !== input.name );

    const filled = inputs.filter( ( input ) => '' !== input.value.trim() ).length;

    return { total: inputs.length, filled };
}

/**
 * Имена полей, у которых сейчас показана ошибка (validation-manager ставит .form-invalid на группу).
 *
 * @param {HTMLFormElement} form
 * @returns {string[]}
 */
function invalidFieldNames( form ) {
    return [ ...form.querySelectorAll( '.form-invalid' ) ]
        .map( ( group ) => group.querySelector( 'input[name], select[name], textarea[name]' )?.name )
        .filter( Boolean );
}

/**
 * @param {HTMLFormElement} form
 */
export function createApplyTracker( form ) {
    if ( ! vars?.actions?.track || ! vars?.nonces?.track ) { return NOOP_TRACKER; }

    const openedAt = Date.now();
    let submits  = 0;
    let started  = false;
    let finished = false;
    let leftSent = false;
    let stage    = 'form';

    /**
     * @param {string} event
     * @param {{ fields?: string[], message?: string, captcha?: string }} extra
     */
    const send = ( event, extra = {} ) => {
        const { total, filled } = fieldStats( form );
        const body = new URLSearchParams( {
            action:   vars.actions.track,
            security: vars.nonces.track,
            visit:    VISIT,
            event,
            stage,
            seconds:  String( Math.round( ( Date.now() - openedAt ) / 1000 ) ),
            submits:  String( submits ),
            filled:   String( filled ),
            total:    String( total ),
            message:  extra.message ?? '',
            captcha:  extra.captcha ?? '',
        } );
        ( extra.fields ?? [] ).forEach( ( name ) => body.append( 'fields[]', name ) );

        // keepalive — запрос доживает до конца даже при закрытии вкладки (событие left).
        fetch( vars.ajax_url, { method: 'POST', body, keepalive: true } ).catch( () => {} );
    };

    form.addEventListener( 'input', () => {
        if ( started ) { return; }
        started = true;
        send( 'started' );
    } );

    onCaptchaEvent( ( event, detail ) => {
        if ( 'shown' === event ) { send( 'captcha_shown' ); }
        if ( 'failed' === event ) { send( 'captcha_failed', { captcha: detail } ); }
    } );

    // pagehide — единственное событие, которое надёжно срабатывает при закрытии вкладки на телефоне.
    window.addEventListener( 'pagehide', () => {
        if ( finished || leftSent ) { return; }
        leftSent = true;
        send( 'left' );
    } );

    // Возврат из bfcache (кнопка «Назад») — визит продолжается.
    window.addEventListener( 'pageshow', ( event ) => {
        if ( event.persisted ) { leftSent = false; }
    } );

    return {
        submitAttempt() {
            submits++;
        },
        /** @param {string} [message] */
        invalid( message = '' ) {
            send( 'invalid', { fields: invalidFieldNames( form ), message } );
        },
        /** @param {string} [message] */
        failed( message = '' ) {
            send( 'submit_failed', { message } );
        },
        /** @param {'form'|'otp'} next Этап, на который перешла форма. */
        stage( next ) {
            stage = next;
        },
        succeeded() {
            finished = true;
        },
    };
}
