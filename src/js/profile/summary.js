/* ══════════════════════════════════════════════════════════════════════
   «Сводка по ученику» (Эпик 10 T10.8, D8) — заменяет очередь «Проверка работ».
   Источник: window.fsProfile.{groups, summary:{nonce,actions}, ajax.url}.
   Выбор группы + ученика → карточки его занятий: дата, тема, цветная полоса
   (🟢 посещён · 🟣 индивидуальное · 🔴 пропуск · серый — не отмечено) и результаты
   работ по типам (badge + сырой балл). Оценивание — в детали работы (T10.9).
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast, fmtNum, emptyState } from './utils.js';
import { icoDocCheck } from '../common/icons.js';
import { createApi } from './api.js';
import { DOW_JS } from './constants.js';
import { groupPickerBtnHtml, studentPickerBtnHtml, openGroupPicker, openStudentPicker } from './picker.js';

const KIND_LABEL = { group: 'Групповое', individual: 'Индивидуальное' };
const ATT_LABEL  = { present: 'Присутствовал', absent: 'Отсутствовал', none: 'Не отмечено' };
const VERDICT_LABEL = { correct: 'Верно', incorrect: 'Неверно', pending: 'На проверке' };
const STATUS_LABEL  = { submitted: 'Сдано', pending: 'На проверке', graded: 'Оценено', returned: 'Возвращено', in_progress: 'В процессе', expired: 'Просрочено' };

let root = null;
let state = null;
let api = null;
let reviewApi = null;
let attemptGradeApi = null;

export function renderSummary(r) {
    root = r;
    const p = window.fsProfile || {};
    state = {
        groups:   Array.isArray(p.groups) ? p.groups : [],
        cfg:      p.summary || null,
        groupId:  (p.groups && p.groups[0]) ? p.groups[0].id : null,
        personId: null,
        roster:   [],
        data:     null,
    };
    api = createApi(state.cfg);
    reviewApi = p.review ? createApi(p.review) : null;
    attemptGradeApi = p.attemptGrade ? createApi(p.attemptGrade) : null;
    if (!state.groups.length || !state.cfg) { root.innerHTML = empty('Нет групп', 'За вами не закреплены группы.'); return; }
    loadRoster();
}

async function loadRoster() {
    try {
        const d = await api('getRoster', { group_id: state.groupId });
        state.roster = Array.isArray(d.students) ? d.students : [];
    } catch (e) {
        root.innerHTML = empty('Не удалось загрузить ростер', e.message);
        return;
    }
    state.personId = state.roster.length ? state.roster[0].person_id : null;
    if (!state.personId) { state.data = { lessons: [] }; render(); return; }
    loadSummary();
}

async function loadSummary() {
    try {
        state.data = await api('getSummary', { group_id: state.groupId, student_person_id: state.personId });
    } catch (e) {
        root.innerHTML = empty('Не удалось загрузить сводку', e.message);
        return;
    }
    render();
}

/* ── Render ───────────────────────────────────────────────────────────── */
function render() {
    const lessons = (state.data && state.data.lessons) || [];
    const cards = lessons.length
        ? lessons.map(lessonCard).join('')
        : `<div class="j-empty">${state.data && state.data.open ? 'В программе нет занятий.' : 'У ученика пока нет датированных занятий.'}</div>`;

    const g = state.groups.find(x => x.id === state.groupId) || state.groups[0];
    const student = state.roster.find(s => s.person_id === state.personId);

    root.innerHTML = `
    <div class="prof-summary">
        <div class="sum-head">
            <div class="prof-ktp-pick">
                <span class="kp-label">Группа</span>
                ${groupPickerBtnHtml(g, 'sumGroupBtn')}
            </div>
            <div class="prof-ktp-pick">
                <span class="kp-label">Ученик</span>
                ${studentPickerBtnHtml(student, state.roster, 'sumStudentBtn')}
            </div>
        </div>
        <div class="sum-cards">${cards}</div>
    </div>`;

    const gBtn = root.querySelector('#sumGroupBtn');
    if (gBtn) gBtn.addEventListener('click', openGroupMenu);
    const sBtn = root.querySelector('#sumStudentBtn');
    if (sBtn && state.roster.length) sBtn.addEventListener('click', openStudentMenu);

    root.querySelectorAll('.sum-work[data-src-id]').forEach(el =>
        el.addEventListener('click', () => openWorkDetail(el.dataset.srcType, +el.dataset.srcId)));
}

/* T12.8: дропдауны группы/ученика — общий пикер (picker.js). */
function openGroupMenu() {
    openGroupPicker(document.getElementById('sumGroupBtn'), state.groups, state.groupId, id => {
        state.groupId = id;
        loadRoster();
    });
}

function openStudentMenu() {
    openStudentPicker(document.getElementById('sumStudentBtn'), state.roster, state.personId, id => {
        state.personId = id;
        loadSummary();
    });
}

function strip(l) {
    if (l.kind === 'individual') return 'individual';
    return l.attendance; // present | absent | none
}

function lessonCard(l) {
    const open = !!(state.data && state.data.open);
    const st = strip(l);
    const works = l.works.length
        ? `<div class="sum-works">${l.works.map(w => `
            <span class="sum-work${w.display === 'pending' ? ' pending' : ''}${w.overdue ? ' overdue' : ''}" role="button" tabindex="0"
                data-src-type="${esc(w.source_type)}" data-src-id="${w.source_id}" title="${esc(w.title)}${w.overdue ? ' — сдано после дедлайна' : ''} — открыть">
                ${w.badge ? `<b>${esc(w.badge)}</b> ` : ''}${w.display === 'pending' ? 'на проверке' : esc(w.value)}${w.overdue ? ' <span class="sum-work-late">просрочено</span>' : ''}
            </span>`).join('')}</div>`
        : '<div class="sum-works sum-works-empty">Работ нет</div>';

    return `
    <div class="sum-card">
        <span class="sum-strip sum-strip-${esc(st)}" title="${esc(ATT_LABEL[st] || KIND_LABEL[l.kind] || '')}"></span>
        <div class="sum-card-body">
            <div class="sum-card-top">
                ${l.date ? `<span class="sum-date">${esc(fmtDate(l.date))}</span>` : ''}
                <span class="sum-kind sum-kind-${esc(l.kind)}">${esc(KIND_LABEL[l.kind] || l.kind)}</span>
                ${l.kind !== 'individual' && !open ? `<span class="sum-att sum-att-${esc(l.attendance)}">${esc(ATT_LABEL[l.attendance])}</span>` : ''}
            </div>
            <div class="sum-topic">${esc(l.topic || '—')}</div>
            ${works}
        </div>
    </div>`;
}

/* ── Деталь работы + оценивание (T10.9) ──────────────────────────────── */
async function openWorkDetail(sourceType, sourceId) {
    if (!reviewApi) { toast('Оценивание недоступно'); return; }
    let d;
    try {
        d = await reviewApi('getDetail', { source_type: sourceType, source_id: sourceId });
    } catch (e) { toast(e.message); return; }
    renderDetailModal(d);
}

function closeDetailModal() {
    const m = document.getElementById('sumModal');
    if (m) m.remove();
    document.removeEventListener('keydown', onDetailEsc);
}
function onDetailEsc(e) { if (e.key === 'Escape') closeDetailModal(); }

function renderDetailModal(d) {
    closeDetailModal();
    const tasks = d.tasks.length
        ? d.tasks.map(t => taskBlock(t, d)).join('')
        : '<div class="sum-detail-empty">В работе нет задач.</div>';
    const scoreLine = (d.score !== null && d.score !== undefined)
        ? `${fmtNum(d.score)}${d.max_score != null ? ' / ' + fmtNum(d.max_score) : ''} б.`
        : 'без оценки';

    const grading = d.gradable ? `
        <div class="sum-modal-foot">
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

    const modal = document.createElement('div');
    modal.className = 'sum-modal';
    modal.id = 'sumModal';
    modal.innerHTML = `
        <div class="sum-modal-backdrop"></div>
        <div class="sum-modal-box" role="dialog" aria-modal="true">
            <div class="sum-modal-head">
                <div>
                    <div class="smh-title">${esc(d.title)}</div>
                    <div class="smh-meta" id="smhMeta">${d.kind === 'exam' ? 'Экзамен' : 'Работа'} · ${esc(STATUS_LABEL[d.status] || d.status)} · ${esc(scoreLine)}${d.is_late ? ' · <span class="smh-late">Просрочено</span>' : ''}</div>
                </div>
                <button class="sum-modal-x" aria-label="Закрыть">&times;</button>
            </div>
            <div class="sum-modal-body">
                ${tasks}
                ${d.attachment_url ? attachmentBlock(d) : ''}
                ${d.feedback ? `<div class="sum-fb"><b>Комментарий:</b> ${esc(d.feedback)}</div>` : ''}
            </div>
            ${grading}
        </div>`;
    document.body.appendChild(modal);

    modal.querySelector('.sum-modal-backdrop').addEventListener('click', closeDetailModal);
    modal.querySelector('.sum-modal-x').addEventListener('click', closeDetailModal);
    document.addEventListener('keydown', onDetailEsc);

    if (d.gradable) wireGrading(modal, d.submission_id);
    if (d.kind === 'exam' && d.attempt_id && attemptGradeApi) wireAttemptGrading(modal, d);
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
    // Пооответное оценивание экзамена (T11.9). Эпик 13 (D17): если у задачи есть
    // критерии — оценивание покритерийное (сумма сырых баллов, без весов);
    // иначе — прежний контрол «балл + верно».
    const grade = hasCriteria ? criteriaGradeBlock(t) : canGrade ? `
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

/* Пооответное оценивание попытки экзамена (T11.9). Эпик 13 (D17): критериальные
   задачи шлют criteria_scores (JSON {индекс: баллы}) вместо score/is_correct. */
function wireAttemptGrading(modal, d) {
    const meta = modal.querySelector('#smhMeta');
    modal.querySelectorAll('.sum-task-grade').forEach(box => {
        const btn = box.querySelector('.stg-save');
        const isCriteria = box.classList.contains('sum-task-grade--criteria');
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
                loadSummary();
            } catch (e) { toast(e.message); }
            btn.disabled = false;
        });
    });
}

function wireGrading(modal, submissionId) {
    const scoreEl = modal.querySelector('#grScore');
    const maxEl   = modal.querySelector('#grMax');
    const fbEl    = modal.querySelector('#grFb');

    modal.querySelector('[data-grade="save"]').addEventListener('click', async () => {
        try {
            await reviewApi('saveGrade', {
                submission_id: submissionId,
                score: scoreEl.value || '0',
                max_score: maxEl.value || '0',
                feedback: fbEl.value.trim(),
            });
            toast('Оценка сохранена');
            closeDetailModal();
            loadSummary();
        } catch (e) { toast(e.message); }
    });

    modal.querySelector('[data-grade="return"]').addEventListener('click', async () => {
        const fb = fbEl.value.trim();
        if (!fb) { toast('Укажите комментарий для возврата'); fbEl.focus(); return; }
        try {
            await reviewApi('returnSubmission', { submission_id: submissionId, feedback: fb });
            toast('Работа возвращена на доработку');
            closeDetailModal();
            loadSummary();
        } catch (e) { toast(e.message); }
    });
}

/* ── Helpers ──────────────────────────────────────────────────────────── */
function fmtDate(iso) {
    const parts = String(iso).split('-');
    if (parts.length !== 3) return iso;
    const [y, m, d] = parts;
    const dow = DOW_JS[new Date(`${y}-${m}-${d}T00:00:00`).getDay()];
    return `${d}.${m} · ${dow}`;
}

function empty(title, text) {
    return emptyState('prof-summary', icoDocCheck(34), title, text);
}
