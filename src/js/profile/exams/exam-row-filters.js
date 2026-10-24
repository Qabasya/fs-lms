/* ══════════════════════════════════════════════════════════════════════
   Фильтры списка участников экзамена: «Проведение экзамена» и «Статистика» (панель `exam-results-filters`, как в «Результатах»).
   Кого показывать (все / ученики / гости), поиск по ФИО, группа — для учеников, школа — для гостей.
   Строки приходят от сервера с полями `audience`, `name`, `group`, `school`; фильтры работают на клиенте.
   ══════════════════════════════════════════════════════════════════════ */

import { esc } from '../utils.js';
import { icoSearch } from '../../common/icons.js';

export const AUDIENCES = [
    { key: 'all', label: 'Все' },
    { key: 'student', label: 'Ученики' },
    { key: 'guest', label: 'Гости' },
];

/** Значение фильтра школы для самозаписанных гостей: у них школы нет, а пустое значение занято пунктом «Все школы». */
export const SELF_REGISTERED = '__self__';

/** Значение фильтра групп «Незаписавшиеся»: вместо группы показывает учеников направления без записи (только на экране проведения). */
export const UNREGISTERED = '__unregistered__';

const norm = s => String(s || '').toLowerCase().replace(/ё/g, 'е').trim();

/** Переключатель «Все / Ученики / Гости» (prof-seg); кнопки — `data-audience`. */
export function audienceSegHtml(current) {
    return `<div class="prof-seg" role="group" aria-label="Участники">${AUDIENCES.map(a =>
        `<button type="button" class="${current === a.key ? 'on' : ''}" data-audience="${a.key}">${esc(a.label)}</button>`).join('')}</div>`;
}

/** Поиск по ФИО: для учеников — по ученикам, для гостей — по гостям; при «Все» ищет по всем. */
export function searchHtml(audience, value, id) {
    const placeholder = 'guest' === audience ? 'Поиск по гостям' : ('student' === audience ? 'Поиск по ученикам' : 'Поиск по ФИО');
    return `<div class="stu-search exam-stats-search">
        <span class="stu-search-ico">${icoSearch(15)}</span>
        <input type="search" class="stu-search-input" id="${id}" placeholder="${esc(placeholder)}" value="${esc(value)}" autocomplete="off">
    </div>`;
}

const sortedUnique = values => [...new Set(values.filter(Boolean))].sort((a, b) => a.localeCompare(b, 'ru'));

/** Группы — когда показаны ученики (у гостей групп нет); варианты — из групп учеников в списке. */
export function groupSelectHtml(rows, audience, current, withUnregistered = false) {
    if ('guest' === audience) { return ''; }
    const groups = sortedUnique(rows.filter(r => 'student' === r.audience).map(r => r.group));
    const extra = withUnregistered && 'student' === audience;
    if (!groups.length && !extra) { return ''; }
    return `<select data-view="group" aria-label="Группа"><option value="">Все группы</option>${groups.map(g =>
        `<option value="${esc(g)}"${current === g ? ' selected' : ''}>${esc(g)}</option>`).join('')}${extra
        ? `<option value="${UNREGISTERED}"${UNREGISTERED === current ? ' selected' : ''}>Незаписавшиеся</option>` : ''}</select>`;
}

/** Школы — когда показаны гости: самозапись и школы из ссылок преподавателей. */
export function schoolSelectHtml(rows, audience, current) {
    if ('student' === audience) { return ''; }
    const guests = rows.filter(r => 'guest' === r.audience);
    const schools = sortedUnique(guests.map(r => r.school));
    const hasSelf = guests.some(r => !r.school);
    if (!schools.length && !hasSelf) { return ''; }
    return `<select data-view="school" aria-label="Школа"><option value="">Все школы</option>
        ${hasSelf ? `<option value="${SELF_REGISTERED}"${SELF_REGISTERED === current ? ' selected' : ''}>Самозапись</option>` : ''}
        ${schools.map(sc => `<option value="${esc(sc)}"${current === sc ? ' selected' : ''}>${esc(sc)}</option>`).join('')}</select>`;
}

/**
 * Подходит ли строка под фильтры. Группа и школа сужают именно свою аудиторию:
 * выбранная группа оставляет только учеников этой группы, выбранная школа — только гостей этой школы.
 *
 * @param {{audience:string, name:string, group?:string, school?:string}} row
 * @param {{audience:string, search:string, group:string, school:string}} view
 */
export function rowMatches(row, view) {
    if ('all' !== view.audience && row.audience !== view.audience) { return false; }
    const q = norm(view.search);
    if (q && !norm(row.name).includes(q)) { return false; }
    if (view.group && !('student' === row.audience && row.group === view.group)) { return false; }
    if (view.school) {
        if ('guest' !== row.audience) { return false; }
        return SELF_REGISTERED === view.school ? !row.school : row.school === view.school;
    }
    return true;
}
