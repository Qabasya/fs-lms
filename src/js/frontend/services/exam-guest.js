/**
 * Страницы гостя экзамена (этап 11b.4): «Завершить сеанс» и защита от возврата «Назад».
 *
 * После завершения содержимое блока очищается, а `location.replace()` не оставляет страницу результата в истории. Если браузер всё же
 * вернул её из кеша переходов (bfcache, `pageshow` с `persisted`), страница перезагружается: сервер при каждом запросе проверяет сессию
 * и без неё отдаёт 404. Данные ни в `localStorage`, ни в `sessionStorage` не кладутся.
 */

import { shouldReloadOnPageshow } from './exam-guest-model.js';

function post( vars, data ) {
    const body = new URLSearchParams( data );
    body.append( 'action', vars.actions.end_session );
    body.append( 'security', vars.nonce );

    return fetch( vars.ajax_url, { method: 'POST', credentials: 'same-origin', body } ).then( r => r.json() );
}

export function initExamGuest() {
    const vars = window.fs_lms_exam_guest_vars;
    const root = document.getElementById( 'fs-exam-result' ) || document.getElementById( 'fs-exam-entry' );
    if ( ! vars || ! root ) { return; }

    window.addEventListener( 'pageshow', event => {
        if ( shouldReloadOnPageshow( event ) ) { window.location.reload(); }
    } );

    const button = root.querySelector( '[data-exam-end-session]' );
    if ( ! button ) { return; }

    button.addEventListener( 'click', async () => {
        button.disabled = true;
        try {
            const response = await post( vars, {} );
            if ( ! response.success ) {
                button.disabled = false;
                button.textContent = ( response.data && response.data.message ) || 'Не удалось завершить сеанс';
                return;
            }
            root.innerHTML = '';
            window.location.replace( response.data.url );
        } catch ( e ) {
            button.disabled = false;
            button.textContent = 'Не удалось завершить сеанс';
        }
    } );
}
