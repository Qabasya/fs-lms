/* ══════════════════════════════════════════════════════════════════════
   Общее для «Моих экзаменов» и экрана «Результаты экзамена»: подпись итога и
   статусы заданий в перечне. Числа — только с сервера (ExamScoreService), клиент
   ничего не считает и не знает шкал.
   ══════════════════════════════════════════════════════════════════════ */

/** Статус единицы оценивания → подпись и вариант пилюли `sc-pill` («Моих курсов»). */
export const UNIT_STATUS = {
    correct: { label: 'Верно', pill: 'done' },
    partial: { label: 'Частично', pill: 'open' },
    unanswered: { label: 'Не решено', pill: 'wait' },
    incorrect: { label: 'Неверно', pill: 'err' },
    pending: { label: 'Проверяется', pill: 'lock' },
};

/**
 * Итог: ЕГЭ — «{вторичный} из {макс}», ОГЭ — «{первичный} из {макс}, отметка {N}».
 * Неокончательный итог (ручная часть ещё проверяется) показывается первичным баллом.
 *
 * @param {{direction: string, primary: number, primary_max: number, secondary: ?number, secondary_max: ?number, grade: ?number, final: boolean}|null|undefined} r
 * @return {string}
 */
export function resultCaption(r) {
    if (!r) { return ''; }
    if (r.direction === 'oge') {
        return r.final && r.grade != null
            ? `${r.primary} из ${r.primary_max}, отметка ${r.grade}`
            : `${r.primary} из ${r.primary_max}`;
    }
    return r.final && r.secondary != null ? `${r.secondary} из ${r.secondary_max}` : `${r.primary} из ${r.primary_max}`;
}

/**
 * Доля первичного балла для полосы прогресса, 0–100.
 *
 * @param {{primary: number, primary_max: number}|null|undefined} r
 * @return {number}
 */
export function resultPercent(r) {
    if (!r || !(r.primary_max > 0)) { return 0; }
    return Math.max(0, Math.min(100, Math.round((r.primary / r.primary_max) * 100)));
}
