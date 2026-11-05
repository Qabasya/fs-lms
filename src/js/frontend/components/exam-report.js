/**
 * Школьный отчёт по экзамену: клик по строке ученика открывает его работу под таблицей.
 *
 * Адрес строки лежит в `data-row-url` (сервер ставит тот же, что у ссылки «Результат и работа»).
 * UI-only, без AJAX.
 */

/** Класс открываемой строки (у заглушек адреса нет). */
const ROW_SELECTOR = 'tr[data-row-url]';

/**
 * Адрес строки для клика: null — клик не открывает работу (по ссылке внутри строки или вне строки).
 *
 * @param {EventTarget|null} target Цель клика.
 * @param {Element}          table  Таблица отчёта.
 * @returns {string|null}
 */
export function rowUrlFromClick( target, table ) {
    if ( ! target || 'function' !== typeof target.closest ) { return null; }
    // Клик по ссылке внутри строки пусть работает как ссылка.
    if ( target.closest( 'a' ) ) { return null; }

    const row = target.closest( ROW_SELECTOR );
    if ( ! row || ! table.contains( row ) ) { return null; }

    return row.getAttribute( 'data-row-url' );
}

/**
 * @returns {void}
 */
export function initExamReport() {
    const table = document.querySelector( '.fs-exam-report__table' );
    if ( ! table ) { return; }

    table.addEventListener( 'click', ( event ) => {
        const url = rowUrlFromClick( event.target, table );
        if ( null !== url ) { window.location.href = url; }
    } );
}
