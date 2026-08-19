/* ══════════════════════════════════════════════════════════════════════
   Этап 4 (Tasks.md): модалка урока вне расписания. Тот же drag-drop, что
   и закрепление на день со слотом (ktp.js:attachDrop), но день без штатной
   встречи группы — занятия в этот день нет, урок просто откроется ученикам
   в выбранное время. Паттерн — indi-modal.js: общий поповер #profGradePop,
   позиционирование от ячейки-дня (anchor).
   ══════════════════════════════════════════════════════════════════════ */

import { toast, openGradePopPositioned, closeGradePop } from '../utils.js';
import { icoAlert } from '../../common/icons.js';

/**
 * @param {Object}   o
 * @param {Element}  o.anchor      Ячейка календаря, от которой позиционируется поповер
 * @param {string}   o.day         'YYYY-MM-DD' — день без слота в расписании
 * @param {string}   o.defaultTime 'HH:MM' — дефолт (время ближайшей встречи группы)
 * @param {Function} o.onConfirm   ({ scheduledAt, endsAt }) => void
 */
export function openOffScheduleModal( o ) {
    const pop = document.getElementById( 'profGradePop' );
    if ( ! pop ) { return; }

    const initTime = o.defaultTime || '15:00';
    const initTimeEnd = addHour( initTime );

    pop.innerHTML = `
        <div class="gp-form gp-offsched">
        <div class="gp-title">Урок вне расписания</div>
        <div class="gp-warn">${ icoAlert( 15 ) }<span>В этот день занятия нет. Урок просто откроется ученикам — выберите время.</span></div>
        <div class="gp-field"><span>Время</span><div class="gp-time"><input type="time" id="osTime" value="${ initTime }"><span class="gp-dash">–</span><input type="time" id="osTimeEnd" value="${ initTimeEnd }"></div></div>
        <div class="gp-row">
            <button class="prof-btn prof-btn-sm prof-btn-primary" data-os="save">Открыть урок</button>
            <button class="prof-btn prof-btn-sm" data-os="cancel">Отмена</button>
        </div>
        </div>`;

    const $ = sel => pop.querySelector( sel );

    $( '[data-os="cancel"]' ).addEventListener( 'click', closeGradePop );
    $( '[data-os="save"]' ).addEventListener( 'click', () => {
        const time = $( '#osTime' ).value;
        const timeEnd = $( '#osTimeEnd' ).value;
        if ( ! time || ! timeEnd ) { toast( 'Укажите время', 'error' ); return; }
        if ( timeEnd <= time ) { toast( 'Время окончания должно быть позже начала', 'error' ); return; }
        closeGradePop();
        o.onConfirm( { scheduledAt: `${ o.day } ${ time }:00`, endsAt: `${ o.day } ${ timeEnd }:00` } );
    } );

    openGradePopPositioned( pop, o.anchor );
}

/** '15:00' → '16:00' — дефолт времени окончания (начало + 1 час). */
function addHour( time ) {
    const [ h, m ] = String( time ).split( ':' ).map( Number );
    if ( Number.isNaN( h ) ) { return '16:00'; }
    return `${ String( ( h + 1 ) % 24 ).padStart( 2, '0' ) }:${ String( m || 0 ).padStart( 2, '0' ) }`;
}
