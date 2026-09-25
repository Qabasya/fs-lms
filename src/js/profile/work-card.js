/* ══════════════════════════════════════════════════════════════════════
   Карточка работы (Tasks.md, п. 7) — одна вёрстка на два экрана: список
   сдач в «Работах» (шаг 2, .wk-sub-list) и вкладка «Работы» в «Сводке по
   ученику». Одна строка: цветной бейдж типа (СР/ПР/ДЗ/КР/ЭКЗ), заголовок с
   подписью (ученик · группа или работа · статус), полоска вердиктов по
   заданиям (галочка / крестик / часы / прочерк у несданной; до 27 в ряд, размер тянется по месту),
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
    missed:    () => '–',
};

const MARK_TITLE = { correct: 'Решено', incorrect: 'Не решено', pending: 'На проверке', missed: 'Не сдано' };

/**
 * @param {Object}   card
 * @param {string}   card.title       Заголовок карточки (название работы или ФИО ученика)
 * @param {string}  [card.badge]      Короткая метка типа работы (СР/ПР/ДЗ/КР/ЭКЗ)
 * @param {string[]}[card.marks]      Вердикты заданий: correct | incorrect | pending | missed
 * @param {string}  [card.subtitle]   Строка под заголовком (группа, статус и т.п.)
 * @param {string}  [card.date]       ISO-дата сдачи
 * @param {number}  [card.duration]   Секунд от открытия работы до сдачи (нет замера — не выводится)
 * @param {string}  [card.sourceType] submission | attempt — для перехода в деталь (кабинет преподавателя)
 * @param {number}  [card.sourceId]   0 — сдачи нет (ДЗ с прошедшим дедлайном)
 * @param {string}  [card.url]        Ссылка на саму работу (кабинет ученика) — карточка становится ссылкой
 * @param {string}  [card.rowClass]   Класс строки (совместимость со старыми обработчиками)
 * @returns {string} HTML карточки; без ссылки и без сдачи — некликабельна
 */
export function workCardHtml(card) {
    const mod = BADGE_MOD[card.badge] || 'df';
    const tag = card.url ? 'a' : 'div';
    const cls = ['wcard', card.rowClass, (card.url || card.sourceId) ? '' : 'wcard--static'].filter(Boolean).join(' ');
    let act = '';
    if (card.url) {
        act = ` href="${esc(card.url)}"`;
    } else if (card.sourceId) {
        act = ` role="button" tabindex="0" data-src-type="${esc(card.sourceType)}" data-src-id="${card.sourceId}"`;
    }

    return `<${tag} class="${cls}"${act}>
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
    </${tag}>`;
}

/**
 * Подпись статуса работы под заголовком карточки: результат, «На проверке»,
 * «Просрочено · 3/5» (сдано после срока) или «Не сдано · срок …».
 *
 * @param {{display: string, value: string, overdue?: boolean, due_at?: string}} w
 * @returns {string}
 */
export function workStatusText(w) {
    if ('missed' === w.display) { return `Не сдано · срок ${fmtDateTime(w.due_at)}`; }
    if ('pending' === w.display) { return 'На проверке'; }
    return w.overdue ? `Просрочено · ${w.value}` : w.value;
}

/**
 * Чип работы в строке занятия: «ПР 3/5», «ДЗ не сдано», «на проверке» + метка
 * «просрочено». Одна вёрстка для сводки преподавателя и посещаемости ученика.
 *
 * @param {{title: string, badge?: string, value: string, display: string, overdue?: boolean, due_at?: string}} w
 * @param {string} [attrs] Атрибуты кликабельного чипа (href или role/data-*); пусто — просто подпись
 * @param {'span'|'a'} [tag]
 * @returns {string}
 */
export function workChipHtml(w, attrs = '', tag = 'span') {
    const missed = 'missed' === w.display;
    const cls    = ['sum-work', 'pending' === w.display ? 'pending' : '', w.overdue ? 'overdue' : '', missed ? 'missed' : ''].filter(Boolean).join(' ');
    let text = esc(w.value);
    if (missed) { text = 'не сдано'; } else if ('pending' === w.display) { text = 'на проверке'; }
    let hint = w.title;
    if (missed) { hint += ` — не сдано, срок ${fmtDateTime(w.due_at)}`; } else if (w.overdue) { hint += ' — сдано после дедлайна'; }

    return `<${tag} class="${cls}"${attrs ? ' ' + attrs : ''} title="${esc(hint)}">${w.badge ? `<b>${esc(w.badge)}</b> ` : ''}${text}${w.overdue ? ' <span class="sum-work-late">просрочено</span>' : ''}</${tag}>`;
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
