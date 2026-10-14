/**
 * Страницы гостя экзамена (этап 11b.4): «Завершить сеанс» и защита от возврата «Назад».
 *
 * После завершения содержимое блока очищается, а `location.replace()` не оставляет страницу результата в истории. Если браузер всё же
 * вернул её из кеша переходов (bfcache, `pageshow` с `persisted`), страница перезагружается: сервер при каждом запросе проверяет сессию
 * и без неё отдаёт 404. Данные ни в `localStorage`, ни в `sessionStorage` не кладутся.
 */

import { shouldReloadOnPageshow } from './exam-guest-model.js';
import { examStartPhase, msToNextPhase } from '../../common/exam-start-phase.js';

function post( vars, data ) {
    const body = new URLSearchParams( data );
    body.append( 'action', vars.actions.end_session );
    body.append( 'security', vars.nonce );

    return fetch( vars.ajax_url, { method: 'POST', credentials: 'same-origin', body } ).then( r => r.json() );
}

/** Школьный отчёт: после загрузки с раскрытым разбором страница прокручивается к блоку разбора. */
function initReportScroll() {
    const report = document.getElementById( 'fs-exam-report' );
    const review = report && '1' === report.dataset.review ? document.getElementById( 'fs-exam-report-review' ) : null;
    if ( review ) { review.scrollIntoView( { block: 'start' } ); }
}

/**
 * Страница входа: оповещение по моменту старта живёт без перезагрузки. Секунды до начала и конца окна приходят с сервера на
 * момент ответа (часы клиента момента не определяют); «далеко» → «скоро» — смена текста, «скоро» → «идёт» → «завершён» —
 * перезагрузка: сервер отдаёт кнопку входа со ссылкой или итоговый текст.
 */
function initEntryPhase() {
    const page = document.getElementById( 'fs-exam-entry' );
    const text = page ? page.querySelector( '[data-entry-phase]' ) : null;
    if ( ! page || ! text ) { return; }

    const loadedAt = Date.now();
    const startsIn = Number( page.dataset.startsIn );
    const endsIn = Number( page.dataset.endsIn );
    if ( ! Number.isFinite( startsIn ) || ! Number.isFinite( endsIn ) ) { return; }

    const tick = () => {
        const elapsed = ( Date.now() - loadedAt ) / 1000;
        const toStart = startsIn - elapsed;
        const toEnd = endsIn - elapsed;
        const phase = examStartPhase( toStart, toEnd );

        if ( 'running' === phase || 'ended' === phase ) {
            window.location.reload();
            return;
        }
        text.textContent = 'soon' === phase ? page.dataset.textSoon : page.dataset.textFar;

        const wait = msToNextPhase( toStart, toEnd );
        if ( null !== wait ) { window.setTimeout( tick, wait + 500 ); }
    };
    tick();
}

export function initExamGuest() {
    const page = document.getElementById( 'fs-exam-signup' ) || document.getElementById( 'fs-exam-entry' ) ||
        document.getElementById( 'fs-exam-result' ) || document.getElementById( 'fs-exam-report' );
    if ( ! page ) { return; }

    // Любой защищённый маршрут перепроверяет доступ после восстановления из bfcache, в том числе отчёт без AJAX-настроек.
    window.addEventListener( 'pageshow', event => {
        if ( shouldReloadOnPageshow( event ) ) { window.location.reload(); }
    } );

    initReportScroll();
    initEntryPhase();

    const vars = window.fs_lms_exam_guest_vars;
    const root = document.getElementById( 'fs-exam-result' ) || document.getElementById( 'fs-exam-entry' );
    if ( ! vars || ! root ) { return; }

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
