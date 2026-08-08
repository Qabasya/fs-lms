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

/** Стартовый курсор: месяц самой ранней размещённой темы (или 0). */
export function initialCursor(themes, months) {
    const placed = (themes || []).filter(t => t.scheduled_at).map(t => t.scheduled_at.slice(0, 7));
    if (!placed.length || !months.length) return 0;
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
