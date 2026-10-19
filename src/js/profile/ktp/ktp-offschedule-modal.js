/* ══════════════════════════════════════════════════════════════════════
   Этап 4 (Tasks.md): модалка урока вне расписания. Тот же drag-drop, что
   и закрепление на день со слотом (ktp.js:attachDrop), но день без штатной
   встречи группы — занятия в этот день нет, урок просто откроется ученикам
   в выбранное время. Паттерн — indi-modal.js: общий поповер #profGradePop,
   позиционирование от ячейки-дня (anchor).

   Индивидуальная группа (`individual`): штатного расписания нет, любой день —
   обычный, поэтому предупреждения нет, а с `dateEditable` в модалке можно сменить
   дату — это перенос занятия (в т.ч. уже начавшегося).
   ══════════════════════════════════════════════════════════════════════ */

import { toast, openGradePopPositioned, closeGradePop } from '../utils.js';
import { icoAlert } from '../../common/icons.js';

/**
 * @param {Object}   o
 * @param {Element}  o.anchor      Ячейка календаря, от которой позиционируется поповер
 * @param {string}   o.day         'YYYY-MM-DD' — день без слота в расписании (или текущий день занятия)
 * @param {string}   o.defaultTime 'HH:MM' — дефолт (время ближайшей встречи группы)
 * @param {string}   [o.defaultEnd] 'HH:MM' — дефолт конца (иначе начало + 1 час)
 * @param {boolean}  [o.individual] Индивидуальная группа: штатного расписания нет — без предупреждения «занятия нет»
 * @param {boolean}  [o.dateEditable] Показать поле даты (перенос занятия на другой день)
 * @param {string}   [o.title]     Заголовок (по умолчанию — «Урок вне расписания»)
 * @param {string}   [o.saveLabel] Подпись кнопки (по умолчанию — «Открыть урок»)
 * @param {Function} o.onConfirm   ({ day, scheduledAt, endsAt }) => void
 */
export function openOffScheduleModal( o ) {
    const pop = document.getElementById( 'profGradePop' );
    if ( ! pop ) { return; }

    const initTime = o.defaultTime || '15:00';
    const initTimeEnd = o.defaultEnd || addHour( initTime );
    const title = o.title || 'Урок вне расписания';
    const saveLabel = o.saveLabel || 'Открыть урок';

    pop.innerHTML = `
        <div class="gp-form gp-offsched">
        <div class="gp-title">${ title }</div>
        ${ o.individual ? '' : `<div class="gp-warn">${ icoAlert( 15 ) }<span>В этот день занятия нет. Урок просто откроется ученикам — выберите время.</span></div>` }
        ${ o.dateEditable ? `<div class="gp-field"><span>Дата</span><input type="date" id="osDay" value="${ o.day }"></div>` : '' }
        <div class="gp-field"><span>Время</span><div class="gp-time"><input type="time" id="osTime" value="${ initTime }"><span class="gp-dash">–</span><input type="time" id="osTimeEnd" value="${ initTimeEnd }"></div></div>
        <div class="gp-row">
            <button class="prof-btn prof-btn-sm prof-btn-primary" data-os="save">${ saveLabel }</button>
            <button class="prof-btn prof-btn-sm" data-os="cancel">Отмена</button>
        </div>
        </div>`;

    const $ = sel => pop.querySelector( sel );

    $( '[data-os="cancel"]' ).addEventListener( 'click', closeGradePop );
    $( '[data-os="save"]' ).addEventListener( 'click', () => {
        const day = o.dateEditable ? $( '#osDay' ).value : o.day;
        const time = $( '#osTime' ).value;
        const timeEnd = $( '#osTimeEnd' ).value;
        if ( ! day ) { toast( 'Укажите дату', 'error' ); return; }
        if ( ! time || ! timeEnd ) { toast( 'Укажите время', 'error' ); return; }
        if ( timeEnd <= time ) { toast( 'Время окончания должно быть позже начала', 'error' ); return; }
        closeGradePop();
        o.onConfirm( { day, scheduledAt: `${ day } ${ time }:00`, endsAt: `${ day } ${ timeEnd }:00` } );
    } );

    openGradePopPositioned( pop, o.anchor );
}

/** '15:00' → '16:00' — дефолт времени окончания (начало + 1 час). */
function addHour( time ) {
    const [ h, m ] = String( time ).split( ':' ).map( Number );
    if ( Number.isNaN( h ) ) { return '16:00'; }
    return `${ String( ( h + 1 ) % 24 ).padStart( 2, '0' ) }:${ String( m || 0 ).padStart( 2, '0' ) }`;
}
