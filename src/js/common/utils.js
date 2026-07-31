/**
 * @fileoverview Общие утилиты всех бандлов: экранирование, форматирование дат,
 * инициалы и debounce.
 *
 * @module common/utils
 * @description Раньше эти функции жили копиями в admin/profile/player/frontend
 * (аудит §2.8: escapeHtml — 11 реализаций, fmtDate — 4, debounce — 3). Здесь
 * канонические версии; доменные бандлы реэкспортируют их под своими именами,
 * чтобы не ломать существующие импорты.
 */

/**
 * Экранирует строку для безопасной вставки в HTML.
 *
 * @param {*} value Исходное значение (приводится к строке).
 * @return {string} Экранированная строка.
 */
export function escapeHtml( value ) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    };

    return String( value ).replace( /[&<>"']/g, ( ch ) => map[ ch ] );
}

/**
 * Дата 'YYYY-MM-DD…' → 'ДД.ММ.ГГГГ'.
 *
 * @param {string} iso Дата в ISO-формате.
 * @return {string} Пустая строка, если дата не передана.
 */
export function fmtDate( iso ) {
    if ( ! iso ) { return ''; }
    const parts = String( iso ).slice( 0, 10 ).split( '-' );

    return parts.length === 3 ? `${ parts[ 2 ] }.${ parts[ 1 ] }.${ parts[ 0 ] }` : iso;
}

/**
 * Дата 'YYYY-MM-DD…' → 'ДД.ММ'.
 *
 * @param {string} iso Дата в ISO-формате.
 * @return {string} Пустая строка, если дата не передана.
 */
export function fmtDayMonth( iso ) {
    if ( ! iso ) { return ''; }
    const parts = String( iso ).slice( 0, 10 ).split( '-' );

    return parts.length === 3 ? `${ parts[ 2 ] }.${ parts[ 1 ] }` : iso;
}

/**
 * Дата и время 'YYYY-MM-DD HH:MM…' → 'ДД.ММ.ГГГГ ЧЧ:ММ'.
 *
 * @param {string} iso Дата-время.
 * @return {string} Пустая строка, если значение не передано.
 */
export function fmtDateTime( iso ) {
    if ( ! iso ) { return ''; }

    return `${ fmtDate( iso ) } ${ String( iso ).slice( 11, 16 ) }`.trim();
}

/**
 * Сегодняшняя дата в формате 'YYYY-MM-DD'.
 *
 * @return {string} Дата.
 */
export function todayIso() {
    return new Date().toISOString().slice( 0, 10 );
}

/**
 * Инициалы из ФИО: «Иванова Софья» → «ИС».
 *
 * @param {string} name Полное имя.
 * @return {string} Не более двух заглавных букв.
 */
export function initials( name ) {
    return String( name )
        .split( /\s+/ )
        .filter( Boolean )
        .slice( 0, 2 )
        .map( ( part ) => part[ 0 ].toUpperCase() )
        .join( '' );
}

/**
 * Откладывает вызов до паузы в событиях.
 *
 * @param {Function} fn Функция.
 * @param {number}   ms Задержка в миллисекундах.
 * @return {Function} Обёртка с отложенным вызовом.
 */
export function debounce( fn, ms ) {
    let timer;

    return ( ...args ) => {
        clearTimeout( timer );
        timer = setTimeout( () => fn.apply( null, args ), ms );
    };
}

/**
 * Показывает или скрывает элемент атрибутом `hidden`.
 *
 * Инлайновые стили в JS запрещены (CLAUDE.md), поэтому видимость переключается
 * атрибутом, а не `style.display`. Элемента может не быть — тогда вызов no-op.
 *
 * @param {Element|null} el      Элемент.
 * @param {boolean}      visible Показать (true) или скрыть (false).
 * @return {void}
 */
export function toggleVisible( el, visible ) {
    if ( el ) {
        el.hidden = ! visible;
    }
}
