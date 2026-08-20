/* ══════════════════════════════════════════════════════════════════════
   Деталь работы/экзамена — полноэкранная страница проверки (Tasks.md, D2).
   Раньше жила модалкой `sum-modal` внутри «Сводки по ученику» (summary.js);
   вынесена в отдельный SCREENS-экран, чтобы открываться и из «Работ» (D3),
   не только из Сводки. Источник: window.fsProfile.{review, attemptGrade}.
   Экран НЕ входит в cfg.screens — открывается только программно через
   openWorkReview(), app.js держит его секцию в DOM всегда.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast, fmtNum } from './utils.js';
import { icoChevronLeft } from '../common/icons.js';
import { createApi } from './api.js';

const VERDICT_LABEL = { correct: 'Верно', incorrect: 'Неверно', pending: 'На проверке' };
const STATUS_LABEL  = { submitted: 'Сдано', pending: 'На проверке', graded: 'Оценено', returned: 'Возвращено', in_progress: 'В процессе', expired: 'Просрочено' };
/* D18: ответы/баллы скрыты от ученика до подтверждения — у ЕГЭ (без ручной
   проверки заданий) Graded наступает сразу при сдаче и не значит «учитель
   посмотрел», нужна отдельная кнопка. */
const APPROVABLE_KIND = 'ege_computer';

let wrRoot   = null;
let onBackCb = () => {};
let reviewApi = null;
let attemptGradeApi = null;
let returnTo = 'summary';
let current  = null; // { sourceType, sourceId }

/** Вызывается один раз при монтаже SPA (см. app.js) — только сохраняет root/колбэк. */
export function renderWorkReview(root, { onBack } = {}) {
    wrRoot   = root;
    onBackCb = typeof onBack === 'function' ? onBack : () => {};
    const p = window.fsProfile || {};
    reviewApi       = p.review ? createApi(p.review) : null;
    attemptGradeApi = p.attemptGrade ? createApi(p.attemptGrade) : null;
}

/** Экран, на который вернёт кнопка «‹ Назад» (summary | works) — читает app.js при клике. */
export function getReturnTo() {
    return returnTo;
}

/** Открывает деталь работы/экзамена — вызывается из Сводки (D2) и «Работ» (D3). */
export async function openWorkReview(sourceType, sourceId, from) {
    returnTo = from || 'summary';
    current  = { sourceType, sourceId };
    if (!wrRoot) { return; }
    if (!reviewApi) { toast('Оценивание недоступно', 'error'); return; }

    wrRoot.innerHTML = '<div class="wr-loading">Загрузка…</div>';
    let d;
    try {
        d = await reviewApi('getDetail', { source_type: sourceType, source_id: sourceId });
    } catch (e) {
        wrRoot.innerHTML = `<div class="wr-loading">${esc(e.message)}</div>`;
        return;
    }
    render(d);
}

function reload() {
    if (current) { openWorkReview(current.sourceType, current.sourceId, returnTo); }
}

/* ── Render ───────────────────────────────────────────────────────────── */
function render(d) {
    const tasks = d.tasks.length
        ? d.tasks.map(t => taskBlock(t, d)).join('')
        : '<div class="sum-detail-empty">В работе нет задач.</div>';
    const scoreLine = (d.score !== null && d.score !== undefined)
        ? `${fmtNum(d.score)}${d.max_score != null ? ' / ' + fmtNum(d.max_score) : ''} б.`
        : 'без оценки';

    const isApproved = !!d.approved_at;
    const canApprove = d.kind === 'exam' && d.attempt_id && attemptGradeApi
        && d.assessment_kind === APPROVABLE_KIND && !isApproved;

    const grading = d.gradable ? `
        <div class="wr-foot">
            <div class="smf-fields">
                <label>Балл<input type="number" id="grScore" step="0.5" min="0" value="${d.score ?? ''}"></label>
                <label>Из<input type="number" id="grMax" step="0.5" min="0" value="${d.max_score ?? ''}"></label>
                <input type="text" id="grFb" class="smf-fb" placeholder="Комментарий (обязателен для возврата)" value="${d.feedback ? esc(d.feedback) : ''}">
            </div>
            <div class="smf-actions">
                <button class="prof-btn prof-btn-sm" data-grade="return">Вернуть на доработку</button>
                <button class="prof-btn prof-btn-sm prof-btn-primary" data-grade="save">Сохранить оценку</button>
            </div>
        </div>` : '';

    wrRoot.innerHTML = `
        <div class="wr-screen">
            <div class="wr-head">
                <button class="wr-back">${icoChevronLeft(16)} Назад</button>
                <div class="wr-head-main">
                    <div class="smh-title">${esc(d.title)}</div>
                    <div class="smh-meta" id="smhMeta">${d.kind === 'exam' ? 'Экзамен' : 'Работа'} · ${esc(STATUS_LABEL[d.status] || d.status)} · ${esc(scoreLine)}${d.is_late ? ' · <span class="smh-late">Просрочено</span>' : ''}</div>
                </div>
                <div class="smh-actions">
                    ${canApprove ? '<button class="prof-btn prof-btn-sm prof-btn-primary sum-approve">Утвердить работу</button>' : ''}
                    ${isApproved ? '<span class="sum-approved-badge" title="Ответы открыты ученику">Утверждено</span>' : ''}
                    <button class="prof-btn prof-btn-sm sum-reset" data-armed="0">Сбросить попытки</button>
                </div>
            </div>
            <div class="wr-body">
                ${tasks}
                ${d.attachment_url ? attachmentBlock(d) : ''}
                ${d.feedback ? `<div class="sum-fb"><b>Комментарий:</b> ${esc(d.feedback)}</div>` : ''}
            </div>
            ${grading}
        </div>`;

    wrRoot.querySelector('.wr-back').addEventListener('click', () => onBackCb());

    if (d.gradable) { wireGrading(wrRoot, d.submission_id); }
    if (d.kind === 'exam' && d.attempt_id && attemptGradeApi) { wireAttemptGrading(wrRoot, d); }
    if (canApprove) { wireApprove(wrRoot, d); }
    wireReset(wrRoot);
}

/* D18: «Утвердить работу» — единственное явное действие учителя для ЕГЭ (без
   ручной проверки заданий), открывает ответы/баллы ученику. */
function wireApprove(root, d) {
    const btn = root.querySelector('.sum-approve');
    if (!btn) { return; }
    btn.addEventListener('click', async () => {
        btn.disabled = true;
        try {
            await attemptGradeApi('approveAttempt', { attempt_id: d.attempt_id });
            toast('Работа утверждена — ответы открыты ученику');
            reload();
        } catch (e) { toast(e.message, 'error'); btn.disabled = false; }
    });
}

/* Задача 11: сброс попыток/сдач ученика (необратимо) — двойной клик для подтверждения. */
function wireReset(root) {
    const btn = root.querySelector('.sum-reset');
    if (!btn || !current) { return; }
    let armTimer;
    btn.addEventListener('click', async () => {
        if (btn.dataset.armed !== '1') {
            btn.dataset.armed = '1';
            btn.classList.add('sum-reset--armed');
            btn.textContent = 'Точно сбросить? Ещё раз';
            clearTimeout(armTimer);
            armTimer = setTimeout(() => {
                btn.dataset.armed = '0';
                btn.classList.remove('sum-reset--armed');
                btn.textContent = 'Сбросить попытки';
            }, 4000);
            return;
        }
        clearTimeout(armTimer);
        btn.disabled = true;
        try {
            await reviewApi('resetAttempts', { source_type: current.sourceType, source_id: current.sourceId });
            toast('Попытки сброшены');
            onBackCb();
        } catch (e) { toast(e.message, 'error'); btn.disabled = false; }
    });
}

/* T13.1: вложение ученика (фото/файл решения) — форма одиночной сдачи уже
   принимает файл, деталь работы теперь его отдаёт. Картинка — превью, иначе
   ссылка «Открыть файл». */
function attachmentBlock(d) {
    const isImage = d.attachment_mime && d.attachment_mime.indexOf('image/') === 0;
    return `<div class="sum-attachment">
        <div class="sum-attachment-label">Вложение ученика</div>
        ${isImage
            ? `<a href="${esc(d.attachment_url)}" target="_blank" rel="noopener noreferrer"><img src="${esc(d.attachment_url)}" class="sum-attachment-img" alt="Вложение ученика"></a>`
            : `<a href="${esc(d.attachment_url)}" target="_blank" rel="noopener noreferrer" class="sum-attachment-link">Открыть файл</a>`}
    </div>`;
}

function taskBlock(t, d) {
    const score = (t.score !== null && t.score !== undefined)
        ? `<span class="st-score">${fmtNum(t.score)}${t.max_score != null ? '/' + fmtNum(t.max_score) : ''}</span>` : '';
    const canGrade = d.kind === 'exam' && t.task_id && attemptGradeApi;
    const hasCriteria = canGrade && Array.isArray(t.criteria) && t.criteria.length;
    const hasOgeRubric = canGrade && !hasCriteria && t.oge_rubric;
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
                <input type="text" class="stg-fb" placeholder="комментарий">
                <button class="prof-btn prof-btn-sm prof-btn-primary stg-save">Оценить</button>
            </div>` : '';
    return `
        <div class="sum-task">
            <div class="sum-task-head">
                <span class="st-n">Задача ${t.n}</span>
                <span class="sum-verdict sv-${esc(t.verdict)}">${esc(VERDICT_LABEL[t.verdict] || t.verdict)}</span>
                ${score}
            </div>
            <div class="sum-task-cond">${t.condition || '<i>условие недоступно</i>'}</div>
            <div class="sum-task-ans"><span class="sta-label">Ответ ученика:</span> <span class="sta-val">${t.answer ? esc(t.answer) : '—'}</span></div>
            ${t.files && t.files.length ? taskFilesBlock(t.files) : ''}
            ${t.correct ? `<div class="sum-task-ans sum-task-correct"><span class="sta-label">Правильный ответ:</span> <span class="sta-val">${esc(t.correct)}</span></div>` : ''}
            ${grade}
        </div>`;
}

/* Эпик 13 (D16): файлы ученика в ответе «Развёрнутый ответ» — превью изображения
   или ссылка «Открыть файл» для остального. */
function taskFilesBlock(files) {
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
                <input type="text" class="stg-fb" placeholder="комментарий">
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
                <input type="text" class="stg-fb" placeholder="комментарий">
                <button class="prof-btn prof-btn-sm prof-btn-primary stg-save">Оценить</button>
            </div>`;
}

/* Пооответное оценивание попытки экзамена (T11.9). Эпик 13 (D17): критериальные
   задачи шлют criteria_scores (JSON {индекс: баллы}) вместо score/is_correct. */
function wireAttemptGrading(root, d) {
    const meta = root.querySelector('#smhMeta');
    root.querySelectorAll('.sum-task-grade').forEach(box => {
        const btn = box.querySelector('.stg-save');
        const isCriteria  = box.classList.contains('sum-task-grade--criteria');
        const isOgeRubric = box.classList.contains('sum-task-grade--oge-rubric');
        btn.addEventListener('click', async () => {
            const taskId = +box.dataset.taskId;
            const feedback = box.querySelector('.stg-fb').value.trim();
            const payload = { attempt_id: d.attempt_id, task_id: taskId, feedback };

            let verdict;
            if (isCriteria) {
                const scores = {};
                let sum = 0, max = 0;
                box.querySelectorAll('.stg-criterion').forEach(row => {
                    const v = +(row.querySelector('.stgc-points').value || 0);
                    scores[row.dataset.idx] = v;
                    sum += v;
                    max += +row.dataset.max;
                });
                payload.criteria_scores = JSON.stringify(scores);
                verdict = sum >= max ? 'correct' : 'incorrect';
            } else if (isOgeRubric) {
                // Holistic-рубрика (§3.4): один выбранный уровень — обычный простой
                // балл (GradeAttemptCallbacks без критериев), не сумма по критериям.
                const score = +box.querySelector('.stg-oge-score').value;
                const max   = +box.dataset.max;
                payload.score      = String(score);
                payload.is_correct = score >= max ? '1' : '0';
                verdict = score >= max ? 'correct' : 'incorrect';
            } else {
                payload.score = box.querySelector('.stg-score').value || '0';
                payload.is_correct = box.querySelector('.stg-ok-cb').checked ? '1' : '0';
                verdict = box.querySelector('.stg-ok-cb').checked ? 'correct' : 'incorrect';
            }

            btn.disabled = true;
            try {
                const res = await attemptGradeApi('gradeAttempt', payload);
                // Обновляем вердикт задачи + шапку (пересчитанный total/status с сервера).
                const badge = box.closest('.sum-task').querySelector('.sum-verdict');
                if (badge) { badge.className = `sum-verdict sv-${verdict}`; badge.textContent = VERDICT_LABEL[verdict]; }
                if (meta && res) {
                    meta.textContent = `Экзамен · ${STATUS_LABEL[res.attempt_status] || res.attempt_status} · ${fmtNum(res.total_score)}${d.max_score != null ? ' / ' + fmtNum(d.max_score) : ''} б.`;
                }
                toast('Оценка сохранена');
            } catch (e) { toast(e.message, 'error'); }
            btn.disabled = false;
        });
    });
}

function wireGrading(root, submissionId) {
    const scoreEl = root.querySelector('#grScore');
    const maxEl   = root.querySelector('#grMax');
    const fbEl    = root.querySelector('#grFb');

    root.querySelector('[data-grade="save"]').addEventListener('click', async () => {
        try {
            await reviewApi('saveGrade', {
                submission_id: submissionId,
                score: scoreEl.value || '0',
                max_score: maxEl.value || '0',
                feedback: fbEl.value.trim(),
            });
            toast('Оценка сохранена');
            reload();
        } catch (e) { toast(e.message, 'error'); }
    });

    root.querySelector('[data-grade="return"]').addEventListener('click', async () => {
        const fb = fbEl.value.trim();
        if (!fb) { toast('Укажите комментарий для возврата', 'error'); fbEl.focus(); return; }
        try {
            await reviewApi('returnSubmission', { submission_id: submissionId, feedback: fb });
            toast('Работа возвращена на доработку');
            onBackCb();
        } catch (e) { toast(e.message, 'error'); }
    });
}
