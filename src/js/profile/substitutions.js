/* ══════════════════════════════════════════════════════════════════════
   Замены (офис, Эпики 5+9) — одно решение = одна форма: КОГО заменяем,
   КТО заменяет, В КАКОМ кабинете и НА КАКОЙ период. Педагог пишется в
   substitutions, кабинет — override group_lessons.room_id за тот же период.
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
        data:    null,   // { substitutions, group_teacher, teachers, rooms }
    };
    api = createApi(state.cfg);
    if (!state.groups.length || !state.cfg) { root.innerHTML = emptyHtml('Нет групп', 'Нет групп для управления заменами.'); return; }
    load();
}

/**
 * Первая загрузка тянет всё (getData), смена группы — только её замены
 * (getGroupSubs): списки преподавателей и кабинетов от группы не зависят.
 */
async function load(groupOnly = false) {
    if (!state.groupId) { root.innerHTML = emptyHtml('Нет группы', ''); return; }
    root.innerHTML = wrap('<div class="rev-loading">Загрузка…</div>');
    try {
        if (groupOnly && state.data) {
            const d = await api('getGroupSubs', { group_id: state.groupId });
            state.data = Object.assign({}, state.data, {
                substitutions: d.substitutions,
                group_teacher: d.group_teacher,
            });
        } else {
            state.data = await api('getData', { group_id: state.groupId });
        }
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
            ${formCard()}
            ${listCard()}
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

/** Единая форма замены: педагог + кабинет + период одним решением. */
function formCard() {
    const d = state.data;
    const teacher = d.group_teacher;
    const teacherOpts = d.teachers
        .filter(t => !teacher || t.id !== teacher.id)   // сам себя заменить не может
        .map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join('');
    const roomOpts = d.rooms.map(r => `<option value="${r.id}">${esc(r.name)}</option>`).join('');

    const who = teacher
        ? `<div class="subs-who">Заменяем: <b>${esc(teacher.name)}</b> <span class="subs-who-note">— штатный преподаватель группы</span></div>`
        : '<div class="subs-who subs-who--warn">У группы не назначен преподаватель — заменять некого. Назначьте преподавателя в карточке группы.</div>';

    const disabled = teacher ? '' : ' disabled';

    return `
    <div class="prof-card">
        <div class="prof-card-head"><h3>Назначить замену</h3><span class="ch-sub">болезнь / отпуск / ремонт кабинета</span></div>
        ${who}
        <form class="subs-form" data-form="sub">
            <label class="subs-field subs-field--grow"><span>Замещающий</span>
                <select name="substitute_teacher_id" required${disabled}>
                    <option value="">— выберите —</option>${teacherOpts}
                </select>
            </label>
            <label class="subs-field subs-field--grow"><span>Кабинет на период</span>
                <select name="room_id"${disabled}>
                    <option value="">— не менять —</option>${roomOpts}
                </select>
            </label>
            <label class="subs-field"><span>С</span><input type="date" name="valid_from" value="${todayStr()}" required${disabled}></label>
            <label class="subs-field"><span>По</span><input type="date" name="valid_to" value="${todayStr(14)}" required${disabled}></label>
            <input type="text" class="subs-reason" name="reason" placeholder="Причина (необязательно)"${disabled}>
            <div class="subs-error" hidden></div>
            <div class="subs-actions">
                <button type="button" class="prof-btn prof-btn-sm" data-act="clear-room"${disabled}>Вернуть кабинет группы</button>
                <button type="submit" class="prof-btn prof-btn-sm prof-btn-primary"${disabled}>Назначить замену</button>
            </div>
        </form>
    </div>`;
}

/** История замен группы: активные, запланированные и завершённые. */
function listCard() {
    const list = state.data.substitutions.length
        ? state.data.substitutions.map(subRow).join('')
        : '<div class="rev-empty">Замен по этой группе не было.</div>';
    return `
    <div class="prof-card">
        <div class="prof-card-head"><h3>Замены группы</h3><span class="ch-sub">история и активные</span></div>
        <div class="subs-list">${list}</div>
    </div>`;
}

/** Статус замены по датам — вычисляется на клиенте, в БД не хранится. */
function status(s) {
    const today = todayStr();
    if (s.valid_to < today) { return { key: 'done', label: 'Завершена' }; }
    if (s.valid_from > today) { return { key: 'planned', label: 'Запланирована' }; }
    return { key: 'active', label: 'Активна' };
}

function subRow(s) {
    const st = status(s);
    const who = s.original_teacher_name ? `${esc(s.original_teacher_name)} → ` : '';
    return `<div class="subs-row" data-sub="${s.id}">
        <div class="subs-row-main">
            <div class="subs-row-title">${who}${esc(s.substitute_teacher_name || ('#' + s.substitute_teacher_id))}</div>
            <div class="subs-row-sub">${fmt(s.valid_from)} — ${fmt(s.valid_to)}${s.reason ? ' · ' + esc(s.reason) : ''}</div>
        </div>
        <span class="subs-badge subs-badge--${st.key}">${st.label}</span>
        <button class="prof-btn prof-btn-sm subs-revoke" data-revoke="${s.id}">Снять</button>
    </div>`;
}

/* ── Interactions ─────────────────────────────────────────────────────── */
function wire() {
    const btn = root.querySelector('#subsGroupBtn');
    if (btn) btn.addEventListener('click', openGroupMenu);

    const form = root.querySelector('[data-form="sub"]');
    if (form) {
        form.addEventListener('submit', assign);
        form.querySelector('[data-act="clear-room"]').addEventListener('click', () => clearRoom(form));
    }

    root.querySelectorAll('[data-revoke]').forEach(b =>
        b.addEventListener('click', () => revoke(+b.dataset.revoke)));
}

function openGroupMenu() {
    openGroupPicker(root.querySelector('#subsGroupBtn'), state.groups, state.groupId, id => {
        state.groupId = id;
        load(true);
    });
}

function showError(form, message) {
    const box = form.querySelector('.subs-error');
    if (!box) { toast(message, 'error'); return; }
    box.textContent = message;
    box.hidden = false;
}

function clearError(form) {
    const box = form.querySelector('.subs-error');
    if (box) { box.hidden = true; box.textContent = ''; }
}

/** Клиентские проверки — зеркало серверных (SubstitutionService::assign). */
function validate(val) {
    if (!state.data.group_teacher) { return 'У группы нет преподавателя — заменять некого.'; }
    if (!val.substitute) { return 'Выберите замещающего преподавателя.'; }
    if (+val.substitute === state.data.group_teacher.id) { return 'Замещающий совпадает с преподавателем группы.'; }
    if (!val.from || !val.to) { return 'Укажите период замены.'; }
    if (val.from > val.to) { return 'Дата начала позже даты окончания.'; }
    if (val.to < todayStr()) { return 'Период уже прошёл — выберите дату не раньше сегодняшней.'; }

    const clash = state.data.substitutions.find(s => s.valid_from <= val.to && s.valid_to >= val.from);
    if (clash) { return `Период пересекается с заменой ${fmt(clash.valid_from)} — ${fmt(clash.valid_to)}. Снимите её или измените даты.`; }

    return null;
}

function readForm(form) {
    return {
        substitute: form.querySelector('[name="substitute_teacher_id"]').value,
        roomId:     form.querySelector('[name="room_id"]').value,
        from:       form.querySelector('[name="valid_from"]').value,
        to:         form.querySelector('[name="valid_to"]').value,
        reason:     form.querySelector('[name="reason"]').value,
    };
}

async function assign(e) {
    e.preventDefault();
    const form = e.currentTarget;
    const val = readForm(form);

    clearError(form);
    const invalid = validate(val);
    if (invalid) { showError(form, invalid); return; }

    try {
        await api('assign', {
            group_id: state.groupId,
            substitute_teacher_id: val.substitute,
            valid_from: val.from,
            valid_to: val.to,
            reason: val.reason,
        });
    } catch (err) { showError(form, err.message); return; }

    // Кабинет — часть того же решения, но отдельный вызов: замена педагога уже
    // сохранена, поэтому сбой по кабинету не откатывает её, а сообщается отдельно.
    let roomNote = '';
    if (val.roomId) {
        try {
            const res = await api('setRoom', { group_id: state.groupId, room_id: val.roomId, valid_from: val.from, valid_to: val.to });
            roomNote = res.warnings && res.warnings.length
                ? ' · ' + res.warnings.join('; ')
                : ` · кабинет заменён на ${res.applied} занятиях`;
        } catch (err) {
            toast('Замена назначена, но кабинет не заменён: ' + err.message, 'error');
            load(true);
            return;
        }
    }

    toast('Замена назначена' + roomNote);
    load(true);
}

async function clearRoom(form) {
    const val = readForm(form);
    clearError(form);
    if (!val.from || !val.to) { showError(form, 'Укажите период, за который вернуть кабинет группы.'); return; }

    try {
        const res = await api('setRoom', { group_id: state.groupId, room_id: '', valid_from: val.from, valid_to: val.to });
        toast(res.warnings && res.warnings.length ? res.warnings.join('; ') : `Кабинет группы возвращён (${res.applied})`);
    } catch (err) { showError(form, err.message); }
}

async function revoke(id) {
    try {
        await api('revoke', { substitution_id: id });
        toast('Замена снята');
        load(true);
    } catch (err) { toast(err.message, 'error'); }
}

/* ── Helpers ──────────────────────────────────────────────────────────── */
function fmt(s) { if (!s) return ''; const p = String(s).slice(0, 10).split('-'); return p.length === 3 ? `${p[2]}.${p[1]}.${p[0]}` : s; }

function emptyHtml(title, text) {
    return emptyState('prof-subs', icoSwap(34), title, text);
}
