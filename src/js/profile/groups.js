/* ══════════════════════════════════════════════════════════════════════
   Экран «Группы» (Эпик 10 T10.7) — ростер выбранной группы.
   Источник: window.fsProfile.{groups, roster:{nonce,actions}, ajax.url}.
   Показывает активных учеников (PII-safe snapshot-имена) и их индивидуальные
   занятия; здесь же создаются индивидуальные занятия (перенос из журнала).
   Клик по группе в сайдбаре открывает этот экран (см. app.js openGroupsFor).
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast, openGradePopPositioned, closeGradePop } from './utils.js';
import { createApi } from './api.js';

const AVA = ['#5c7cfa','#7048e8','#1c7ed6','#0ca678','#f08c00','#e8590c','#e64980','#9c36b5','#2f9e44','#4263eb'];
const STATUS_LABEL = { scheduled: 'запланировано', done: 'проведено', cancelled: 'отменено' };

let root = null;
let state = null;
let api = null;

export function renderGroups(r, handlers = {}) {
    root = r;
    const p = window.fsProfile || {};
    state = {
        groups:   Array.isArray(p.groups) ? p.groups : [],
        cfg:      p.roster || null,
        groupId:  (p.groups && p.groups[0]) ? p.groups[0].id : null,
        data:     null,
        handlers,
    };
    api = createApi(state.cfg);
    if (!state.groups.length || !state.cfg) { root.innerHTML = empty('Нет групп', 'За вами не закреплены группы.'); return; }
    load();
}

/** Вызывается из сайдбара (app.js) при выборе группы. */
export function setGroupsGroup(gid) {
    if (!state) return;
    state.groupId = gid;
    load();
}

async function load() {
    if (!state.groupId) { root.innerHTML = empty('Нет группы', ''); return; }
    try {
        state.data = await api('getRoster', { group_id: state.groupId });
    } catch (e) {
        root.innerHTML = empty('Не удалось загрузить ростер', e.message);
        return;
    }
    render();
}

function group() { return state.groups.find(g => g.id === state.groupId) || state.groups[0]; }

/* ── Render ───────────────────────────────────────────────────────────── */
function render() {
    const g = group();
    const d = state.data;
    const rows = d.students.length
        ? d.students.map((s, i) => studentRow(s, i)).join('')
        : '<div class="j-empty">В группе нет активных учеников.</div>';

    root.innerHTML = `
    <div class="prof-roster">
        <div class="pr-head">
            <div class="pr-head-main">
                <div class="pr-title">${esc(g.name)}</div>
                <div class="pr-sub">${esc(g.subject)} · ${d.students.length} уч.</div>
            </div>
            <div class="pr-head-actions">
                <button class="prof-btn prof-btn-sm" data-act="journal">Журнал</button>
            </div>
        </div>
        <div class="pr-list">${rows}</div>
    </div>`;

    const jbtn = root.querySelector('[data-act="journal"]');
    if (jbtn) jbtn.addEventListener('click', () => state.handlers.openJournal && state.handlers.openJournal(state.groupId));

    root.querySelectorAll('[data-add-indi]').forEach(b =>
        b.addEventListener('click', () => openIndiForm(+b.dataset.pid, b)));
}

function studentRow(s, i) {
    const indis = s.individual.length
        ? `<div class="pr-indis">${s.individual.map(x => `
            <span class="pr-indi pr-indi-${esc(x.status)}" title="${esc(STATUS_LABEL[x.status] || x.status)}">
                <span class="pr-indi-date">${x.date ? esc(fmtDate(x.date)) : '—'}</span>${x.label ? ' · ' + esc(x.label) : ''}
            </span>`).join('')}</div>`
        : '<div class="pr-indis pr-indis-empty">Индивидуальных занятий нет</div>';

    return `
    <div class="pr-row" data-pid="${s.person_id}">
        <span class="pr-ava" style="background:${AVA[i % AVA.length]}">${initials(s.name)}</span>
        <div class="pr-info">
            <div class="pr-name">${esc(s.name)}</div>
            ${indis}
        </div>
        <button class="prof-btn prof-btn-sm prof-btn-ghost" data-add-indi data-pid="${s.person_id}">＋ Индивидуальное</button>
    </div>`;
}

/* ── Создание индивидуального занятия (перенос из журнала, T10.5→T10.7) ── */
function openIndiForm(pid, anchor) {
    const pop = document.getElementById('profGradePop');
    if (!pop) return;
    const s = state.data.students.find(x => x.person_id === pid);
    const today = new Date().toISOString().slice(0, 10);

    pop.innerHTML = `
        <div class="gp-title">Инд. занятие · ${esc(s ? firstWord(s.name) : '')}</div>
        <label class="gp-field"><span>Дата</span><input type="date" id="giDate" value="${today}"></label>
        <label class="gp-field"><span>Время</span><input type="time" id="giTime" value="15:00"></label>
        <label class="gp-field"><span>Тема (необязательно)</span><input type="text" id="giLabel" placeholder="Напр. Разбор ошибок"></label>
        <div class="gp-row">
            <button class="prof-btn prof-btn-sm prof-btn-primary" data-gi="create">Создать</button>
            <button class="prof-btn prof-btn-sm" data-gi="cancel">Отмена</button>
        </div>`;

    pop.querySelector('[data-gi="cancel"]').addEventListener('click', closeGradePop);
    pop.querySelector('[data-gi="create"]').addEventListener('click', async () => {
        const date = pop.querySelector('#giDate').value;
        const time = pop.querySelector('#giTime').value || '15:00';
        const label = pop.querySelector('#giLabel').value.trim();
        if (!date) { toast('Укажите дату'); return; }
        closeGradePop();
        try {
            await api('createIndividual', {
                group_id: state.groupId,
                student_person_id: pid,
                scheduled_at: `${date} ${time}:00`,
                label,
            });
            toast('Индивидуальное занятие создано');
            load();
        } catch (err) { toast(err.message); }
    });

    openGradePopPositioned(pop, anchor);
}

/* ── Helpers ──────────────────────────────────────────────────────────── */
function initials(name) {
    return name.split(' ').filter(Boolean).map(w => w[0]).join('').slice(0, 2).toUpperCase();
}
function firstWord(name) { return name.split(' ').filter(Boolean)[0] || ''; }
function fmtDate(iso) {
    // iso "YYYY-MM-DD HH:MM" → "DD.MM HH:MM"
    const [d, t] = String(iso).split(' ');
    if (!d) return iso;
    const [, m, dd] = d.split('-');
    return `${dd}.${m}${t ? ' ' + t : ''}`;
}

function empty(title, text) {
    return `<div class="prof-roster"><div class="prof-ktp-empty">
        <div class="ke-ico"><svg width="34" height="34" viewBox="0 0 24 24" fill="none"><circle cx="9" cy="8" r="3" stroke="currentColor" stroke-width="1.6"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6M16 5a3 3 0 0 1 0 6M21 20c0-2.5-1.5-4.6-3.6-5.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></div>
        <h3>${esc(title)}</h3><p>${esc(text || '')}</p>
    </div></div>`;
}
