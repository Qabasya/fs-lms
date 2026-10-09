/* ══════════════════════════════════════════════════════════════════════
   Расписание кабинета: «Сегодня / Неделя / Месяц» — общий механизм главной
   преподавателя (dashboard.js) и ученика/родителя (learner.js).

   Модуль знает только про даты: сетку недели (Пн–Вс, листается ‹ ›), календарный месяц
   (ряды по неделе, клик по числу открывает его неделю) и переключатель режимов. Что
   рисовать в строке «Сегодня» и в карточке недели/месяца, решает вызывающий — у
   преподавателя это журнал группы, у ученика урок и трансляция.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, fmtDayMonth, todayIso } from './utils.js';
import { DOW_JS, DOW_RU } from './constants.js';

/**
 * Подключает переключатель расписания.
 *
 * @param {object}   o
 * @param {Element}  o.toggle    Сегмент-переключатель с кнопками `data-mode="today|week|month"`.
 * @param {Element}  o.body      Контейнер, в который рисуется расписание.
 * @param {object[]} o.items     Все занятия; у каждого есть `date` ('Y-m-d').
 * @param {Function} o.todayHtml () => HTML режима «Сегодня».
 * @param {Function} o.cardHtml  (item, cssClass) => HTML карточки занятия в неделе/месяце.
 */
export function initSchedule({ toggle, body, items, todayHtml, cardHtml }) {
    const state = { weekOffset: 0, monthOffset: 0 };

    const byDate = () => {
        const map = {};
        items.forEach((it) => { (map[it.date] = map[it.date] || []).push(it); });
        return map;
    };

    function render(mode) {
        if (mode === 'month') {
            renderMonth();
        } else if (mode === 'week') {
            renderWeek();
        } else {
            body.innerHTML = todayHtml();
        }
    }

    function renderWeek() {
        const map = byDate();
        // 7 дней недели (Пн–Вс), даже пустые. Опорный понедельник — текущая неделя
        // со сдвигом state.weekOffset (пагинация ‹ ›).
        const weekDates = datesFrom(state.weekOffset, 7);

        const grid = `<div class="prof-week-grid">${weekDates.map((date) => {
            const dayItems = map[date] || [];
            const cards = dayItems.length
                ? dayItems.map((it) => cardHtml(it, 'prof-week-card')).join('')
                : '<div class="prof-week-empty">Занятий нет</div>';
            return `<div class="prof-week-col">
                    <div class="prof-week-dow">${DOW_JS[new Date(date + 'T00:00:00').getDay()]} ${date.slice(8, 10)}.${date.slice(5, 7)}</div>
                    ${cards}
                </div>`;
        }).join('')}</div>`;

        body.innerHTML = schedNav('wnav', 'Предыдущая неделя', 'Следующая неделя', `${fmtDayMonth(weekDates[0])} – ${fmtDayMonth(weekDates[6])}`) + grid;
        body.querySelectorAll('.pwn-arrow[data-wnav]').forEach((btn) =>
            btn.addEventListener('click', () => { state.weekOffset += +btn.dataset.wnav; render('week'); }));
    }

    /**
     * «Месяц»: календарный месяц (с 1-го по последнее число) рядами по неделе Пн–Вс;
     * хвосты соседних месяцев в крайних рядах — пустые клетки. ‹ › листают месяцы.
     * В ячейке — все занятия дня, ряд растягивается по самому загруженному дню; клик по
     * числу открывает эту неделю в режиме «Неделя».
     */
    function renderMonth() {
        const map   = byDate();
        const today = todayIso();
        const first = new Date();
        first.setHours(0, 0, 0, 0);
        first.setDate(1);
        first.setMonth(first.getMonth() + state.monthOffset);

        const year   = first.getFullYear();
        const month  = first.getMonth();
        const daysIn = new Date(year, month + 1, 0).getDate();
        const lead   = (first.getDay() + 6) % 7;            // пустые клетки до 1-го (неделя с Пн)
        const trail  = (7 - ((lead + daysIn) % 7)) % 7;     // и после последнего числа
        const prefix = `${year}-${String(month + 1).padStart(2, '0')}-`;

        const cells = [
            ...Array.from({ length: lead }, () => '<div class="prof-month-cell is-out"></div>'),
            ...Array.from({ length: daysIn }, (_, i) => {
                const date = prefix + String(i + 1).padStart(2, '0');
                const dayItems = (map[date] || []).map((it) => cardHtml(it, 'prof-month-item')).join('');

                return `<div class="prof-month-cell${date === today ? ' is-today' : ''}${date < today ? ' is-past' : ''}">
                <button type="button" class="pmc-day" data-day="${date}" aria-label="Открыть неделю ${esc(fmtDayMonth(date))}">${i + 1}</button>
                ${dayItems}
            </div>`;
            }),
            ...Array.from({ length: trail }, () => '<div class="prof-month-cell is-out"></div>'),
        ].join('');

        body.innerHTML = schedNav('mnav', 'Предыдущий месяц', 'Следующий месяц', monthLabel(first))
            + `<div class="prof-month-grid">
            ${DOW_RU.map((dow) => `<div class="prof-month-dow">${dow}</div>`).join('')}
            ${cells}
        </div>`;

        body.querySelectorAll('.pwn-arrow[data-mnav]').forEach((btn) =>
            btn.addEventListener('click', () => { state.monthOffset += +btn.dataset.mnav; render('month'); }));
        body.querySelectorAll('[data-day]').forEach((btn) => btn.addEventListener('click', () => openWeekOf(btn.dataset.day)));
    }

    /** Переключает расписание на «Неделю», в которой лежит дата. */
    function openWeekOf(date) {
        const days = (new Date(date + 'T00:00:00') - mondayOf(0)) / 86400000;
        state.weekOffset = Math.floor(Math.round(days) / 7);

        toggle.querySelectorAll('button').forEach((x) => x.classList.toggle('on', 'week' === x.dataset.mode));
        render('week');
    }

    toggle.querySelectorAll('button').forEach((b) => b.addEventListener('click', () => {
        toggle.querySelectorAll('button').forEach((x) => x.classList.remove('on'));
        b.classList.add('on');
        state.weekOffset  = 0; // каждый вход в режим — текущая неделя / текущий месяц
        state.monthOffset = 0;
        render(b.dataset.mode);
    }));

    render('today');
}

/** «Сентябрь 2026». */
function monthLabel(date) {
    const name = date.toLocaleDateString('ru-RU', { month: 'long' });
    return `${name.charAt(0).toUpperCase()}${name.slice(1)} ${date.getFullYear()}`;
}

/** Понедельник текущей недели со сдвигом на weekOffset недель. */
function mondayOf(weekOffset) {
    const monday = new Date();
    monday.setHours(0, 0, 0, 0);
    monday.setDate(monday.getDate() - ((monday.getDay() + 6) % 7) + weekOffset * 7);
    return monday;
}

/**
 * count дат подряд от понедельника недели со сдвигом weekOffset. Формат дат
 * локальный (без сдвига часового пояса, как у toISOString).
 */
function datesFrom(weekOffset, count) {
    const monday = mondayOf(weekOffset);
    const fmtIso = (dt) => `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}-${String(dt.getDate()).padStart(2, '0')}`;

    return Array.from({ length: count }, (_, i) => {
        const dt = new Date(monday);
        dt.setDate(monday.getDate() + i);
        return fmtIso(dt);
    });
}

/** Пагинатор (‹ подпись ›) над сеткой расписания. */
function schedNav(attr, prevLabel, nextLabel, label) {
    return `<div class="prof-week-nav">
        <button type="button" class="pwn-arrow" data-${attr}="-1" aria-label="${prevLabel}">‹</button>
        <div class="pwn-label">${esc(label)}</div>
        <button type="button" class="pwn-arrow" data-${attr}="1" aria-label="${nextLabel}">›</button>
    </div>`;
}
