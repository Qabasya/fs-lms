/* ══════════════════════════════════════════════════════════════════════
   Общий renderer задачи: «Работы» преподавателя (manage) и разбор экзамена
   ученика/родителя (read_only) рисуют задачу одним кодом (SPEC §5, критерий 20).

   Что показывать, решает сервер: в `read_only` блоки оценивания и зачёта не
   вызываются вообще, независимо от полей задачи (их там и нет — см.
   ExamReviewProjection). Скрытие через CSS защитой не считается.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, fmtNum, fmtDateTime } from './utils.js';
import { solutionBlock } from './work-review-solution.js';

export const VERDICT_LABEL = {
    correct: 'Верно',
    corrected: 'Верно с исправлением',
    incorrect: 'Неверно',
    partial: 'Частично',
    unanswered: 'Не решено',
    pending: 'На проверке',
};

/**
 * @typedef {Object} TaskRenderContext
 * @property {'manage'|'read_only'} [mode]    manage — как в «Работах»; read_only — без оценивания и зачёта.
 * @property {'work'|'exam'}        [kind]    Источник: работа-сдача или попытка контрольной/экзамена.
 * @property {boolean}              [canGradeAttempt] Есть транспорт оценки задач попытки (только manage).
 * @property {boolean}              [canGradeBatch]   Есть транспорт оценки задач сдачи (только manage).
 */

/* Момент, когда ученик последний раз правил ответ на задание. */
export function answeredAtHtml(at) {
    return at ? `<span class="st-time" title="Время ответа">ответ ${esc(fmtDateTime(at))}</span>` : '';
}

/* Верно после ошибки в проверке кнопкой до сдачи — отдельный (жёлтый) вердикт. */
export function verdictKey(t) {
    return t.corrected && 'correct' === t.verdict ? 'corrected' : t.verdict;
}

/**
 * Разметка одной задачи.
 *
 * @param {Object}            t   Задача из WorkDetailService (`n`, `condition`, `answer`, `verdict`, …).
 * @param {TaskRenderContext} ctx Режим и возможности оценивания.
 * @return {string} HTML блока `.sum-task`.
 */
export function renderTask(t, ctx = {}) {
    const { mode = 'manage', kind = 'work', canGradeAttempt = false, canGradeBatch = false } = ctx;
    const manage = 'manage' === mode;

    const score = (t.score !== null && t.score !== undefined)
        ? `<span class="st-score">${fmtNum(t.score)}${t.max_score != null ? '/' + fmtNum(t.max_score) : ''}</span>` : '';
    // t.manual — задача требует ручной проверки (TaskTemplate::needsManualReview()
    // на сервере); для авто-проверяемых задач баллы считает TaskCheckerRegistry
    // при сдаче, а не эта форма — балл/чекбокс/комментарий тут никто не читал
    // (заполнялись, но не влияли на итог), поэтому для них форма не рендерится
    // вовсе (2026-08-21).
    const canGrade = manage && kind === 'exam' && t.task_id && t.manual && canGradeAttempt;
    const hasCriteria = canGrade && Array.isArray(t.criteria) && t.criteria.length;
    const hasOgeRubric = canGrade && !hasCriteria && t.oge_rubric;
    // D4 (.docs/Tasks.md): submission-работы оцениваются поштучно, как экзамены —
    // только для задач с t.gradable (file_answer_task/alternative_conditions_task/
    // robo_task, TaskTemplate::needsManualReview()); авто-проверяемые задачи работы — только
    // condition/answer/correct, без input'ов (как non-gradable экзаменационные).
    const canGradeSubmissionTask = manage && kind === 'work' && t.gradable && t.task_submission_id && canGradeBatch;
    // Эталон — только там, где он что-то объясняет: у решённой задачи он дублирует
    // ответ ученика (Tasks.md, п. 1). На ручной проверке (pending) остаётся —
    // именно по нему преподаватель и ставит балл. Условие общее для обоих режимов.
    const showCorrect = t.correct && 'correct' !== t.verdict;
    // Tasks.md, п. 6: ручной зачёт задания — опечатка в условии не должна стоить
    // ученику возврата всей работы. Доступен для ЛЮБОЙ задачи (в т.ч.
    // автопроверенной), пока она не засчитана; у ручных задач кнопка стоит рядом
    // с формой балла, а не вместо неё. Прошлые раунды сдачи task_submission_id
    // не отдают — там снимок, и кнопки не будет.
    const creditable = manage && 'correct' !== t.verdict && (
        (kind === 'work' && t.task_submission_id && canGradeBatch)
        || (kind === 'exam' && t.task_id && canGradeAttempt)
    );
    // Пооответное оценивание экзамена (T11.9). Эпик 13 (D17): если у задачи есть
    // критерии — оценивание покритерийное (сумма сырых баллов, без весов);
    // holistic-рубрика ОГЭ (§3.4, .docs/Tasks.md) — один балл через dropdown с
    // полным текстом всех уровней рядом (НЕ сумма критериев);
    // иначе — прежний контрол «балл + верно».
    const grade = hasCriteria ? criteriaGradeBlock(t) : hasOgeRubric ? ogeRubricGradeBlock(t) : canGrade ? `
            <div class="sum-task-grade" data-task-id="${t.task_id}" data-max="${t.max_score ?? ''}">
                <input type="number" class="stg-score" step="0.5" min="0" value="${t.score ?? ''}" placeholder="балл">
                <span class="stg-of">/ ${t.max_score != null ? fmtNum(t.max_score) : '1'}</span>
                <label class="stg-ok"><input type="checkbox" class="stg-ok-cb" ${t.verdict === 'correct' ? 'checked' : ''}>верно</label>
                <input type="text" class="stg-fb" placeholder="комментарий" value="${t.feedback ? esc(t.feedback) : ''}">
                <button class="prof-btn prof-btn-sm prof-btn-primary stg-save">Оценить</button>
            </div>` : canGradeSubmissionTask ? `
            <div class="sum-task-grade sum-task-grade--batch" data-submission-id="${t.task_submission_id}" data-max="${t.max_score ?? 1}">
                <input type="number" class="stg-score" step="0.5" min="0" value="${t.score ?? ''}" placeholder="балл">
                <span class="stg-of">/ ${t.max_score != null ? fmtNum(t.max_score) : '1'}</span>
                <input type="text" class="stg-fb" placeholder="комментарий" value="${t.feedback ? esc(t.feedback) : ''}">
                <button class="prof-btn prof-btn-sm prof-btn-primary stg-save">Оценить</button>
            </div>` : '';
    const credit = creditable ? `
            <div class="sum-task-credit" data-submission-id="${t.task_submission_id ?? ''}" data-task-id="${t.task_id ?? ''}" data-max="${t.max_score ?? 1}">
                <button class="prof-btn prof-btn-sm stg-credit">Засчитать задание</button>
                <span class="stc-hint">Выставит полный балл за задание, работа останется у ученика</span>
            </div>` : '';
    const key = verdictKey(t);
    // Якорь — устойчивый идентификатор задания: клик по строке перечня ведёт к нему, а не к строке с тем же индексом.
    const anchor = t.anchor ? ` id="${esc(t.anchor)}"` : '';

    return `
        <div class="sum-task"${anchor}>
            <div class="sum-task-head">
                <span class="st-n">Задача ${t.n}</span>
                <span class="sum-verdict sv-${esc(key)}">${esc(VERDICT_LABEL[key] || t.verdict)}</span>
                ${answeredAtHtml(t.answered_at)}
                ${score}
                ${t.manually_graded ? '<span class="sum-manual-mark">Оценено преподавателем</span>' : ''}
            </div>
            <div class="sum-task-cond">${t.condition || '<i>условие недоступно</i>'}</div>
            ${t.answer || !t.code ? `<div class="sum-task-ans"><span class="sta-label">Ответ ученика:</span> <span class="sta-val">${t.answer ? esc(t.answer) : '—'}</span></div>` : ''}
            ${t.code ? codeBlock(t.code) : ''}
            ${t.files && t.files.length ? taskFilesBlock(t.files) : ''}
            ${showCorrect ? `<div class="sum-task-ans sum-task-correct"><span class="sta-label">Правильный ответ:</span> <span class="sta-val">${esc(t.correct)}</span></div>` : ''}
            ${solutionBlock(t.solution)}
            ${grade}
            ${credit}
        </div>`;
}

/* Код ученика (TaskTemplate::hasCodeField()): у Code/FileCode — необязательное
   поле рядом с ответом, в проверке не участвует; у «Задания Робо» — весь ответ
   (строка «Ответ ученика» тогда не выводится). Моноширинным блоком. */
export function codeBlock(code) {
    return `<div class="sum-task-code">
        <span class="sta-label">Код ученика:</span>
        <pre class="sum-task-code-pre"><code>${esc(code)}</code></pre>
    </div>`;
}

/* Эпик 13 (D16): файлы ученика в ответе «Развёрнутый ответ» — превью изображения
   или ссылка «Открыть файл» для остального. */
export function taskFilesBlock(files) {
    const items = files.map((f) => {
        const isImage = f.mime && f.mime.indexOf('image/') === 0;
        return isImage
            ? `<a href="${esc(f.url)}" target="_blank" rel="noopener noreferrer"><img src="${esc(f.url)}" class="sum-task-files__img" alt="${esc(f.name)}"></a>`
            : `<a href="${esc(f.url)}" target="_blank" rel="noopener noreferrer" class="sum-task-files__link">${esc(f.name)}</a>`;
    }).join('');
    return `<div class="sum-task-files"><span class="sta-label">Файлы ученика:</span><div class="sum-task-files__list">${items}</div></div>`;
}

/* Эпик 13 (D17): покритерийное оценивание — строка на критерий, балл задачи = сумма. */
function criteriaGradeBlock(t) {
    const rows = t.criteria.map((c, i) => `
            <div class="stg-criterion" data-idx="${i}" data-max="${c.max_points}">
                <span class="stgc-label">${esc(c.label)}</span>
                <input type="number" class="stgc-points" min="0" max="${c.max_points}" step="0.5" value="${c.awarded ?? 0}">
                <span class="stgc-of">/ ${fmtNum(c.max_points)}</span>
            </div>`).join('');
    return `
            <div class="sum-task-grade sum-task-grade--criteria" data-task-id="${t.task_id}">
                ${rows}
                <input type="text" class="stg-fb" placeholder="комментарий" value="${t.feedback ? esc(t.feedback) : ''}">
                <button class="prof-btn prof-btn-sm prof-btn-primary stg-save">Оценить</button>
            </div>`;
}

/* §3.4 (.docs/Tasks.md): holistic-рубрика ОГЭ (13.1/13.2/14/15/16) — учитель
   видит текст всех уровней целиком и ставит ОДИН балл через dropdown, а не
   сумму независимых критериев (в отличие от criteriaGradeBlock выше). */
function ogeRubricGradeBlock(t) {
    const max = t.oge_rubric.max_points;
    const options = Array.from({ length: max + 1 }, (_, score) => max - score)
        .map((score) => `<option value="${score}" ${t.score !== null && Math.round(+t.score) === score ? 'selected' : ''}>${score} из ${max}</option>`)
        .join('');
    return `
            <div class="sum-task-rubric">${t.oge_rubric.html}</div>
            <div class="sum-task-grade sum-task-grade--oge-rubric" data-task-id="${t.task_id}" data-max="${max}">
                <select class="stg-oge-score">${options}</select>
                <input type="text" class="stg-fb" placeholder="комментарий" value="${t.feedback ? esc(t.feedback) : ''}">
                <button class="prof-btn prof-btn-sm prof-btn-primary stg-save">Оценить</button>
            </div>`;
}
