/* ══════════════════════════════════════════════════════════════════════
   Карточка работы (Tasks.md, п. 7) — одна вёрстка на два экрана: список
   сдач в «Работах» (шаг 2, .wk-sub-list) и вкладка «Работы» в «Сводке по
   ученику». Слева цветной бейдж типа (СР/ПР/ДЗ/КР/ЭКЗ), затем название,
   под ним полоска вердиктов по заданиям (галочка / крестик / часы), справа
   дата и время сдачи. Стили — profile/components/_work-card.scss.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, fmtDateTime } from './utils.js';
import { icoCheck, icoCross, icoClock } from '../common/icons.js';

/** Бейдж → модификатор цвета (палитра — _work-card.scss). */
const BADGE_MOD = { 'СР': 'sr', 'ПР': 'pr', 'ДЗ': 'dz', 'КР': 'kr', 'ЭКЗ': 'ex' };

const MARK_ICON = {
    correct:   () => icoCheck(12),
    incorrect: () => icoCross(11),
    pending:   () => icoClock(12),
};

const MARK_TITLE = { correct: 'Решено', incorrect: 'Не решено', pending: 'На проверке' };

/**
 * @param {Object}   card
 * @param {string}   card.title       Заголовок карточки (название работы или ФИО ученика)
 * @param {string}  [card.badge]      Короткая метка типа работы (СР/ПР/ДЗ/КР/ЭКЗ)
 * @param {string[]}[card.marks]      Вердикты заданий: correct | incorrect | pending
 * @param {string}  [card.subtitle]   Строка под заголовком (группа, статус и т.п.)
 * @param {string}  [card.date]       ISO-дата сдачи
 * @param {string}   card.sourceType  submission | attempt — для перехода в деталь
 * @param {number}   card.sourceId
 * @param {string}  [card.rowClass]   Класс строки (совместимость со старыми обработчиками)
 * @returns {string} HTML карточки
 */
export function workCardHtml(card) {
    const mod = BADGE_MOD[card.badge] || 'df';

    return `<div class="wcard${card.rowClass ? ' ' + card.rowClass : ''}" role="button" tabindex="0"
        data-src-type="${esc(card.sourceType)}" data-src-id="${card.sourceId}">
        ${card.badge ? `<span class="wcard-badge wcard-badge--${mod}">${esc(card.badge)}</span>` : ''}
        <div class="wcard-main">
            <div class="wcard-title">${esc(card.title || '—')}</div>
            ${card.subtitle ? `<div class="wcard-sub">${esc(card.subtitle)}</div>` : ''}
            ${marksHtml(card.marks)}
        </div>
        <span class="wcard-date">${card.date ? esc(fmtDateTime(card.date)) : '—'}</span>
    </div>`;
}

/** Полоска заданий: по значку на задание, в порядке работы. */
function marksHtml(marks) {
    if (!Array.isArray(marks) || !marks.length) { return ''; }

    return `<div class="wcard-marks">${marks.map((m, i) => {
        const kind = MARK_ICON[m] ? m : 'pending';
        return `<span class="wmark wmark--${kind}" title="${esc(`Задание ${i + 1}: ${MARK_TITLE[kind]}`)}">${MARK_ICON[kind]()}</span>`;
    }).join('')}</div>`;
}
