/**
 * Чистые функции формы записи гостя на экзамен (этап 11a.3): резюме перед оплатой, выбор сеанса, таймер брони.
 * Без DOM — проверяются тестами (`tests/js/exam-signup.test.mjs`). Серверные правила те же и обязательны независимо от клиента.
 */

/** Сеанс не выбран автоматически: без явного выбора отправка невозможна. */
export function isSessionChosen( sessionId ) {
    return Number( sessionId ) > 0;
}

const MONTHS = [ 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря' ];

/** «2026-03-12» → «12 марта 2026». */
export function formatDateLong( iso ) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec( String( iso || '' ) );
    return m ? `${ Number( m[3] ) } ${ MONTHS[ Number( m[2] ) - 1 ] } ${ m[1] }` : '—';
}

/**
 * Резюме перед оплатой («ключ — значение»): участник как введено, дата с годом и днём недели, время.
 * Направление, адрес и цена приходят с сервера и в резюме не пересчитываются.
 *
 * @param {{last_name?:string, first_name?:string, middle_name?:string}} form
 * @param {{date:string, weekday:string, time:string}|null} slot
 * @returns {{name:string, date:string, time:string}}
 */
export function buildSummary( form, slot ) {
    const name = [ form.last_name, form.first_name, form.middle_name ].map( s => String( s || '' ).trim() ).filter( Boolean ).join( ' ' );
    return {
        name: name || '—',
        date: slot ? `${ formatDateLong( slot.date ) }, ${ slot.weekday }` : '—',
        time: slot ? slot.time : '—',
    };
}

/** «мм:сс» из секунд; не меньше нуля. */
export function formatLeft( total ) {
    const s = Math.max( 0, Math.floor( total ) );
    return `${ String( Math.floor( s / 60 ) ).padStart( 2, '0' ) }:${ String( s % 60 ).padStart( 2, '0' ) }`;
}

/** Секунд до конца брони: серверное значение минус прошедшее с момента получения (часы клиента дедлайн не определяют). */
export function secondsLeft( serverSeconds, elapsedMs ) {
    return Math.max( 0, Number( serverSeconds ) - Math.floor( elapsedMs / 1000 ) );
}
