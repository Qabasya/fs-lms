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
import { TRAP_SELECTOR } from './form-trap.js';
import { createFormTracker, NOOP_TRACKER } from './form-tracker.js';

/** @type {{ ajax_url: string, actions: { track?: string }, nonces: { track?: string } }|undefined} */
const vars = window.fs_lms_apply_vars;

/** ID визита: связывает события одной сессии формы; сервер принимает a-z0-9, до 16 символов. */
const VISIT = Math.random().toString( 36 ).slice( 2, 10 );

/** @returns {string} ID визита для запросов формы. */
export function applyVisitId() {
    return VISIT;
}

/**
 * @param {HTMLFormElement} form
 */
function fieldStats( form ) {
    const inputs = [ ...form.querySelectorAll( 'input:not([type="hidden"]), select, textarea' ) ]
        .filter( ( input ) => input.name && ! input.matches( TRAP_SELECTOR ) );

    const filled = inputs.filter( ( input ) => '' !== input.value.trim() ).length;

    return { total: inputs.length, filled };
}

/**
 * @param {HTMLFormElement} form
 */
export function createApplyTracker( form ) {
    if ( ! vars?.actions?.track || ! vars?.nonces?.track ) { return { ...NOOP_TRACKER, stage() {} }; }

    let stage = 'form';

    const tracker = createFormTracker( form, {
        ajaxUrl:    vars.ajax_url,
        params:     () => ( {
            action:   vars.actions.track,
            security: vars.nonces.track,
            visit:    VISIT,
            stage,
            captcha:  '',
        } ),
        fieldStats,
    } );

    onCaptchaEvent( ( event, detail ) => {
        if ( 'shown' === event ) { tracker.send( 'captcha_shown' ); }
        if ( 'failed' === event ) { tracker.send( 'captcha_failed', { captcha: detail } ); }
    } );

    return {
        ...tracker,
        /** @param {'form'|'otp'} next Этап, на который перешла форма. */
        stage( next ) {
            stage = next;
        },
    };
}
