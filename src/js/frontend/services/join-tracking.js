/**
 * @fileoverview Что происходило с формой родителя (/lms/join/{code}) после открытия.
 *
 * События уходят в журнал «Зачисления» к заявке (AjaxHook::TrackJoinForm):
 * started — первый ввод, invalid — отправка не прошла проверку, submit_failed —
 * сервер отказал или не ответил, left — страницу закрыли, не отправив форму.
 * Значения полей не отправляются (там ПД) — только имена полей и счётчики.
 *
 * Глобальные переменные: fs_lms_join_vars (actions.track, nonces.track, visit).
 */

/** @type {{ ajax_url: string, actions: { track?: string }, nonces: { track?: string }, visit?: string }|undefined} */
const vars = window.fs_lms_join_vars;

import { createFormTracker, NOOP_TRACKER } from './form-tracker.js';

/**
 * @param {HTMLFormElement} form
 */
function fieldStats( form ) {
    const inputs = [ ...form.querySelectorAll( 'input:not([type="hidden"]), select, textarea' ) ]
        .filter( ( input ) => input.name );

    const filled = inputs.filter( ( input ) => (
        'checkbox' === input.type || 'radio' === input.type ? input.checked : '' !== input.value.trim()
    ) ).length;

    return { total: inputs.length, filled };
}

/**
 * @param {HTMLFormElement} form
 */
export function createJoinTracker( form ) {
    if ( ! vars?.actions?.track || ! vars?.nonces?.track ) { return NOOP_TRACKER; }

    const joinCode = form.querySelector( '[name="join_code"]' )?.value ?? '';

    return createFormTracker( form, {
        ajaxUrl:    vars.ajax_url,
        params:     () => ( {
            action:    vars.actions.track,
            security:  vars.nonces.track,
            join_code: joinCode,
            visit:     vars.visit ?? '',
        } ),
        fieldStats,
    } );
}
