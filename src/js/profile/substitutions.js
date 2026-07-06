/* ══════════════════════════════════════════════════════════════════════
   Замены (офис, Эпики 5+9) — единый экран: замена ПЕДАГОГА (substitutions)
   и замена КАБИНЕТА на период (group_lessons.room_id override).
   Источник: window.fsProfile.substitutions:{nonce,actions} + groups.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast, emptyState } from './utils.js';
import { icoCaret, icoSwap } from '../common/icons.js';
import { createApi } from './api.js';
import { openGroupPicker } from './picker.js';

let root = null;
let state = null;
let api = null;

export function renderSubstitutions(r) {
    root = r;
    const p = window.fsProfile || {};
    state = {
        groups:  Array.isArray(p.groups) ? p.groups : [],
        cfg:     p.substitutions || null,
        groupId: (p.groups && p.groups[0]) ? p.groups[0].id : null,
        data:    null,
    };
    api = createApi(state.cfg);
    if (!state.groups.length || !state.cfg) { root.innerHTML = emptyHtml('Нет групп', 'Нет групп для управления заменами.'); return; }
    load();
}

async function load() {
    if (!state.groupId) { root.innerHTML = emptyHtml('Нет группы', ''); return; }
    root.innerHTML = wrap('<div class="rev-loading">Загрузка…</div>');
    try {
        state.data = await api('getData', { group_id: state.groupId });
    } catch (e) {
        root.innerHTML = wrap(`<div class="rev-empty">${esc(e.message)}</div>`);
        return;
    }
    render();
}

function group() { return state.groups.find(g => g.id === state.groupId) || state.groups[0]; }
function todayStr(offsetDays = 0) {
    const d = new Date(); d.setDate(d.getDate() + offsetDays);
    return d.toISOString().slice(0, 10);
}

/* ── Render ───────────────────────────────────────────────────────────── */
function render() {
    root.innerHTML = wrap(`
        <div class="subs-grid">
            ${teacherCard()}
            ${roomCard()}
        </div>`);
    wire();
}

function wrap(inner) {
    const g = group();
    return `
    <div class="prof-subs">
        <div class="subs-head">
            <div class="subs-title">Замены</div>
            <button class="prof-btn prof-btn-sm subs-group-btn" id="subsGroupBtn">
                ${esc(g ? g.name + ' · ' + g.subject : 'Группа')}
                ${icoCaret(12)}
            </button>
        </div>
        ${inner}
    </div>`;
}

function teacherCard() {
    const d = state.data;
    const opts = d.teachers.map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join('');
    const list = d.substitutions.length
        ? d.substitutions.map(subRow).join('')
        : '<div class="rev-empty">Активных замен преподавателя нет.</div>';
    return `
    <div class="prof-card">
        <div class="prof-card-head"><h3>Замена преподавателя</h3><span class="ch-sub">болезнь / отпуск</span></div>
        <div class="subs-list">${list}</div>
        <form class="subs-form" data-form="teacher">
            <label class="subs-field subs-field--grow"><span>Замещающий</span>
                <select name="substitute_teacher_id" required><option value="">— выберите —</option>${opts}</select>
            </label>
            <label class="subs-field"><span>С</span><input type="date" name="valid_from" value="${todayStr()}" required></label>
            <label class="subs-field"><span>По</span><input type="date" name="valid_to" value="${todayStr(14)}" required></label>
            <input type="text" class="subs-reason" name="reason" placeholder="Причина (необязательно)">
            <button type="submit" class="prof-btn prof-btn-sm prof-btn-primary">Назначить замену</button>
        </form>
    </div>`;
}

function subRow(s) {
    return `<div class="subs-row" data-sub="${s.id}">
        <div class="subs-row-main">
            <div class="subs-row-title">${esc(s.substitute_teacher_name || ('#' + s.substitute_teacher_id))}</div>
            <div class="subs-row-sub">${fmt(s.valid_from)} — ${fmt(s.valid_to)}${s.reason ? ' · ' + esc(s.reason) : ''}${s.original_teacher_name ? ' · вместо ' + esc(s.original_teacher_name) : ''}</div>
        </div>
        <button class="prof-btn prof-btn-sm subs-revoke" data-revoke="${s.id}">Снять</button>
    </div>`;
}

function roomCard() {
    const d = state.data;
    const opts = d.rooms.map(r => `<option value="${r.id}">${esc(r.name)}</option>`).join('');
    return `
    <div class="prof-card">
        <div class="prof-card-head"><h3>Замена кабинета</h3><span class="ch-sub">ремонт / недоступен</span></div>
        <p class="subs-note">Проставит выбранный кабинет всем групповым занятиям в период. «Снять» — вернёт кабинет группы по умолчанию.</p>
        <form class="subs-form" data-form="room">
            <label class="subs-field subs-field--grow"><span>Кабинет</span>
                <select name="room_id"><option value="">— кабинет группы —</option>${opts}</select>
            </label>
            <label class="subs-field"><span>С</span><input type="date" name="valid_from" value="${todayStr()}" required></label>
            <label class="subs-field"><span>По</span><input type="date" name="valid_to" value="${todayStr(14)}" required></label>
            <div class="subs-actions">
                <button type="submit" class="prof-btn prof-btn-sm prof-btn-primary" data-room-act="set">Заменить</button>
                <button type="button" class="prof-btn prof-btn-sm" data-room-act="clear">Снять</button>
            </div>
        </form>
    </div>`;
}

/* ── Interactions ─────────────────────────────────────────────────────── */
function wire() {
    const btn = root.querySelector('#subsGroupBtn');
    if (btn) btn.addEventListener('click', openGroupMenu);

    const tForm = root.querySelector('[data-form="teacher"]');
    if (tForm) tForm.addEventListener('submit', assignTeacher);

    root.querySelectorAll('[data-revoke]').forEach(b =>
        b.addEventListener('click', () => revokeTeacher(+b.dataset.revoke)));

    const rForm = root.querySelector('[data-form="room"]');
    if (rForm) {
        rForm.addEventListener('submit', e => { e.preventDefault(); setRoom(rForm, false); });
        rForm.querySelector('[data-room-act="clear"]').addEventListener('click', () => setRoom(rForm, true));
    }
}

function openGroupMenu() {
    openGroupPicker(root.querySelector('#subsGroupBtn'), state.groups, state.groupId, id => {
        state.groupId = id;
        load();
    });
}

async function assignTeacher(e) {
    e.preventDefault();
    const f = e.currentTarget;
    const sub = f.querySelector('[name="substitute_teacher_id"]').value;
    if (!sub) { toast('Выберите замещающего'); return; }
    try {
        await api('assign', {
            group_id: state.groupId,
            substitute_teacher_id: sub,
            valid_from: f.querySelector('[name="valid_from"]').value,
            valid_to: f.querySelector('[name="valid_to"]').value,
            reason: f.querySelector('[name="reason"]').value,
        });
        toast('Замена преподавателя назначена');
        load();
    } catch (err) { toast(err.message); }
}

async function revokeTeacher(id) {
    try {
        await api('revoke', { substitution_id: id });
        toast('Замена снята');
        load();
    } catch (err) { toast(err.message); }
}

async function setRoom(form, clear) {
    const from = form.querySelector('[name="valid_from"]').value;
    const to = form.querySelector('[name="valid_to"]').value;
    if (!from || !to) { toast('Укажите период'); return; }
    const roomId = clear ? '' : form.querySelector('[name="room_id"]').value;
    try {
        const res = await api('setRoom', { group_id: state.groupId, room_id: roomId, valid_from: from, valid_to: to });
        const msg = clear ? `Кабинет возвращён (${res.applied})` : `Кабинет заменён на ${res.applied} занятиях`;
        toast(res.warnings && res.warnings.length ? res.warnings.join('; ') : msg);
    } catch (err) { toast(err.message); }
}

/* ── Helpers ──────────────────────────────────────────────────────────── */
function fmt(s) { if (!s) return ''; const p = String(s).slice(0, 10).split('-'); return p.length === 3 ? `${p[2]}.${p[1]}.${p[0]}` : s; }

function emptyHtml(title, text) {
    return emptyState('prof-subs', icoSwap(34), title, text);
}
