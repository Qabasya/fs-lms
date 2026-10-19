/* ══════════════════════════════════════════════════════════════════════
   КТП: чистые вычисления календаря — без DOM и без общего стейта.
   Всё нужное функции получают параметрами; мутации стейта и перерисовка —
   на вызывающей стороне (ktp.js / ktp-individual.js).
   ══════════════════════════════════════════════════════════════════════ */

/** Месяцы учебного периода {start_date..end_date} → [{y, m}] (m — 0-based). */
export function computeMonths(period) {
    const months = [];
    if (!period || !period.start_date || !period.end_date) return months;
    const [sy, sm] = period.start_date.split('-').map(Number);
    const [ey, em] = period.end_date.split('-').map(Number);
    let y = sy, m = sm - 1;
    while (y < ey || (y === ey && m <= em - 1)) {
        months.push({ y, m });
        m++; if (m > 11) { m = 0; y++; }
    }
    return months;
}

/**
 * Месяцы календаря индивидуальной группы: занятия ставятся на любые даты, поэтому диапазон —
 * от самого раннего из (начало периода, первое занятие, текущий месяц) до самого позднего из
 * (конец периода, последнее занятие, текущий месяц + 12). `now` — параметром (чистая функция).
 *
 * @param {?{start_date:string,end_date:string}} period Учебный период группы (может отсутствовать).
 * @param {Array<{scheduled_at:?string}>}        themes Темы программы.
 * @param {Date}                                 now
 * @returns {Array<{y:number,m:number}>} m — 0-based.
 */
export function individualMonths(period, themes, now = new Date()) {
    const key = (y, m) => y * 12 + m;
    const fromYm = s => { const [y, m] = String(s).slice(0, 7).split('-').map(Number); return key(y, m - 1); };

    const nowKey = key(now.getFullYear(), now.getMonth());
    let lo = nowKey;
    let hi = nowKey + 12;
    if (period && period.start_date) lo = Math.min(lo, fromYm(period.start_date));
    if (period && period.end_date) hi = Math.max(hi, fromYm(period.end_date));
    (themes || []).filter(t => t.scheduled_at).forEach(t => {
        const k = fromYm(t.scheduled_at);
        lo = Math.min(lo, k);
        hi = Math.max(hi, k);
    });

    const months = [];
    for (let k = lo; k <= hi; k++) months.push({ y: Math.floor(k / 12), m: k % 12 });
    return months;
}

/**
 * Ячейки месяца календаря: ведущие пустые (сдвиг первого дня на день недели, неделя с понедельника) и дни месяца.
 * Общая для КТП и календаря экзаменов: сетка не зависит от того, что в ней стоит.
 *
 * @param {number} year  Год.
 * @param {number} month Месяц, 0-based (как `m` в computeMonths).
 * @returns {Array<{type:'empty'}|{type:'day', date:string, day:number}>}
 */
export function monthCells(year, month) {
    const offset = (new Date(year, month, 1).getDay() + 6) % 7;
    const days = new Date(year, month + 1, 0).getDate();
    const cells = [];
    for (let i = 0; i < offset; i++) cells.push({ type: 'empty' });
    for (let d = 1; d <= days; d++) {
        cells.push({ type: 'day', date: `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`, day: d });
    }
    return cells;
}

/**
 * Стартовый курсор КТП: текущий месяц, если он в учебном периоде; иначе месяц самой ранней размещённой темы
 * (или первый месяц периода). `now` — параметром, чтобы вычисление оставалось чистым и проверяемым.
 */
export function initialCursor(themes, months, now = new Date()) {
    if (!months.length) return 0;
    const current = months.findIndex(mm => mm.y === now.getFullYear() && mm.m === now.getMonth());
    if (current >= 0) return current;
    const placed = (themes || []).filter(t => t.scheduled_at).map(t => t.scheduled_at.slice(0, 7));
    if (!placed.length) return 0;
    placed.sort();
    const [y, m] = placed[0].split('-').map(Number);
    const idx = months.findIndex(mm => mm.y === y && mm.m === m - 1);
    return idx < 0 ? 0 : idx;
}

/** Сдвиг курсора месяца на d с клэмпом в границы [0, monthsCount - 1]. */
export function shiftMonth(cursor, monthsCount, d) {
    return Math.max(0, Math.min(monthsCount - 1, cursor + d));
}

/* Месяцы диапазона всех инд. занятий (min..max scheduled_at). */
export function indiMonths(items) {
    const ym = items.map(it => String(it.scheduled_at || '').slice(0, 7)).filter(Boolean).sort();
    if (!ym.length) return [];
    const [sy, sm] = ym[0].split('-').map(Number);
    const [ey, em] = ym[ym.length - 1].split('-').map(Number);
    const months = [];
    let y = sy, m = sm - 1;
    while (y < ey || (y === ey && m <= em - 1)) {
        months.push({ y, m });
        m++; if (m > 11) { m = 0; y++; }
    }
    return months;
}

/* Открываем календарь на текущем месяце, если он в диапазоне, иначе на первом. */
export function indiInitialCursor(months) {
    if (!months.length) return 0;
    const now = new Date();
    const idx = months.findIndex(mm => mm.y === now.getFullYear() && mm.m === now.getMonth());
    return idx >= 0 ? idx : 0;
}

/* Сдвиг курсора календаря инд. занятий: после отвязки от стейта вычисление
   совпадает с shiftMonth — оставлен алиас под историческим именем. */
export const shiftIndiMonth = shiftMonth;

/** '2026-08-01 12:00:00' → '2026-08-01T12:00' (значение <input type="datetime-local">). */
export function toLocalInputValue(mysqlDateTime) {
    return mysqlDateTime.slice(0, 16).replace(' ', 'T');
}
/** '2026-08-01T12:00' → '2026-08-01 12:00:00'. */
export function fromLocalInputValue(inputValue) {
    return inputValue.replace('T', ' ') + ':00';
}

const pad2 = n => String(n).padStart(2, '0');

/** Длительность занятия в минутах по `scheduled_at`/`ends_at` темы; без конца — 60. */
export function durationMinutes(theme) {
    if (!theme || !theme.scheduled_at || !theme.ends_at) return 60;
    const diff = (new Date(theme.ends_at.replace(' ', 'T')) - new Date(theme.scheduled_at.replace(' ', 'T'))) / 60000;
    return diff > 0 ? diff : 60;
}

/** Конец занятия: день + время начала ('HH:MM') + длительность → 'YYYY-MM-DD HH:MM:00' (через полночь — следующий день). */
export function endAfter(day, time, minutes) {
    const d = new Date(`${day}T${time}:00`);
    d.setMinutes(d.getMinutes() + minutes);
    return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())} ${pad2(d.getHours())}:${pad2(d.getMinutes())}:00`;
}

/** Занятие уже началось (или проведено): `now` — параметром, чтобы вычисление оставалось чистым. */
export function hasStarted(theme, now = new Date()) {
    if (!theme || !theme.scheduled_at) return false;
    if ('held' === theme.status) return true;
    const stamp = `${now.getFullYear()}-${pad2(now.getMonth() + 1)}-${pad2(now.getDate())} ${pad2(now.getHours())}:${pad2(now.getMinutes())}:00`;
    return theme.scheduled_at <= stamp;
}

/**
 * Время для нового занятия на день `day`: берётся у прошлых занятий — сперва у ближайшего раньше
 * этого дня в тот же день недели (был понедельник 16:00 — следующий понедельник предложит 16:00),
 * затем у ближайшего по дате в тот же день недели, затем у самого позднего занятия до этого дня,
 * затем у самого позднего вообще. Нет занятий — null.
 *
 * @param {Array<{scheduled_at:?string, ends_at:?string}>} themes Темы группы.
 * @param {string} day 'YYYY-MM-DD'.
 * @returns {?{start:string, end:?string}} 'HH:MM'; end — null, если конца у занятия нет.
 */
export function suggestLessonTime(themes, day) {
    const placed = (themes || []).filter(t => t.scheduled_at && t.scheduled_at.slice(0, 10) !== day)
        .sort((a, b) => a.scheduled_at.localeCompare(b.scheduled_at));
    if (!placed.length) return null;

    const weekday = new Date(`${day}T12:00:00`).getDay();
    const sameDay = placed.filter(t => new Date(`${t.scheduled_at.slice(0, 10)}T12:00:00`).getDay() === weekday);
    const before = list => list.filter(t => t.scheduled_at.slice(0, 10) < day);
    const nearest = list => list.reduce((best, t) => (
        !best || Math.abs(new Date(t.scheduled_at.slice(0, 10)) - new Date(day)) < Math.abs(new Date(best.scheduled_at.slice(0, 10)) - new Date(day)) ? t : best
    ), null);

    const pick = before(sameDay).pop() || nearest(sameDay) || before(placed).pop() || placed[placed.length - 1];

    return { start: pick.scheduled_at.slice(11, 16), end: pick.ends_at ? pick.ends_at.slice(11, 16) : null };
}
