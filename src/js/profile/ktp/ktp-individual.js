/* ══════════════════════════════════════════════════════════════════════
   КТП: режим «Индивидуальные занятия» (#1).
   Дизайн повторяет групповую КТП: слева сайдбар уроков (поиск + «Уроки курса»
   / разделитель / «Все уроки предмета»), справа СКВОЗНОЙ календарь всех инд.
   занятий всех групп препода (D-1). Поток: клик по занятию в календаре →
   сайдбар грузит кандидатов этого слота → клик по уроку назначает его.

   Собственного стейта у модуля нет: ядро (ktp.js) передаёт контекст
   ctx = { root, state, api, openGroupMenu } — инд.-поля (individual,
   indiMonths, indiCursor, indiSelected, indiCandidates) живут в state ядра.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast } from '../utils.js';
import { icoChevronLeft, icoChevronRight, icoCaret } from '../../common/icons.js';
import { DOW_RU, MONTHS_RU } from '../constants.js';
import { openIndiModal } from '../indi-modal.js';
import { indiMonths, indiInitialCursor, shiftIndiMonth } from './ktp-calendar-model.js';
import { indiSlotChip, errorHtml } from './ktp-templates.js';

/* НБ-9: sentinel-id псевдо-«группы» = режим «Индивидуальные занятия». */
export const INDI_ID = -1;

async function fetchIndividual(ctx) {
    const { state, api } = ctx;
    const perGroup = await Promise.all(state.groups.map(g =>
        api('getIndividual', { group_id: g.id }).then(d => (d && d.items) || []).catch(() => [])));
    state.individual = perGroup.flat().sort((a, b) => String(a.scheduled_at).localeCompare(String(b.scheduled_at)));
    state.indiMonths = indiMonths(state.individual);
}

export async function loadIndividual(ctx) {
    const { state, root } = ctx;
    state.groupId = INDI_ID;
    state.indiSelected = null;
    state.indiCandidates = null;
    root.innerHTML = `<div class="prof-ktp"><div class="rev-loading">Загрузка…</div></div>`;
    try {
        await fetchIndividual(ctx);
    } catch (e) {
        root.innerHTML = errorHtml(e.message);
        return;
    }
    state.indiCursor = indiInitialCursor(state.indiMonths);
    renderIndividual(ctx);
}

function renderIndividual(ctx) {
    const { state, root } = ctx;
    const items = state.individual || [];
    root.innerHTML = `
    <div class="prof-ktp prof-ktp-indi">
        <div class="prof-ktp-head">
            <div class="prof-ktp-pickers">
                <div class="prof-ktp-pick">
                    <span class="kp-label">Группа</span>
                    <button type="button" class="kp-btn" id="ktpGroupBtn">
                        <span class="kp-chip chip-indi">Инд</span>
                        <span class="kp-txt">Индивидуальные занятия</span>
                        ${icoCaret(12, 'kp-caret')}
                    </button>
                </div>
            </div>
            <span class="prof-spacer"></span>
            <div class="prof-ktp-legend">
                <span class="kl"><span class="prof-dot prof-dot-accent"></span>Урок назначен</span>
                <span class="kl"><span class="prof-dot prof-dot-absent"></span>Не назначен</span>
            </div>
        </div>
        ${items.length ? `
        <div class="prof-ktp-grid">
            <div class="prof-theme-bank">
                <div class="tb-head"><h3>Уроки</h3><span class="tbh-count" id="indiBankHint"></span></div>
                <input type="text" class="indi-search" id="indiSearch" placeholder="Поиск урока по названию…" ${state.indiSelected ? '' : 'disabled'}>
                <div class="prof-theme-list" id="indiBank"></div>
            </div>
            <div class="prof-kal">
                <div class="kal-head">
                    <button class="prof-icon-ghost" id="ktpPrev">${icoChevronLeft(18)}</button>
                    <div class="kal-month" id="ktpMonth"></div>
                    <button class="prof-icon-ghost" id="ktpNext">${icoChevronRight(18)}</button>
                    <span class="prof-spacer"></span>
                    <span class="kal-hint">Клик по дню — добавить · ✎ на занятии — изменить</span>
                </div>
                <div class="kal-grid-wrap">
                    <div class="kal-dow">${DOW_RU.map(d => `<span>${d}</span>`).join('')}</div>
                    <div class="kal-grid" id="ktpGrid"></div>
                </div>
            </div>
        </div>` : `<div class="prof-indi-empty"><p>Индивидуальных занятий пока нет.</p><button class="prof-btn prof-btn-primary" id="indiAddFirst">+ Добавить занятие</button></div>`}
    </div>`;

    document.getElementById('ktpGroupBtn').onclick = ctx.openGroupMenu;
    if (!items.length) {
        const add = document.getElementById('indiAddFirst');
        if (add) { add.addEventListener('click', () => openIndiModal({ api: ctx.api, anchor: add, groups: state.groups, onSaved: () => loadIndividual(ctx) })); }
        return;
    }

    document.getElementById('ktpPrev').onclick = () => shiftIndiMonthBy(ctx, -1);
    document.getElementById('ktpNext').onclick = () => shiftIndiMonthBy(ctx, 1);
    const search = document.getElementById('indiSearch');
    let deb;
    search.addEventListener('input', () => { clearTimeout(deb); deb = setTimeout(() => loadIndiCandidates(ctx), 250); });

    renderIndiCalendar(ctx);
    renderIndiBank(ctx);
}

function shiftIndiMonthBy(ctx, d) {
    ctx.state.indiCursor = shiftIndiMonth(ctx.state.indiCursor, ctx.state.indiMonths.length, d);
    renderIndiCalendar(ctx);
}

function renderIndiCalendar(ctx) {
    const { state } = ctx;
    if (!state.indiMonths.length) return;
    const { y, m } = state.indiMonths[state.indiCursor];
    document.getElementById('ktpMonth').textContent = `${MONTHS_RU[m]} ${y}`;
    document.getElementById('ktpPrev').disabled = state.indiCursor <= 0;
    document.getElementById('ktpNext').disabled = state.indiCursor >= state.indiMonths.length - 1;

    const byDate = {};
    (state.individual || []).forEach(it => {
        const ds = String(it.scheduled_at || '').slice(0, 10);
        if (ds) (byDate[ds] = byDate[ds] || []).push(it);
    });

    const first = new Date(y, m, 1);
    const offset = (first.getDay() + 6) % 7;
    const last = new Date(y, m + 1, 0).getDate();

    let cells = '';
    for (let i = 0; i < offset; i++) cells += `<div class="kal-cell empty"></div>`;
    for (let d = 1; d <= last; d++) {
        const ds = `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        const slots = (byDate[ds] || []).sort((a, b) => String(a.scheduled_at).localeCompare(String(b.scheduled_at)));
        cells += `<div class="kal-cell${slots.length ? '' : ' no-lesson'}" data-day="${ds}">
            <div class="kal-date"><span class="kd-num">${d}</span></div>
            ${slots.map(it => indiSlotChip(it, state.indiSelected)).join('')}
        </div>`;
    }

    const grid = document.getElementById('ktpGrid');
    grid.innerHTML = cells;
    // Клик по слоту — выбрать для назначения урока (сайдбар, НБ-9); ✎ — правка (B2).
    grid.querySelectorAll('.indi-slot').forEach(el => el.addEventListener('click', (e) => {
        if (e.target.closest('.indi-edit')) { return; }
        e.stopPropagation();
        selectIndiSlot(ctx, el.dataset.glid);
    }));
    grid.querySelectorAll('.indi-edit').forEach(btn => btn.addEventListener('click', (e) => {
        e.stopPropagation();
        openEditIndi(ctx, btn.dataset.glid, btn);
    }));
    // Клик по свободному месту дня — создать инд. занятие на эту дату (B2).
    grid.querySelectorAll('.kal-cell[data-day]').forEach(cell => cell.addEventListener('click', (e) => {
        if (e.target.closest('.indi-slot')) { return; }
        openAddIndi(ctx, cell.dataset.day, cell);
    }));
}

// B2: создать инд. занятие на выбранную дату календаря (группа/ученик — в модалке).
function openAddIndi(ctx, ds, anchor) {
    openIndiModal({
        api: ctx.api,
        anchor,
        groups: ctx.state.groups,
        fixed: { date: ds },
        onSaved: () => loadIndividual(ctx),
    });
}

// B2: правка инд. занятия (группа фиксирована, ученик/дата/время/кабинет/тема — меняются).
function openEditIndi(ctx, glid, anchor) {
    const slot = (ctx.state.individual || []).find(x => String(x.group_lesson_id) === String(glid));
    if (!slot) { return; }
    const parts = String(slot.scheduled_at || '').split(' ');
    openIndiModal({
        api: ctx.api,
        anchor,
        groups: ctx.state.groups,
        edit: {
            glid: slot.group_lesson_id,
            group_id: slot.group_id,
            student_person_id: slot.student_person_id,
            student_name: slot.student_name,
            date: parts[0] || '',
            time: parts[1] ? parts[1].slice(0, 5) : '15:00',
            time_end: (String(slot.ends_at || '').split(' ')[1] || '').slice(0, 5),
            room_id: slot.room_id || '',
            room_name: slot.room || '',
            lesson_id: slot.lesson_id || '',
        },
        onSaved: () => loadIndividual(ctx),
    });
}

function selectIndiSlot(ctx, glid) {
    ctx.state.indiSelected = glid;
    ctx.state.indiCandidates = null;
    const search = document.getElementById('indiSearch');
    if (search) { search.disabled = false; search.value = ''; }
    renderIndiCalendar(ctx); // подсветить выбранный слот
    renderIndiBank(ctx);     // показать «Загрузка…»
    loadIndiCandidates(ctx);
}

async function loadIndiCandidates(ctx) {
    const { state, api } = ctx;
    const slot = (state.individual || []).find(x => String(x.group_lesson_id) === String(state.indiSelected));
    const bank = document.getElementById('indiBank');
    if (!slot || !bank) return;
    const q = (document.getElementById('indiSearch')?.value || '').trim();
    bank.innerHTML = '<div class="pil-empty">Загрузка…</div>';
    try {
        const d = await api('lessonCandidates', { group_id: slot.group_id, search: q });
        state.indiCandidates = (d && d.lessons) || [];
        renderIndiBank(ctx);
    } catch (e) { bank.innerHTML = `<div class="pil-empty">${esc(e.message)}</div>`; }
}

function renderIndiBank(ctx) {
    const { state } = ctx;
    const bank = document.getElementById('indiBank');
    if (!bank) return;
    const hint = document.getElementById('indiBankHint');
    const slot = (state.individual || []).find(x => String(x.group_lesson_id) === String(state.indiSelected));

    if (!slot) {
        bank.innerHTML = '<div class="pil-empty">Выберите занятие в календаре, чтобы назначить урок.</div>';
        if (hint) hint.textContent = '';
        return;
    }
    if (hint) hint.textContent = slot.student_name || '';
    if (state.indiCandidates === null) { bank.innerHTML = '<div class="pil-empty">Загрузка…</div>'; return; }

    bank.innerHTML = renderLessonCandidates(state.indiCandidates, slot.lesson_id);
    bank.querySelectorAll('.pil-item').forEach(el => el.addEventListener('click', () => assignIndiLesson(ctx, el.dataset.lid)));
}

async function assignIndiLesson(ctx, lid) {
    const glid = ctx.state.indiSelected;
    if (!glid) return;
    try {
        await ctx.api('assignLesson', { group_lesson_id: glid, lesson_id: lid });
        toast('Урок назначен');
        await fetchIndividual(ctx);   // обновить темы/lesson_id слотов
        renderIndiCalendar(ctx);      // перерисовать календарь с новым уроком
        renderIndiBank(ctx);          // обновить пометку «текущий»
    } catch (e) { toast(e.message, 'error'); }
}

function renderLessonCandidates(lessons, currentId) {
    if (!lessons.length) return '<div class="pil-empty">Уроки не найдены.</div>';
    const section = (title, list) => list.length
        ? `<div class="pil-divider">${esc(title)}</div>` + list.map(l =>
            `<div class="pil-item${String(l.id) === String(currentId) ? ' current' : ''}" data-lid="${l.id}">${esc(l.title || 'Без названия')}</div>`).join('')
        : '';
    return section('Уроки курса', lessons.filter(l => l.in_course)) + section('Все уроки предмета', lessons.filter(l => !l.in_course));
}
