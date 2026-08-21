/* ══════════════════════════════════════════════════════════════════════
   КТП: HTML-шаблоны карточек и состояний. Без DOM-манипуляций и стейта:
   всё нужное (флаги, выбранный слот) приходит параметрами от ядра.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, emptyState } from '../utils.js';
import { icoGrip, icoPinFilled, icoCalendarBoard, icoAlert, icoCamera, icoCalendar } from '../../common/icons.js';

/* T12.6 (D14): «1/2 · 2/2» — origin+continuation считаются одной темой. */
function partLabel(t) {
    return (t.total_parts && t.total_parts > 1) ? ` · ${t.part}/${t.total_parts}` : '';
}

export function themeCardHtml(t) {
    return `<div class="prof-theme-card" draggable="true" data-glid="${t.group_lesson_id}">
        <span class="tc-num">${t.n}${partLabel(t)}</span>
        <div class="tc-body">
            <div class="tc-title">${esc(t.topic || 'Без названия')}</div>
            <div class="tc-meta">${t.is_pinned ? '<span class="tc-pinned">закреплено</span>' : ''}</div>
        </div>
        <span class="tc-grip">${icoGrip(14)}</span>
    </div>`;
}

/** Индикатор записи занятия: зелёная камера — запись есть, красная — занятие прошло, записи нет.
    Модуль «Видеозаписи занятий» выключен/не настроен (videoEnabled=false) — фронт ведёт себя
    так, будто про записи занятий вообще не знает: ни иконки, ни ручной правки ссылки. */
function recordingIconHtml(t, videoEnabled) {
    if (!videoEnabled) {
        return '';
    }
    if (t.recording_url) {
        return `<button type="button" class="pt-recording pt-recording--ok" data-glid="${t.group_lesson_id}" data-url="${esc(t.recording_url)}" title="Есть запись занятия — изменить ссылку" aria-label="Запись занятия есть">${icoCamera(13, 'var(--ok)')}</button>`;
    }
    if ('held' === t.status) {
        return `<button type="button" class="pt-recording pt-recording--err" data-glid="${t.group_lesson_id}" data-url="" title="Занятие прошло, записи нет — добавить ссылку вручную" aria-label="Записи нет">${icoCamera(13, 'var(--err)')}</button>`;
    }
    return '';
}

export function placedThemeHtml(t, videoEnabled) {
    const pinned = t.is_pinned ? ' pinned' : '';
    const offSchedule = t.off_schedule ? ' off-schedule' : '';
    const roomTip = t.room ? ` · ${t.room}` : '';
    // T12.6: «Продолжить» доступно только для «родных» строк (part 1) — не для уже-продолжений.
    const canContinue = 1 === t.part;
    // #16: карточка урока = тема (жирная) / кабинет / преподаватель, друг под другом.
    // Номер убран; метка продолжения (part/total) прикреплена к теме.
    // Этап 2 (★): клик по карточке ведёт в плеер курса (teacher-режим) — карточка
    // кликабельна, только если у занятия есть контент (player_url из getCalendar).
    // Этап 4: урок вне расписания (нет штатного слота в этот день) — иначе неотличим
    // от планового занятия.
    return `<div class="placed-theme${pinned}${offSchedule}" draggable="true" data-glid="${t.group_lesson_id}" title="${esc(t.topic)}${esc(roomTip)}">
        <span class="pt-pin">${icoPinFilled(11)}</span>
        ${t.off_schedule ? `<span class="pt-off-schedule" title="Урок вне расписания — открывается ученикам отдельно">${icoAlert(11)}</span>` : ''}
        <button type="button" class="pt-deadlines" data-glid="${t.group_lesson_id}" title="Дедлайны работ" aria-label="Дедлайны работ">${icoCalendar(12)}</button>
        <span class="pt-title">${esc(t.topic || 'Без названия')}${partLabel(t)}</span>
        ${t.room ? `<span class="pt-meta">${esc(t.room)}</span>` : ''}
        ${t.teacher ? `<span class="pt-meta">${esc(t.teacher)}</span>` : ''}
        ${recordingIconHtml(t, videoEnabled)}
        ${canContinue ? `<button type="button" class="pt-more" data-glid="${t.group_lesson_id}" aria-label="Действия">⋮</button>` : ''}
    </div>`;
}

/* Эпик 15: открытая группа — программа опубликована целиком и доступна ученикам
   сразу; дат/drag-drop/публикации нет, показываем темы курса списком. */
export function openProgramHtml(themes) {
    return `
        <div class="prof-theme-bank ktp-open-program">
            <div class="tb-head">
                <h3>Программа курса</h3>
                <span class="tbh-count">${themes.length} тем · открыто ученикам сразу, расписание не ведётся</span>
            </div>
            <div class="prof-theme-list">${themes.length
                ? themes.map(themeCardHtml).join('')
                : '<div class="tb-empty">В курсе нет уроков.</div>'}</div>
        </div>`;
}

/** Чип слота инд. занятия; selectedGlid — выбранный в календаре слот (подсветка). */
export function indiSlotChip(it, selectedGlid) {
    const t = String(it.scheduled_at || '').split(' ')[1];
    const time = t ? t.slice(0, 5) : '';
    const has = !!it.lesson_id;
    const sel = String(it.group_lesson_id) === String(selectedGlid) ? ' selected' : '';
    const sub = has ? esc(it.topic || 'Без названия') : 'Урок не назначен';
    return `<div class="placed-theme indi-slot${has ? '' : ' unassigned'}${sel}" data-glid="${it.group_lesson_id}" title="${esc(it.student_name || '')} · ${esc(sub)}">
        <button class="indi-edit" data-glid="${it.group_lesson_id}" title="Изменить занятие" aria-label="Изменить">✎</button>
        <span class="pt-num">${esc(time)} · ${esc(it.student_name || '—')}</span>
        <span class="pt-title">${sub}</span>
    </div>`;
}

/* ── States ───────────────────────────────────────────────────────────── */
export function emptyStateHtml(g) {
    return `<div class="prof-ktp-empty">
        <div class="ke-ico">
            ${icoCalendarBoard(34)}
        </div>
        <h3>Для группы ${esc(g.name)} не назначен курс</h3>
        <p>Выберите курс предмета — появятся темы и календарь для распределения.</p>
        <div class="ke-assign">
            <select id="ktpCourseSel" class="ke-course-sel"><option value="">— загрузка курсов… —</option></select>
            <button class="prof-btn prof-btn-primary" id="ktpAssignBtn" disabled>Назначить курс</button>
        </div>
    </div>`;
}

export function noGroupsHtml() {
    return emptyState('prof-ktp', icoCalendarBoard(34), 'Нет групп', 'За вами пока не закреплены группы.');
}

export function errorHtml(msg) {
    return emptyState('prof-ktp', icoAlert(30), 'Не удалось загрузить КТП', msg || '', true);
}
