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

const NOOP_TRACKER = {
    submitAttempt() {},
    invalid() {},
    failed() {},
    succeeded() {},
};

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
export function createJoinTracker( form ) {
    if ( ! vars?.actions?.track || ! vars?.nonces?.track ) { return NOOP_TRACKER; }

    const joinCode = form.querySelector( '[name="join_code"]' )?.value ?? '';
    const openedAt = Date.now();
    let submits    = 0;
    let started    = false;
    let finished   = false;
    let leftSent   = false;

    /**
     * @param {string}                  event
     * @param {{ fields?: string[], message?: string }} extra
     */
    const send = ( event, extra = {} ) => {
        const { total, filled } = fieldStats( form );
        const body = new URLSearchParams( {
            action:    vars.actions.track,
            security:  vars.nonces.track,
            join_code: joinCode,
            visit:     vars.visit ?? '',
            event,
            seconds:   String( Math.round( ( Date.now() - openedAt ) / 1000 ) ),
            submits:   String( submits ),
            filled:    String( filled ),
            total:     String( total ),
            message:   extra.message ?? '',
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
        succeeded() {
            finished = true;
        },
    };
}
