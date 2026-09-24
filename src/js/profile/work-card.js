/* ══════════════════════════════════════════════════════════════════════
   Карточка работы (Tasks.md, п. 7) — одна вёрстка на два экрана: список
   сдач в «Работах» (шаг 2, .wk-sub-list) и вкладка «Работы» в «Сводке по
   ученику». Одна строка: цветной бейдж типа (СР/ПР/ДЗ/КР/ЭКЗ), заголовок с
   подписью (ученик · группа или работа · статус), полоска вердиктов по
   заданиям (галочка / крестик / часы; до 27 в ряд, размер тянется по месту),
   справа дата и время сдачи и сколько ученик потратил на работу. Колонки
   фиксированной ширины — отметки у всех карточек списка стоят друг под другом.
   Стили — profile/components/_work-card.scss.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, fmtDateTime, fmtDuration } from './utils.js';
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
 * @param {number}  [card.duration]   Секунд от открытия работы до сдачи (нет замера — не выводится)
 * @param {string}   card.sourceType  submission | attempt — для перехода в деталь
 * @param {number}   card.sourceId
 * @param {string}  [card.rowClass]   Класс строки (совместимость со старыми обработчиками)
 * @returns {string} HTML карточки
 */
export function workCardHtml(card) {
    const mod = BADGE_MOD[card.badge] || 'df';

    return `<div class="wcard${card.rowClass ? ' ' + card.rowClass : ''}" role="button" tabindex="0"
        data-src-type="${esc(card.sourceType)}" data-src-id="${card.sourceId}">
        <span class="wcard-type">${card.badge ? `<span class="wcard-badge wcard-badge--${mod}">${esc(card.badge)}</span>` : ''}</span>
        <div class="wcard-main" title="${esc([card.title, card.subtitle].filter(Boolean).join(' · '))}">
            <span class="wcard-title">${esc(card.title || '—')}</span>
            ${card.subtitle ? `<span class="wcard-sub">${esc(card.subtitle)}</span>` : ''}
        </div>
        ${marksHtml(card.marks)}
        <div class="wcard-when">
            <span class="wcard-date">${card.date ? esc(fmtDateTime(card.date)) : '—'}</span>
            ${durationHtml(card.duration)}
        </div>
    </div>`;
}

/** Затраченное время: у сдач до появления замера его нет — тогда ничего. */
function durationHtml(sec) {
    const text = fmtDuration(sec);
    return text
        ? `<span class="wcard-dur" title="Затрачено на работу">${icoClock(12)}${esc(text)}</span>`
        : '';
}

/** Полоска заданий: по значку на задание, в порядке работы. */
function marksHtml(marks) {
    // Пустой контейнер тоже нужен: он держит колонку, иначе дата съедет влево.
    if (!Array.isArray(marks) || !marks.length) { return '<div class="wcard-marks"></div>'; }

    return `<div class="wcard-marks">${marks.map((m, i) => {
        const kind = MARK_ICON[m] ? m : 'pending';
        return `<span class="wmark wmark--${kind}" title="${esc(`Задание ${i + 1}: ${MARK_TITLE[kind]}`)}">${MARK_ICON[kind]()}</span>`;
    }).join('')}</div>`;
}
