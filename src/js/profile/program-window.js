/* ══════════════════════════════════════════════════════════════════════
   «Программа курса» в кабинете: новые уроки сверху, дальнее будущее свёрнуто.
   Общее для «Моих курсов» ученика (learner.js) и страницы курса преподавателя
   (teacher-courses.js). Модуль — чистые функции: разметку строки урока каждый
   экран рисует сам, здесь только раскладка (порядок, окно, заглушка).
   ══════════════════════════════════════════════════════════════════════ */

/** Сколько ближайших непроведённых уроков видно преподавателю. */
const UPCOMING = 2;

/**
 * Ученик: только разблокированные уроки, последний — сверху. Модуль без открытых
 * уроков скрыт. Номер модуля (`mi`) остаётся исходным.
 *
 * @param {Array<{name:string, lessons:Array<{status:string}>}>} modules
 * @return {Array<{m:object, mi:number, rows:Array<object>, hidden:Array<object>}>}
 */
export function learnerLayout(modules) {
    return modules
        .map((m, mi) => ({ m, mi, rows: unlocked(m.lessons).reverse(), hidden: [] }))
        .filter((x) => x.rows.length)
        .reverse();
}

/** Ученик, курс без модулей. */
export function learnerFlat(lessons) {
    return unlocked(lessons).reverse();
}

function unlocked(lessons) {
    return lessons.filter((l) => 'locked' !== l.status && '' !== l.status);
}

/**
 * Преподаватель: от последнего проведённого урока (`held`, в любой группе)
 * видны два следующих, всё дальше — в `hidden` модуля (заглушка «Ещё N…»).
 * `open` — модуль раскрыт по умолчанию: тот, где два ближайших урока
 * (или последний проведённый, если курс закончился).
 *
 * @param {Array<{title:string, lessons:Array<{held?:boolean}>}>} modules
 * @return {Array<{m:object, mi:number, rows:Array<object>, hidden:Array<object>, open:boolean}>}
 */
export function teacherLayout(modules) {
    const flat = modules.flatMap((m) => m.lessons);
    const lastHeld = flat.reduce((acc, l, i) => (l.held ? i : acc), -1);
    const upcoming = new Set(flat.slice(lastHeld + 1, lastHeld + 1 + UPCOMING));
    const future = new Set(flat.slice(lastHeld + 1));

    const items = modules.map((m, mi) => {
        const rows = m.lessons.filter((l) => !future.has(l) || upcoming.has(l));
        const hidden = m.lessons.filter((l) => future.has(l) && !upcoming.has(l));
        // Модуль целиком из «далёких» уроков — без заглушки: раскрыл модуль, увидел всё.
        const whole = 0 === rows.length;
        return {
            m, mi,
            rows: (whole ? hidden : rows).slice().reverse(),
            hidden: whole ? [] : hidden.slice().reverse(),
            open: m.lessons.some((l) => upcoming.has(l)),
        };
    });

    if (!items.some((x) => x.open)) {
        const cur = items.slice().reverse().find((x) => x.rows.length);
        if (cur) { cur.open = true; }
    }
    return items.reverse();
}

/**
 * Заглушка «Ещё N уроков впереди» + скрытые строки (сворачиваются как .prof-fold).
 *
 * @param {Array<object>} hidden   Скрытые уроки (уже в порядке вывода).
 * @param {(l:object)=>string} rowHtml
 */
export function moreHtml(hidden, rowHtml) {
    if (!hidden.length) { return ''; }
    const n = hidden.length;
    const word = pluralLessons(n);
    return `<button type="button" class="sc-more" data-more aria-expanded="false">Ещё ${n} ${word} впереди</button>
        <div class="sc-more-body prof-fold"><div class="prof-fold-inner">${hidden.map(rowHtml).join('')}</div></div>`;
}

/** Вешает раскрытие заглушек внутри контейнера. */
export function bindMore(container) {
    container.querySelectorAll('[data-more]').forEach((btn) => btn.addEventListener('click', () => {
        const open = btn.nextElementSibling.classList.toggle('is-open');
        btn.setAttribute('aria-expanded', String(open));
        btn.classList.toggle('is-open', open);
    }));
}

function pluralLessons(n) {
    const a = n % 10, b = n % 100;
    if (1 === a && 11 !== b) { return 'урок'; }
    if (a >= 2 && a <= 4 && (b < 12 || b > 14)) { return 'урока'; }
    return 'уроков';
}
