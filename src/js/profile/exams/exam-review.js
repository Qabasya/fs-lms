/* ══════════════════════════════════════════════════════════════════════
   Экран «Результаты экзамена» ученика и родителя (этап 7.4).
   Полноэкранный разбор: шапка (название, дата сдачи, итог) и задания тем же
   renderer'ом, что экран «Работы» преподавателя, — в режиме read_only.
   Экран НЕ входит в cfg.screens: открывается программно из карточки экзамена,
   секцию держит в DOM app.js (как work-review).

   До утверждения сервер не отдаёт ни заданий, ни баллов — экран показывает
   только сообщение. Попытка определяется сервером по участию; `attempt_id`
   клиент не передаёт.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, fmtDateTime } from '../utils.js';
import { icoChevronLeft } from '../../common/icons.js';
import { createApi } from '../api.js';
import { renderTask } from '../task-render.js';
import { getChildId } from '../learner-child.js';
import { resultCaption } from './exam-result.js';

let rootEl = null;
let onBackCb = () => {};
let api = null;
let generation = 0; // номер запроса: ответ устаревшего запроса экран не перерисовывает

/** Вызывается один раз при монтаже SPA (app.js): запоминает root и колбэк «Назад». */
export function renderExamReview(root, { onBack } = {}) {
    rootEl = root;
    onBackCb = typeof onBack === 'function' ? onBack : () => {};
    const cfg = window.fsProfile?.exams;
    api = cfg ? createApi(cfg) : null;
}

/**
 * Открывает разбор экзамена.
 *
 * @param {number|string} eventId Проведение.
 * @param {string}        [anchor] Якорь задания: после отрисовки экран прокручивается к нему.
 */
export async function openExamReview(eventId, anchor) {
    if (!rootEl) { return; }
    if (!api) {
        rootEl.innerHTML = '<div class="wr-loading">Данные экзаменов недоступны.</div>';
        return;
    }

    const mine = ++generation;
    rootEl.innerHTML = '<div class="wr-loading">Загрузка…</div>';

    const childId = getChildId();
    let review;
    try {
        review = await api('getReview', childId ? { event_id: eventId, student_person_id: childId } : { event_id: eventId });
    } catch (e) {
        if (mine === generation) { rootEl.innerHTML = `<div class="wr-loading">${esc(e.message)}</div>`; }
        return;
    }
    if (mine !== generation) { return; }

    paint(review);
    if (anchor) { scrollToTask(anchor); }
}

function paint(review) {
    const revealed = true === review.revealed;
    const caption = revealed ? resultCaption(review.result) : '';
    const meta = [
        review.submitted_at ? `Сдано ${fmtDateTime(review.submitted_at)}` : '',
        caption ? `Итог: ${caption}` : '',
    ].filter(Boolean).map(esc).join(' · ');

    // Режим read_only: задания без форм оценивания и зачёта. До утверждения заданий в ответе нет вовсе.
    const body = revealed
        ? (review.tasks || []).map(t => renderTask(t, { mode: 'read_only', kind: 'exam' })).join('')
            || '<div class="sum-detail-empty">В работе нет заданий.</div>'
        : '<div class="sum-detail-empty">Работа сдана и ожидает утверждения преподавателем. Результат появится здесь после утверждения.</div>';

    rootEl.innerHTML = `
        <div class="wr-screen">
            <div class="wr-head">
                <button type="button" class="wr-back">${icoChevronLeft(16)} Назад</button>
                <div class="wr-head-main">
                    <div class="smh-title">${esc(review.title || 'Экзамен')}</div>
                    ${meta ? `<div class="smh-meta">${meta}</div>` : ''}
                </div>
            </div>
            <div class="wr-body">
                <div class="wr-tasks" data-exam-tasks>${body}</div>
            </div>
        </div>`;

    rootEl.querySelector('.wr-back').addEventListener('click', () => onBackCb());
}

/** Прокрутка к заданию по устойчивому якорю — не к строке с тем же индексом. */
function scrollToTask(anchor) {
    const el = anchor ? rootEl.querySelector(`[id="${CSS.escape(anchor)}"]`) : null;
    if (el) { el.scrollIntoView({ block: 'start' }); }
}
