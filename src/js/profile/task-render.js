/* ══════════════════════════════════════════════════════════════════════
   Общий renderer задачи: «Работы» преподавателя (manage) и разбор экзамена
   ученика/родителя (read_only) рисуют задачу одним кодом (SPEC §5, критерий 20).

   Что показывать, решает сервер: в `read_only` блоки оценивания и зачёта не
   вызываются вообще, независимо от полей задачи (их там и нет — см.
   ExamReviewProjection). Скрытие через CSS защитой не считается.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, fmtNum, fmtDateTime, fmtClock } from './utils.js';
import { solutionBlock } from './work-review-solution.js';

export const VERDICT_LABEL = {
    correct: 'Верно',
    corrected: 'Верно с исправлением',
    incorrect: 'Неверно',
    partial: 'Частично',
    unanswered: 'Нерешено',
    pending: 'На проверке',
};

/**
 * @typedef {Object} TaskRenderContext
 * @property {'manage'|'read_only'} [mode]    manage — как в «Работах»; read_only — без оценивания и зачёта.
 * @property {'work'|'exam'}        [kind]    Источник: работа-сдача или попытка контрольной/экзамена.
 * @property {boolean}              [canGradeAttempt] Есть транспорт оценки задач попытки (только manage).
 * @property {boolean}              [canGradeBatch]   Есть транспорт оценки задач сдачи (только manage).
 */

/* Время ответа на задание от начала экзамена/раунда работы: «Ответ дан: ЧЧ:ММ:СС» или, если ответ менялся, «первый / последний». */
export function answerTimeHtml(t) {
    const first = t.answer_first_sec ?? t.answer_last_sec;
    const last = t.answer_last_sec ?? t.answer_first_sec;
    if (null === first || undefined === first) { return ''; }

    const changed = last !== first;
    const text = `Ответ дан: ${fmtClock(first)}${changed ? ` / ${fmtClock(last)}` : ''}`;
    const hint = changed
        ? 'Первое время — сколько прошло до первого ответа, второе — сколько прошло от начала до последнего ответа'
        : 'Сколько времени прошло от начала до ответа на задание';
    return `<span class="st-time" title="${esc(hint)}">${esc(text)}</span>`;
}

/* Верно после ошибки в проверке кнопкой до сдачи — отдельный (жёлтый) вердикт. */
export function verdictKey(t) {
    return t.corrected && 'correct' === t.verdict ? 'corrected' : t.verdict;
}

/**
 * Кнопка формы оценки: «Оценить» (основная) или, когда оценка уже сохранена и поля заблокированы, красная «Изменить оценку».
 * Состояние потом переключает `setGradeLocked()` в work-review.js — классы и подписи те же.
 *
 * @param {boolean} locked Оценка выставлена преподавателем.
 */
export function gradeSaveButton(locked) {
    return locked
        ? '<button class="prof-btn prof-btn-sm prof-btn-danger stg-save">Изменить оценку</button>'
        : '<button class="prof-btn prof-btn-sm prof-btn-primary stg-save">Оценить</button>';
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
    // Оценка уже выставлена преподавателем: поля заблокированы, вместо «Оценить» — «Изменить оценку».
    const locked = !!t.manually_graded;
    const dis = locked ? ' disabled' : '';
    const gradedCls = locked ? ' is-graded' : '';
    // Tasks.md, п. 6: ручной зачёт задания — опечатка в условии не должна стоить
    // ученику возврата всей работы. Доступен для ЛЮБОЙ задачи (в т.ч.
    // автопроверенной), пока она не засчитана; у задач с формой оценки кнопки нет —
    // балл ставится самой формой. Прошлые раунды сдачи task_submission_id
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
            <div class="sum-task-grade${gradedCls}" data-task-id="${t.task_id}" data-max="${t.max_score ?? ''}">
                <input type="number" class="stg-score" step="1" min="0" value="${t.score ?? ''}" placeholder="балл"${dis}>
                <span class="stg-of">/ ${t.max_score != null ? fmtNum(t.max_score) : '1'}</span>
                <label class="stg-ok"><input type="checkbox" class="stg-ok-cb" ${t.verdict === 'correct' ? 'checked' : ''}${dis}>верно</label>
                <input type="text" class="stg-fb" placeholder="комментарий" value="${t.feedback ? esc(t.feedback) : ''}"${dis}>
                ${gradeSaveButton(locked)}
            </div>` : canGradeSubmissionTask ? `
            <div class="sum-task-grade sum-task-grade--batch${gradedCls}" data-submission-id="${t.task_submission_id}" data-max="${t.max_score ?? 1}">
                <input type="number" class="stg-score" step="1" min="0" value="${t.score ?? ''}" placeholder="балл"${dis}>
                <span class="stg-of">/ ${t.max_score != null ? fmtNum(t.max_score) : '1'}</span>
                <input type="text" class="stg-fb" placeholder="комментарий" value="${t.feedback ? esc(t.feedback) : ''}"${dis}>
                ${gradeSaveButton(locked)}
            </div>` : '';
    // Есть форма оценки («Оценить») — отдельный «Засчитать задание» не нужен: полный балл ставится там же.
    // Автопроверяемое задание, зачтённое преподавателем: для таких `graded_by_user_id` пишет только зачёт
    // (ручной балл автозаданию сервер не принимает) — поэтому «manually_graded» здесь и есть «засчитано».
    // Ошибочный зачёт отменяется: задание возвращается к автопроверке.
    const credited = manage && t.manually_graded && !grade && (
        (kind === 'exam' && t.task_id && !t.manual && canGradeAttempt)
        || (kind === 'work' && t.task_submission_id && !t.gradable && canGradeBatch)
    );
    const creditAttrs = `data-submission-id="${t.task_submission_id ?? ''}" data-task-id="${t.task_id ?? ''}" data-max="${t.max_score ?? 1}"`;
    const credit = credited ? `
            <div class="sum-task-credit" ${creditAttrs}>
                <button class="prof-btn prof-btn-sm prof-btn-danger stg-uncredit">Отменить зачёт</button>
                <span class="stc-hint">Задание засчитано преподавателем; отмена вернёт автоматическую проверку</span>
            </div>` : (creditable && !grade ? `
            <div class="sum-task-credit" ${creditAttrs}>
                <button class="prof-btn prof-btn-sm stg-credit">Засчитать задание</button>
                <span class="stc-hint">Выставит полный балл за задание, работа останется у ученика</span>
            </div>` : '');
    const key = verdictKey(t);
    // Якорь — устойчивый идентификатор задания: клик по строке перечня ведёт к нему, а не к строке с тем же индексом.
    const anchor = t.anchor ? ` id="${esc(t.anchor)}"` : '';

    return `
        <div class="sum-task"${anchor}>
            <div class="sum-task-head">
                <span class="st-n">Задача ${t.n}</span>
                <span class="sum-verdict sv-${esc(key)}">${esc(VERDICT_LABEL[key] || t.verdict)}</span>
                ${answerTimeHtml(t)}
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
    const locked = !!t.manually_graded;
    const dis = locked ? ' disabled' : '';
    const rows = t.criteria.map((c, i) => `
            <div class="stg-criterion" data-idx="${i}" data-max="${c.max_points}">
                <span class="stgc-label">${esc(c.label)}</span>
                <input type="number" class="stgc-points" min="0" max="${c.max_points}" step="1" value="${c.awarded ?? 0}"${dis}>
                <span class="stgc-of">/ ${fmtNum(c.max_points)}</span>
            </div>`).join('');
    return `
            <div class="sum-task-grade sum-task-grade--criteria${locked ? ' is-graded' : ''}" data-task-id="${t.task_id}">
                ${rows}
                <input type="text" class="stg-fb" placeholder="комментарий" value="${t.feedback ? esc(t.feedback) : ''}"${dis}>
                ${gradeSaveButton(locked)}
            </div>`;
}

/* §3.4 (.docs/Tasks.md): holistic-рубрика ОГЭ (13.1/13.2/14/15/16) — учитель
   видит текст всех уровней целиком и ставит ОДИН балл через dropdown, а не
   сумму независимых критериев (в отличие от criteriaGradeBlock выше). */
function ogeRubricGradeBlock(t) {
    const max = t.oge_rubric.max_points;
    const locked = !!t.manually_graded;
    const dis = locked ? ' disabled' : '';
    const options = Array.from({ length: max + 1 }, (_, score) => max - score)
        .map((score) => `<option value="${score}" ${t.score !== null && Math.round(+t.score) === score ? 'selected' : ''}>${score} из ${max}</option>`)
        .join('');
    return `
            <div class="sum-task-rubric">${t.oge_rubric.html}</div>
            <div class="sum-task-grade sum-task-grade--oge-rubric${locked ? ' is-graded' : ''}" data-task-id="${t.task_id}" data-max="${max}">
                <select class="stg-oge-score"${dis}>${options}</select>
                <input type="text" class="stg-fb" placeholder="комментарий" value="${t.feedback ? esc(t.feedback) : ''}"${dis}>
                ${gradeSaveButton(locked)}
            </div>`;
}
