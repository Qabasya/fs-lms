/* ══════════════════════════════════════════════════════════════════════
   Деталь работы/экзамена — полноэкранная страница проверки (Tasks.md, D2).
   Раньше жила модалкой `sum-modal` внутри «Сводки по ученику» (summary.js);
   вынесена в отдельный SCREENS-экран, чтобы открываться и из «Работ» (D3),
   не только из Сводки. Источник: window.fsProfile.{review, attemptGrade}.
   Экран НЕ входит в cfg.screens — открывается только программно через
   openWorkReview(), app.js держит его секцию в DOM всегда.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast, fmtNum, fmtDateTime, fmtDuration } from './utils.js';
import { icoChevronLeft } from '../common/icons.js';
import { createApi } from './api.js';
import { confirmDialog } from '../common/components/confirm-dialog.js';
import { renderTask, VERDICT_LABEL } from './task-render.js';
import { resultCaption } from './exams/exam-result.js';
import { ajaxErrorText } from '../common/utils.js';

const STATUS_LABEL  = { submitted: 'Сдано', pending: 'На проверке', graded: 'Оценено', returned: 'Возвращено', in_progress: 'В процессе', expired: 'Просрочено' };
/* D18: ответы/баллы скрыты от ученика до подтверждения — у ЕГЭ (без ручной
   проверки заданий) Graded наступает сразу при сдаче и не значит «учитель
   посмотрел», нужна отдельная кнопка. */
const APPROVABLE_KIND = 'ege_computer';
/* Статусы сдачи, при которых работа ещё висит в корзине «На проверке» —
   зеркало ReviewQueueService::workStatusesFor('pending'). */
const OPEN_STATUSES = [ 'submitted', 'pending_review' ];
const STALE_CODE = 'X-STALE';

let wrRoot   = null;
let onBackCb = () => {};
let onLoadedCb = () => {};
let reviewApi = null;
let attemptGradeApi = null;
let batchGradeApi = null;
let examApi = null;
let correcting = false;
let returnTo = 'summary';
let current  = null; // { sourceType, sourceId }

/** Вызывается один раз при монтаже SPA (см. app.js) — только сохраняет root/колбэк. */
export function renderWorkReview(root, { onBack, onLoaded } = {}) {
    wrRoot   = root;
    onBackCb = typeof onBack === 'function' ? onBack : () => {};
    onLoadedCb = typeof onLoaded === 'function' ? onLoaded : () => {};
    const p = window.fsProfile || {};
    reviewApi       = p.review ? createApi(p.review) : null;
    attemptGradeApi = p.attemptGrade ? createApi(p.attemptGrade) : null;
    // D4: поштучное оценивание задания «Развёрнутый ответ» внутри submission-работы —
    // переиспользует Этап-7 эндпоинт GradeBatchTask (пишет per-task строку submissions).
    batchGradeApi   = p.batchGrade ? createApi(p.batchGrade) : null;
    // 8.6: исправление утверждённого результата идёт через блок конфига экзаменов сотрудника.
    examApi         = p.exams ? createApi(p.exams) : null;
}

/** Экран, на который вернёт кнопка «‹ Назад» (summary | works) — читает app.js при клике. */
export function getReturnTo() {
    return returnTo;
}

/** Открывает деталь работы/экзамена — вызывается из Сводки (D2) и «Работ» (D3). */
export async function openWorkReview(sourceType, sourceId, from) {
    returnTo = from || 'summary';
    correcting = false;
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

    // «Пройти заново» (.docs/Tasks.md): история прошлых раундов сдачи — только
    // для работ (submission), у экзаменов каждая попытка и так отдельная запись.
    // Не критично для основного экрана — молчаливо пропускаем при сбое.
    let history = [];
    if ('work' === d.kind && d.submission_id) {
        try {
            const r = await reviewApi('getAttemptHistory', { submission_id: d.submission_id });
            history = r?.attempts || [];
        } catch { /* пилюли просто не покажутся */ }
    }

    render(d, history);
    onLoadedCb(d);
}

function reload() {
    if (current) { openWorkReview(current.sourceType, current.sourceId, returnTo); }
}

/* ── Render ───────────────────────────────────────────────────────────── */
function render(d, history = []) {
    const liveTasksHtml = d.tasks.length
        ? d.tasks.map(t => renderTask(t, taskContext(d))).join('')
        : '<div class="sum-detail-empty">В работе нет задач.</div>';
    const isExam = !!d.exam;
    const scoreLine = isExam
        ? examScoreLine(d)
        : ((d.score !== null && d.score !== undefined)
            ? `${fmtNum(d.score)}${d.max_score != null ? ' / ' + fmtNum(d.max_score) : ''} б.`
            : 'без оценки');

    const isApproved = !!d.approved_at;
    // Экзаменная попытка ученика (ЕГЭ и ОГЭ) утверждается, когда проверка закончена; гостю кнопки нет (`approvable` приходит с сервера).
    const canApprove = isExam
        ? (!!d.approvable && !!attemptGradeApi)
        : (d.kind === 'exam' && d.attempt_id && attemptGradeApi && d.assessment_kind === APPROVABLE_KIND && !isApproved);
    // Tasks.md п. 6: работа с разбором по заданиям не имеет единой формы оценки
    // (оценивание поштучное, D4) — без этой кнопки её нечем было увести из
    // «На проверке» в «Проверенные».
    const canComplete = d.kind === 'work' && d.submission_id && OPEN_STATUSES.includes(d.status);

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

    // «Пройти заново» (.docs/Tasks.md): выбор попытки — только когда их больше одной.
    const currentRound = history.length ? Math.max(...history.map(h => h.round)) : null;
    const picker = attemptPickerBlock(history, currentRound, Number(d.max_attempts) || 0);

    wrRoot.innerHTML = `
        <div class="wr-screen">
            <div class="wr-head">
                <button class="wr-back">${icoChevronLeft(16)} Назад</button>
                <div class="wr-head-main">
                    <div class="smh-title">${esc(d.title)}${isExam && d.participant_name ? ' · ' + esc(d.participant_name) : ''}</div>
                    <div class="smh-meta" id="smhMeta">${d.kind === 'exam' ? 'Экзамен' : 'Работа'} · ${esc(isExam ? (d.result_status_label || d.status) : (STATUS_LABEL[d.status] || d.status))} · ${esc(scoreLine)}${durationMeta(d.duration_sec)}${d.is_late ? ' · <span class="smh-late">Просрочено</span>' : ''}</div>
                </div>
                <div class="smh-actions">
                    ${d.review_url ? `<a class="prof-btn prof-btn-sm" href="${esc(d.review_url)}" target="_blank" rel="noopener">Лист результатов</a>` : ''}
                    ${canApprove ? '<button class="prof-btn prof-btn-sm prof-btn-primary sum-approve">Утвердить работу</button>' : ''}
                    ${isApproved ? '<span class="sum-approved-badge" title="Ответы открыты ученику">Утверждено</span>' : ''}
                    ${isExam && d.correctable && examApi && !correcting ? '<button class="prof-btn prof-btn-sm sum-correct">Исправить результат</button>' : ''}
                    ${canComplete ? '<button class="prof-btn prof-btn-sm prof-btn-primary sum-complete">Проверка завершена</button>' : ''}
                    ${isExam ? '' : '<button class="prof-btn prof-btn-sm sum-reset">Сбросить попытки</button>'}
                </div>
            </div>
            ${picker}
            ${isExam && correcting ? correctionPanel(d) : ''}
            <div class="wr-body">
                <div class="wr-tasks prof-swap" data-wr-tasks>${liveTasksHtml}</div>
                ${d.attachment_url ? attachmentBlock(d) : ''}
                ${d.feedback ? `<div class="sum-fb"><b>Комментарий:</b> ${esc(d.feedback)}</div>` : ''}
            </div>
            ${grading}
        </div>`;

    wrRoot.querySelector('.wr-back').addEventListener('click', () => onBackCb());

    const wireLiveTaskControls = () => {
        if (d.kind === 'exam' && d.attempt_id && attemptGradeApi) { wireAttemptGrading(wrRoot, d); }
        if (d.kind === 'work' && batchGradeApi) { wireSubmissionTaskGrading(wrRoot); }
        wireTaskCredit(wrRoot, d);
    };
    wireLiveTaskControls();

    if (d.gradable) { wireGrading(wrRoot, d.submission_id); }
    if (canApprove) { wireApprove(wrRoot, d); }
    if (canComplete) { wireComplete(wrRoot, d); }
    if (!isExam) { wireReset(wrRoot); }
    if (isExam && d.correctable && examApi) { wireCorrection(wrRoot, d); }
    if (picker) { wireAttemptPicker(wrRoot, history, currentRound, liveTasksHtml, wireLiveTaskControls); }
}

/* «Пройти заново»: выбор раунда сдачи (Tasks.md п. 8 — вместо строки пилюль,
   которая расползалась на десяток попыток). Последний раунд всегда «текущий» —
   то, что и так показывает экран. */
function attemptPickerBlock(history, currentRound, maxAttempts) {
    if (history.length < 2) { return ''; }

    const options = history.slice().reverse().map(h => {
        const ok = h.tasks.filter(t => 'correct' === t.verdict).length;
        const cur = h.round === currentRound ? ' · текущая' : '';
        const dur = fmtDuration(h.duration_sec);
        return `<option value="${h.round}"${h.round === currentRound ? ' selected' : ''}>` +
            esc(`Попытка ${h.round} · ${fmtDateTime(h.submitted_at)}${dur ? ' · ' + dur : ''} · ${ok}/${h.tasks.length}${cur}`) +
            '</option>';
    }).join('');

    return `<div class="wr-attempts">
        <label class="wr-attempts-label" for="wrAttemptPick">Попытка</label>
        <select class="wk-select wr-attempt-select" id="wrAttemptPick">${options}</select>
        <span class="wr-attempts-count">${esc(attemptsCountText(history.length, maxAttempts))}</span>
    </div>`;
}

/* Счётчик попыток в шапке выбора: лимит сдач — настройка работы (max_attempts
   в детали работы, 0 — без ограничений). Учителю важно видеть, сколько у ученика
   осталось, прежде чем сбрасывать попытки. */
function attemptsCountText(used, max) {
    return max > 0 ? `${used} из ${max}` : `${used}`;
}

/* Выбор раунда подменяет список задач: текущий — обычный live-рендер
   (с контролами оценки), прошлые — read-only снимок без них (ручная оценка
   применима только к актуальной сдаче — она одна попадает в журнал). */
function wireAttemptPicker(root, history, currentRound, liveTasksHtml, wireLiveTaskControls) {
    const tasksRoot = root.querySelector('[data-wr-tasks]');
    const foot      = root.querySelector('.wr-foot');
    const select    = root.querySelector('#wrAttemptPick');
    if (!select) { return; }

    select.addEventListener('change', () => {
        const round = Number(select.value);
        if (round === currentRound) {
            tasksRoot.innerHTML = liveTasksHtml;
            if (foot) { foot.hidden = false; }
            wireLiveTaskControls();
            return;
        }

        const roundData = history.find((h) => h.round === round);
        tasksRoot.innerHTML = roundData && roundData.tasks.length
            ? roundData.tasks.map(t => renderTask(t, { mode: 'read_only', kind: 'exam' })).join('')
            : '<div class="sum-detail-empty">В этой попытке нет задач.</div>';
        if (foot) { foot.hidden = true; }
    });
}

/* Итог экзаменной попытки в шапке: пока ручная часть не проверена — только «Проверка не завершена», без вторичного балла и отметки. */
function examScoreLine(d) {
    if (!d.result || d.result.pending) { return 'Проверка не завершена'; }
    return resultCaption(d.result);
}

/* 8.6: панель исправления утверждённого результата — поле балла у каждого задания и обязательная причина. */
function correctionPanel(d) {
    // Исправлять можно только задания с записанным баллом: у задания без ответа в работе нет строки, которую можно поправить.
    const rows = (d.tasks || []).filter(t => t.task_id && t.score !== null && t.score !== undefined).map(t => `
        <label class="wr-correct-row" data-task-id="${t.task_id}" data-max="${t.max_score ?? 0}">
            <span>№ ${esc(String(t.number ?? t.n ?? t.task_id))}</span>
            <input type="number" class="wr-correct-score" step="0.5" min="0" max="${t.max_score ?? ''}" value="${t.score}" data-old="${t.score}">
            <span class="stg-of">/ ${t.max_score != null ? fmtNum(t.max_score) : '—'}</span>
        </label>`).join('');
    return `<div class="wr-correct">
        <div class="wr-correct-title">Исправление результата</div>
        <div class="wr-correct-rows">${rows}</div>
        <label class="wr-correct-reason"><span>Причина</span><textarea id="wrCorrectReason" rows="2" maxlength="500" placeholder="Что сообщить ученику"></textarea></label>
        <div class="smf-actions">
            <button class="prof-btn prof-btn-sm prof-btn-primary" data-correct="save">Сохранить исправление</button>
            <button class="prof-btn prof-btn-sm" data-correct="cancel">Отмена</button>
        </div>
    </div>`;
}

/* 8.6: «Исправить результат» открывает панель; сохранение — с подтверждением, ученик получит уведомление с причиной. */
function wireCorrection(root, d) {
    root.querySelector('.sum-correct')?.addEventListener('click', () => { correcting = true; render(d); });
    root.querySelector('[data-correct="cancel"]')?.addEventListener('click', () => { correcting = false; render(d); });

    const save = root.querySelector('[data-correct="save"]');
    if (!save) { return; }
    save.addEventListener('click', async () => {
        const reason = root.querySelector('#wrCorrectReason').value.trim();
        if (!reason) { toast('Укажите причину исправления', 'error'); return; }

        const changes = [];
        root.querySelectorAll('.wr-correct-row').forEach(row => {
            const input = row.querySelector('.wr-correct-score');
            if (input.value !== input.dataset.old) {
                changes.push({ task_id: +row.dataset.taskId, score: input.value || '0' });
            }
        });
        if (!changes.length) { toast('Нет изменений баллов', 'error'); return; }

        if (!await confirmDialog('Исправить результат? Ученик получит уведомление с причиной.', 'Исправить', 'Отмена')) { return; }

        save.disabled = true;
        try {
            await examApi('correctResult', { attempt_id: d.attempt_id, changes, reason, result_version: d.result_version });
            toast('Результат исправлен');
            correcting = false;
            reload();
        } catch (e) { reportFailure(e); save.disabled = false; }
    });
}

/* Версия результата экзаменной попытки: уходит с каждым действием проверки, чтобы два проверяющих не перезаписали друг друга. */
function versionParams(d) {
    return d.exam ? { result_version: d.result_version } : {};
}

/* Ошибка действия проверки: устаревшая версия — понятное сообщение и перезагрузка экрана, прочее — текст ошибки. */
function reportFailure(e) {
    toast(ajaxErrorText(e, 'Не удалось выполнить действие'), 'error');
    if (e && STALE_CODE === e.code) { reload(); }
}

/* Затраченное на работу время в шапке; у сдач до появления замера его нет. */
function durationMeta(sec) {
    const text = fmtDuration(sec);
    return text ? ` · ${esc(text)}` : '';
}

/* Контекст общего renderer'а задач: режим manage; оценивать можно, только если для источника подключён транспорт. */
function taskContext(d) {
    return { mode: 'manage', kind: d.kind, canGradeAttempt: !!attemptGradeApi, canGradeBatch: !!batchGradeApi };
}

/* D18: «Утвердить работу» — единственное явное действие учителя для ЕГЭ (без
   ручной проверки заданий), открывает ответы/баллы ученику. */
function wireApprove(root, d) {
    const btn = root.querySelector('.sum-approve');
    if (!btn) { return; }
    btn.addEventListener('click', async () => {
        btn.disabled = true;
        try {
            await attemptGradeApi('approveAttempt', { attempt_id: d.attempt_id, ...versionParams(d) });
            toast('Работа утверждена — ответы открыты ученику');
            reload();
        } catch (e) { reportFailure(e); btn.disabled = false; }
    });
}

/* Tasks.md п. 6: «Проверка завершена» — переводит сдачу в «Проверенные».
   Неоценённые развёрнутые ответы фиксируются с текущим баллом, поэтому
   спрашиваем подтверждение, когда такие задания в работе ещё есть. */
function wireComplete(root, d) {
    const btn = root.querySelector('.sum-complete');
    if (!btn) { return; }
    const ungraded = (d.tasks || []).filter((t) => t.gradable && 'pending' === t.verdict).length;

    btn.addEventListener('click', async () => {
        if (ungraded) {
            const ok = await confirmDialog(
                `Не оценено развёрнутых ответов: ${ ungraded }. Они будут зачтены с текущим баллом (по умолчанию 0). Завершить проверку?`,
                'Завершить проверку',
                'Отмена'
            );
            if (!ok) { return; }
        }

        btn.disabled = true;
        try {
            await reviewApi('completeReview', { submission_id: d.submission_id });
            toast('Работа переведена в «Проверенные»');
            reload();
        } catch (e) { toast(e.message, 'error'); btn.disabled = false; }
    });
}

/* Задача 11: сброс попыток/сдач ученика (необратимо) — модалка подтверждения
   вместо двойного клика (2026-08-21: вид кнопки больше не меняется). */
function wireReset(root) {
    const btn = root.querySelector('.sum-reset');
    if (!btn || !current) { return; }
    btn.addEventListener('click', async () => {
        const ok = await confirmDialog(
            'Все попытки и сдачи ученика по этой работе будут удалены без возможности восстановления. Ученик сможет пройти её заново.',
            'Сбросить попытки',
            'Отмена'
        );
        if (!ok) { return; }

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
            const payload = { attempt_id: d.attempt_id, task_id: taskId, feedback, ...versionParams(d) };

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
                // Экзамен: статус результата («готова к утверждению») и версия меняются — экран перечитывается целиком.
                if (d.exam) { reload(); return; }
            } catch (e) { reportFailure(e); }
            btn.disabled = false;
        });
    });
}

/* D4 (.docs/Tasks.md): поштучное оценивание задания «Развёрнутый ответ» внутри
   submission-работы — GradeBatchTask пишет score/feedback в per-task строку и
   пересчитывает агрегат (итог работы = сумма по заданиям), полная деталь
   перезапрашивается через reload() — проще инкрементального обновления шапки
   и достаточно (список заданий работы короткий). */
function wireSubmissionTaskGrading(root) {
    root.querySelectorAll('.sum-task-grade--batch').forEach((box) => {
        const btn = box.querySelector('.stg-save');
        btn.addEventListener('click', async () => {
            const submissionId = +box.dataset.submissionId;
            const score = box.querySelector('.stg-score').value || '0';
            const feedback = box.querySelector('.stg-fb').value.trim();

            btn.disabled = true;
            try {
                await batchGradeApi('gradeTask', { submission_id: submissionId, score, feedback });
                toast('Оценка сохранена');
                reload();
            } catch (e) { toast(e.message, 'error'); btn.disabled = false; }
        });
    });
}

/* Tasks.md, п. 6: «Засчитать задание» — полный балл за одно задание без возврата
   всей работы ученику (опечатка в условии, спорная формулировка и т.п.).

   Работа — тот же эндпоинт GradeBatchTask, что и у формы выше: он пишет per-task
   строку, синхронизирует снимок вердиктов (ученик увидит «Верно») и пересчитывает
   итог. Экзамен — GradeAttempt с флагом credit=1: без него сервер отклоняет ручной
   балл автопроверяемому заданию, а с ним отметка `graded_by_user_id` делает балл
   авторитетным и для листа ответов станции.

   Комментарий не трогаем — если учитель его уже оставил, зачёт его не стирает. */
function wireTaskCredit(root, d) {
    root.querySelectorAll('.sum-task-credit').forEach((box) => {
        const btn = box.querySelector('.stg-credit');
        btn.addEventListener('click', async () => {
            const ok = await confirmDialog(
                'За задание будет выставлен полный балл. Работа останется у ученика, на доработку не вернётся.',
                'Засчитать',
                'Отмена'
            );
            if (!ok) { return; }

            const feedback = box.closest('.sum-task').querySelector('.stg-fb')?.value.trim() || '';
            btn.disabled = true;
            try {
                if (d.kind === 'exam') {
                    await attemptGradeApi('gradeAttempt', {
                        attempt_id: d.attempt_id,
                        task_id: +box.dataset.taskId,
                        credit: '1',
                        feedback,
                        ...versionParams(d),
                    });
                } else {
                    await batchGradeApi('gradeTask', {
                        submission_id: +box.dataset.submissionId,
                        score: box.dataset.max || '1',
                        feedback,
                    });
                }
                toast('Задание засчитано');
                reload();
            } catch (e) { toast(e.message, 'error'); btn.disabled = false; }
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
