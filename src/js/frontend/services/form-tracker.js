/**
 * @fileoverview Общее ядро трекеров публичных форм (заявка, регистрация родителя, запись на экзамен).
 *
 * Трекер шлёт в журнал, что происходило с формой: started — первый ввод, invalid — отправка не прошла
 * проверку в браузере, submit_failed — сервер отказал или не ответил, left — страницу закрыли, не
 * завершив форму. Значения полей не отправляются (там ПД) — только имена полей и счётчики.
 *
 * Чем формы различаются, приходит конфигом: адрес и параметры запроса, подсчёт заполненных полей и
 * список полей с ошибкой. Обёртки — apply-tracking.js, join-tracking.js, exam-signup-tracking.js.
 */

/** Трекер-заглушка: у формы нет настроек журнала. */
export const NOOP_TRACKER = {
    submitAttempt() {},
    invalid() {},
    failed() {},
    succeeded() {},
};

/**
 * Имена полей, у которых сейчас показана ошибка (validation-manager ставит .form-invalid на группу).
 *
 * @param {HTMLFormElement} form
 * @returns {string[]}
 */
export function invalidFieldNames( form ) {
    return [ ...form.querySelectorAll( '.form-invalid' ) ]
        .map( ( group ) => group.querySelector( 'input[name], select[name], textarea[name]' )?.name )
        .filter( Boolean );
}

/**
 * @param {HTMLFormElement} form
 * @param {object}   cfg
 * @param {string}   cfg.ajaxUrl                       Адрес admin-ajax.
 * @param {() => Object<string,string>} cfg.params     Параметры запроса (action, security, visit, stage…); считаются при каждой отправке.
 * @param {(form: HTMLFormElement) => { total: number, filled: number }} cfg.fieldStats
 * @param {(form: HTMLFormElement) => string[]} [cfg.invalidFieldNames] Поля с ошибкой (по умолчанию — {@link invalidFieldNames}).
 * @param {boolean}  [cfg.leftRequiresStarted]         «left» только после первого ввода.
 * @param {RequestCredentials} [cfg.credentials]       Режим cookie для fetch.
 */
export function createFormTracker( form, cfg ) {
    const openedAt = Date.now();
    const namesOf  = cfg.invalidFieldNames ?? invalidFieldNames;
    let submits  = 0;
    let started  = false;
    let finished = false;
    let leftSent = false;

    /**
     * @param {string} event
     * @param {{ fields?: string[], message?: string } & Object<string,any>} extra Остальные ключи уходят в запрос как есть.
     */
    const send = ( event, extra = {} ) => {
        const { fields = [], message = '', ...rest } = extra;
        const { total, filled } = cfg.fieldStats( form );
        const body = new URLSearchParams( {
            ...cfg.params(),
            event,
            seconds: String( Math.round( ( Date.now() - openedAt ) / 1000 ) ),
            submits: String( submits ),
            filled:  String( filled ),
            total:   String( total ),
            message,
            ...rest,
        } );
        fields.forEach( ( name ) => body.append( 'fields[]', name ) );

        // keepalive — запрос доживает до конца даже при закрытии вкладки (событие left).
        const init = { method: 'POST', body, keepalive: true };
        if ( cfg.credentials ) { init.credentials = cfg.credentials; }
        fetch( cfg.ajaxUrl, init ).catch( () => {} );
    };

    form.addEventListener( 'input', () => {
        if ( started ) { return; }
        started = true;
        send( 'started' );
    } );

    // pagehide — единственное событие, которое надёжно срабатывает при закрытии вкладки на телефоне.
    window.addEventListener( 'pagehide', () => {
        if ( finished || leftSent || ( cfg.leftRequiresStarted && ! started ) ) { return; }
        leftSent = true;
        send( 'left' );
    } );

    // Возврат из bfcache (кнопка «Назад») — визит продолжается.
    window.addEventListener( 'pageshow', ( event ) => {
        if ( event.persisted ) { leftSent = false; }
    } );

    return {
        send,
        submitAttempt() {
            submits++;
        },
        /** @param {string} [message] */
        invalid( message = '' ) {
            send( 'invalid', { fields: namesOf( form ), message } );
        },
        /**
         * @param {string} [message]
         * @param {string} [field] Поле, на которое указал сервер.
         */
        failed( message = '', field = '' ) {
            send( 'submit_failed', { message, fields: field ? [ field ] : [] } );
        },
        succeeded() {
            finished = true;
        },
    };
}
