/**
 * @fileoverview Что происходило с гостевой формой записи на экзамен до отправки.
 *
 * События уходят в журналы «Аутентификация» и «Экзамены» (AjaxHook::TrackExamSignupForm): started — первый
 * ввод, invalid — отправка не прошла проверку в браузере, submit_failed — сервер отказал или не ответил,
 * left — страницу закрыли, не завершив запись. Значения полей не отправляются (там ПД) — только имена
 * полей и счётчики. У формы нет капчи, поэтому событий капчи здесь нет.
 *
 * Записи одного открытия формы связывает ID визита: его же получает запрос отправки (visit).
 *
 * Глобальные переменные: fs_lms_exam_signup_vars (ajax_url, actions.track, nonce).
 */

import { TRAP_SELECTOR } from './form-trap.js';
import { createFormTracker, NOOP_TRACKER } from './form-tracker.js';

/** ID визита: связывает события одной сессии формы; сервер принимает a-z0-9, до 16 символов. */
const VISIT = Math.random().toString( 36 ).slice( 2, 10 );

/** @returns {string} ID визита для запросов формы. */
export function examVisitId() {
    return VISIT;
}

/**
 * Сколько полей заполнено: обычные поля — по значению, согласия — по отметке, дата и время — по выбору.
 *
 * @param {HTMLFormElement} form
 * @returns {{ total: number, filled: number }}
 */
function fieldStats( form ) {
    const inputs = [ ...form.querySelectorAll( 'input:not([type="hidden"]):not([type="checkbox"]), select, textarea' ) ]
        .filter( ( input ) => input.name && ! input.matches( TRAP_SELECTOR ) );
    const consents = [ ...form.querySelectorAll( 'input[type="checkbox"]' ) ];
    const session  = form.querySelector( '#fs_exam_session_id' );

    const filled = inputs.filter( ( input ) => '' !== input.value.trim() ).length
        + consents.filter( ( input ) => input.checked ).length
        + ( session && '' !== session.value.trim() && '0' !== session.value ? 1 : 0 );

    return { total: inputs.length + consents.length + ( session ? 1 : 0 ), filled };
}

/**
 * Имена полей, у которых сейчас показана ошибка (validation-manager ставит .form-invalid на группу).
 *
 * @param {HTMLFormElement} form
 * @returns {string[]}
 */
function invalidFieldNames( form ) {
    return [ ...form.querySelectorAll( '.form-invalid' ) ]
        .map( ( group ) => {
            const name = group.dataset.field || group.querySelector( 'input[name], select[name], textarea[name]' )?.name || '';
            return name.replace( /\[\]$/, '' );
        } )
        .filter( Boolean );
}

/**
 * @param {HTMLFormElement} form
 * @param {{ ajax_url: string, actions: { track?: string }, nonce: string }} vars
 */
export function createExamSignupTracker( form, vars ) {
    if ( ! vars?.actions?.track || ! vars?.nonce ) { return NOOP_TRACKER; }

    return createFormTracker( form, {
        ajaxUrl:             vars.ajax_url,
        params:              () => ( { action: vars.actions.track, security: vars.nonce, visit: VISIT } ),
        fieldStats,
        invalidFieldNames,
        leftRequiresStarted: true,
        credentials:         'same-origin',
    } );
}
