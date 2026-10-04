/* ══════════════════════════════════════════════════════════════════════
   Общее для экранов экзаменов сотрудника (этап 4): конфиг, выбранный предмет,
   пикер предмета, пустые состояния, пилюля статуса, чистые помощники времени.
   Сеть — только через createApi(window.fsProfile.exams) в самих экранах.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, emptyState } from '../utils.js';
import { subjectPickerBtnHtml, openSubjectPicker } from '../picker.js';
import { icoCalendarBoard } from '../../common/icons.js';

/** Ширина, до которой кабинет считается телефоном: перетаскивания нет, сеанс добавляется кнопкой. */
export const PHONE_MAX_WIDTH = 720;

let subjectKey = '';

/** Блок конфига экзаменов сотрудника (`window.fsProfile.exams`) или null. */
export function examConfig() {
    return (window.fsProfile && window.fsProfile.exams) || null;
}

/** Предметы, по которым сотрудник вправе назначать экзамены: `[{key, name}]`. */
export function examSubjects() {
    const cfg = examConfig();
    return (cfg && Array.isArray(cfg.subjects)) ? cfg.subjects : [];
}

/** Выбранный предмет; если выбранного нет среди доступных — первый из них (пустая строка, если предметов нет). */
export function currentSubject() {
    const subjects = examSubjects();
    if (!subjects.some(s => s.key === subjectKey)) { subjectKey = subjects.length ? subjects[0].key : ''; }
    return subjectKey;
}

export function setCurrentSubject(key) {
    subjectKey = key;
}

/** Выбор предмета скрыт, если предмет один (SPEC §2): нечего выбирать. */
export function subjectPickerHtml(subjects, current, btnId = 'examSubjectBtn') {
    if (!subjects || subjects.length < 2) { return ''; }
    const subject = subjects.find(s => s.key === current) || subjects[0];
    return `<div class="prof-ktp-pick"><span class="kp-label">Предмет</span>${subjectPickerBtnHtml(subject, btnId)}</div>`;
}

/** Вешает меню выбора предмета на кнопку из subjectPickerHtml(); onPick получает ключ (только при смене). */
export function wireSubjectPicker(btnId, subjects, current, onPick) {
    const btn = document.getElementById(btnId);
    if (btn) { btn.onclick = () => openSubjectPicker(btn, subjects, current, onPick); }
}

/** Нет предметов, по которым можно назначить экзамен. */
export function noSubjectsHtml() {
    return emptyState('prof-ktp', icoCalendarBoard(34), 'Нет предметов', 'Нет предметов, по которым можно назначить экзамен.');
}

/** Пилюля статуса проведения: опубликовано — зелёная, черновик — синяя, остальное — серая. */
export function statusPillHtml(status, label) {
    const cls = 'published' === status ? 'prof-state-now' : ('draft' === status ? 'prof-state-soon' : 'prof-state-done');
    return `<span class="prof-state-pill ${cls}">${esc(label)}</span>`;
}

/** Телефонная ширина: сеанс добавляется кнопкой, перетаскивание не включается. */
export function isPhone() {
    return window.matchMedia(`(max-width: ${PHONE_MAX_WIDTH}px)`).matches;
}

/**
 * Время окончания: «10:00» + 235 минут → «13:55». Через полночь переходит на следующие сутки (по модулю 24 ч);
 * сеансы заканчиваются в тот же день, но функция не должна выдавать «25:10».
 *
 * @param {string} start    «HH:MM».
 * @param {number} minutes  Длительность, мин.
 * @returns {string} «HH:MM» или пустая строка, если время не разобралось.
 */
export function endTime(start, minutes) {
    const match = /^(\d{1,2}):(\d{2})$/.exec(String(start || ''));
    if (!match || !Number.isFinite(minutes)) { return ''; }
    const total = (Number(match[1]) * 60 + Number(match[2]) + minutes) % (24 * 60);
    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}

/** «2026-03-02 09:00:00» → «2026-03-02T09:00» (значение `datetime-local`); пусто остаётся пустым. */
export function toDateTimeLocal(value) {
    return value ? String(value).slice(0, 16).replace(' ', 'T') : '';
}

/** Сеансы дня по дате `Y-m-d`: сеансы проведения, сгруппированные для ячейки календаря, по времени начала. */
export function sessionsByDate(sessions) {
    const byDate = {};
    (sessions || []).forEach(s => { (byDate[s.date] = byDate[s.date] || []).push(s); });
    Object.values(byDate).forEach(list => list.sort((a, b) => String(a.time_start).localeCompare(String(b.time_start))));
    return byDate;
}

/**
 * Попадает ли дата в период проведения (включительно). Выходные не исключаются: экзамен может идти в субботу.
 *
 * @param {string} date `Y-m-d`.
 * @param {{period_from:string, period_to:string}|null} event
 */
export function inPeriod(date, event) {
    return !!event && date >= event.period_from && date <= event.period_to;
}

/**
 * Проверка формы проведения до отправки (дублирует серверную, не заменяет).
 *
 * @param {{title:string, period_from:string, period_to:string, registration_opens_at:string, registration_closes_at:string}} v
 * @returns {Object<string, string>} Ошибки по имени поля; пусто — ошибок нет.
 */
export function validateEventForm(v) {
    const errors = {};
    if (!String(v.title || '').trim()) { errors.title = 'Укажите название проведения.'; }
    if (!v.period_from || !v.period_to) {
        errors.period_from = 'Укажите даты проведения.';
    } else if (v.period_from > v.period_to) {
        errors.period_from = 'Дата начала позже даты окончания.';
    }
    if (v.registration_opens_at && v.registration_closes_at && v.registration_opens_at > v.registration_closes_at) {
        errors.registration_opens_at = 'Открытие записи позже её закрытия.';
    }
    return errors;
}
