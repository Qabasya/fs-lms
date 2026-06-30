import { GROUPS, TODAY_LESSONS, WORKLIST_ATT, WORKLIST_REV, WEEK_SCHEDULE, WORK_TYPES } from './data.js';
import { esc, toast } from './utils.js';

export function renderDashboard(root, { openJournalFor }) {
    const totalRev = WORKLIST_REV.reduce((n, w) => n + w.count, 0);
    const totalAtt = WORKLIST_ATT.length;

    root.innerHTML = `
    <div class="prof-dash">
        <div class="prof-dash-hello">
            <h1>Здравствуйте, ${esc(window.fsProfile?.user?.name || 'преподаватель')} 👋</h1>
            <p>Сегодня ${TODAY_LESSONS.length} занятий · ${WORKLIST_REV.length} работы ждут проверки</p>
        </div>

        <div class="prof-stat-tiles">
            ${statTile('Занятий сегодня', String(TODAY_LESSONS.length), 'осталось 3', 'up', '#3b5bdb', 'cal')}
            ${statTile('На проверке', String(totalRev), '+12 за неделю', 'down', '#7048e8', 'check')}
            ${statTile('Не заполнено', String(totalAtt), 'журналов посещаемости', '', '#e03131', 'alert')}
        </div>

        <div class="prof-card prof-sched-card">
            <div class="prof-card-head">
                <h3>Расписание</h3>
                <div class="prof-seg" id="profSchedToggle" style="margin-left:auto">
                    <button class="on" data-mode="today">Сегодня</button>
                    <button data-mode="week">Неделя</button>
                </div>
            </div>
            <div id="profSchedBody"></div>
        </div>

        <div class="prof-dash-grid2">
            <div class="prof-card">
                <div class="prof-card-head">
                    <h3>Требует внимания</h3>
                    <span class="ch-sub">${totalAtt + WORKLIST_REV.length} задач</span>
                </div>
                <div>
                    ${WORKLIST_ATT.map(attRow).join('')}
                    ${WORKLIST_REV.map(revRow).join('')}
                </div>
            </div>
            <div class="prof-card">
                <div class="prof-card-head">
                    <h3>Мои группы</h3>
                </div>
                <div>
                    ${GROUPS.map(grpCard).join('')}
                </div>
            </div>
        </div>
    </div>`;

    renderSched('today');

    const toggle = root.querySelector('#profSchedToggle');
    toggle.querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
        toggle.querySelectorAll('button').forEach(x => x.classList.remove('on'));
        b.classList.add('on');
        renderSched(b.dataset.mode);
    }));

    root.querySelectorAll('[data-grp]').forEach(el =>
        el.addEventListener('click', () => openJournalFor(el.dataset.grp)));
    root.querySelectorAll('[data-att-grp]').forEach(el =>
        el.addEventListener('click', () => openJournalFor('10a')));
}

function renderSched(mode) {
    const body = document.getElementById('profSchedBody');
    if (!body) return;
    if (mode === 'week') {
        body.innerHTML = `<div class="prof-week-grid">
            ${Object.entries(WEEK_SCHEDULE).map(([day, items]) => `
                <div class="prof-week-col">
                    <div class="prof-week-dow">${esc(day)}</div>
                    ${items.map(it => `<div class="prof-week-card" style="border-left-color:${it.color}"
                            data-grp="${it.group === '10 «А»' ? '10a' : ''}">
                        <div class="wc-time">${esc(it.start)}</div>
                        <div class="wc-grp">${esc(it.group)}</div>
                        <div class="wc-topic">${esc(it.topic)}</div>
                    </div>`).join('')}
                </div>`).join('')}
        </div>`;
    } else {
        body.innerHTML = TODAY_LESSONS.map(schedRow).join('');
    }
}

function statTile(label, val, delta, dir, color, ico) {
    const icons = {
        cal:   '<path d="M4 6h12v10H4zM4 9h12M7 4v3M13 4v3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
        check: '<path d="M4 10.5 8 14l8-8.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
        alert: '<path d="M10 4v7M10 14.5v.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>',
    };
    return `<div class="prof-stat-tile">
        <div class="st-top">
            <span class="st-ico" style="background:${color}1a;color:${color}"><svg width="16" height="16" viewBox="0 0 20 20" fill="none">${icons[ico]}</svg></span>
            ${esc(label)}
        </div>
        <div class="st-val">${esc(val)}</div>
        <div class="st-delta prof-st-delta ${dir}">${esc(delta)}</div>
    </div>`;
}

function schedRow(l) {
    const stateMap = {
        now:  '<span class="prof-state-pill prof-state-now">Идёт сейчас</span>',
        soon: '<span class="prof-state-pill prof-state-soon">Скоро</span>',
        done: '<span class="prof-state-pill prof-state-done">Завершён</span>',
    };
    const grp = l.group === '10 «А»' ? '10a' : '';
    return `<div class="prof-lesson-row ${l.state === 'now' ? 'is-now' : ''}" ${grp ? `data-grp="${grp}"` : ''}>
        <div class="prof-lesson-time">
            <div class="lt-start">${esc(l.start)}</div>
            <div class="lt-end">${esc(l.end)}</div>
        </div>
        <div class="prof-lesson-bar" style="background:${l.color}"></div>
        <div class="prof-lesson-body">
            <div class="prof-lesson-grp">${esc(l.group)}</div>
            <div class="prof-lesson-topic">${esc(l.topic)}</div>
            <div class="prof-lesson-meta">
                <span class="lm"><svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M8 2C5.5 2 4 3.8 4 6c0 3 4 8 4 8s4-5 4-8c0-2.2-1.5-4-4-4z" stroke="currentColor" stroke-width="1.3"/><circle cx="8" cy="6" r="1.4" fill="currentColor"/></svg>${esc(l.room)}</span>
            </div>
        </div>
        <div class="prof-lesson-state">${stateMap[l.state]}</div>
    </div>`;
}

function attRow(w) {
    return `<div class="prof-work-item" data-att-grp="${esc(w.group)}" style="cursor:pointer">
        <div class="prof-work-ico att"><svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M4 6h12v10H4zM4 9h12M7 4v3M13 4v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></div>
        <div class="prof-work-main">
            <div class="prof-work-title">Заполнить посещаемость · ${esc(w.group)}</div>
            <div class="prof-work-sub">${esc(w.topic)} · ${esc(w.date)}</div>
        </div>
        <span class="prof-work-count">${w.missing}</span>
        <svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M7 5l5 5-5 5" stroke="var(--muted-2)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </div>`;
}

function revRow(w) {
    const wt = WORK_TYPES[w.type];
    return `<div class="prof-work-item" style="cursor:pointer">
        <div class="prof-work-ico rev" style="background:${wt.raw}1a;color:${wt.raw}">
            <svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M5 4h10v12l-5-2.5L5 16z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
        </div>
        <div class="prof-work-main">
            <div class="prof-work-title">Проверить «${esc(w.work)}»</div>
            <div class="prof-work-sub">${esc(w.group)} · ${esc(wt.name)}</div>
        </div>
        <span class="prof-work-count">${w.count}</span>
        <svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M7 5l5 5-5 5" stroke="var(--muted-2)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </div>`;
}

function grpCard(g) {
    return `<div class="prof-grp-card" data-grp="${g.id}">
        <span class="prof-group-chip" style="background:${g.color}">${esc(g.name.replace(/[«»\s]/g,''))}</span>
        <div class="prof-group-meta">
            <div class="prof-group-name">${esc(g.name)} · ${esc(g.subject)}</div>
            <div class="prof-group-sub">${g.students} учеников</div>
        </div>
        <svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M7 5l5 5-5 5" stroke="var(--muted-2)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </div>`;
}
