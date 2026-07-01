/* ══════════════════════════════════════════════════════════════════════
   Главная (Dashboard) — расписание (на всю ширину), ворклист, группы
   ══════════════════════════════════════════════════════════════════════ */

const WEEK_SCHEDULE = {
  'Понедельник': [
    { start: '09:25', group: '10 «А»', topic: 'Цикл for, range()', color: '#3b5bdb' },
    { start: '11:35', group: '9 «В»',  topic: 'Электронные таблицы', color: '#f08c00' },
  ],
  'Вторник': [
    { start: '08:30', group: '11 «А»', topic: 'Рекурсия', color: '#7048e8' },
    { start: '09:25', group: '10 «А»', topic: 'Функции, def', color: '#3b5bdb' },
    { start: '11:35', group: '10 «Б»', topic: 'Списки', color: '#0ca678' },
  ],
  'Среда': [
    { start: '10:30', group: '9 «В»',  topic: 'Условный оператор', color: '#f08c00' },
  ],
  'Четверг': [
    { start: '08:30', group: '11 «А»', topic: 'Стек и очередь', color: '#7048e8' },
    { start: '10:30', group: '10 «Б»', topic: 'Строки и срезы', color: '#0ca678' },
  ],
  'Пятница': [
    { start: '09:25', group: '10 «А»', topic: 'Контрольная работа', color: '#3b5bdb' },
    { start: '11:35', group: '9 «В»',  topic: 'Практическая', color: '#f08c00' },
  ],
};

function renderDashboard(root) {
  const totalRev = WORKLIST_REV.reduce((n, w) => n + w.count, 0);
  const totalAtt = WORKLIST_ATT.length;

  root.innerHTML = `
  <div class="dash">
    <div class="dash-hello">
      <h1>Здравствуйте, Антон Сергеевич 👋</h1>
      <p>Вторник, 23 декабря · сегодня 5 занятий · 3 работы ждут проверки</p>
    </div>

    <div class="stat-tiles">
      ${statTile('Занятий сегодня', '5', 'осталось 3', 'up', '#3b5bdb', 'cal')}
      ${statTile('На проверке', String(totalRev), '+12 за неделю', 'down', '#7048e8', 'check')}
      ${statTile('Не заполнено', String(totalAtt), 'журналов посещаемости', '', '#e03131', 'alert')}
    </div>

    <!-- Расписание на всю ширину -->
    <div class="card sched-card">
      <div class="card-head">
        <h3>Расписание</h3>
        <div class="seg sched-toggle" id="schedToggle">
          <button class="on" data-mode="today">Сегодня</button>
          <button data-mode="week">Неделя</button>
        </div>
      </div>
      <div id="schedBody"></div>
    </div>

    <div class="dash-grid2">
      <div class="card">
        <div class="card-head">
          <h3>Требует внимания</h3>
          <span class="ch-sub">${totalAtt + WORKLIST_REV.length} задач</span>
        </div>
        <div>
          ${WORKLIST_ATT.map(attRow).join('')}
          ${WORKLIST_REV.map(revRow).join('')}
        </div>
      </div>

      <div class="card">
        <div class="card-head">
          <h3>Мои группы</h3>
          <button class="btn btn-sm btn-ghost ch-act" onclick="toast('Все группы')">Все</button>
        </div>
        <div>
          ${GROUPS.map(grpCard).join('')}
        </div>
      </div>
    </div>
  </div>`;

  renderSched('today');

  const st = root.querySelector('#schedToggle');
  st.querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
    st.querySelectorAll('button').forEach(x => x.classList.remove('on'));
    b.classList.add('on');
    renderSched(b.dataset.mode);
  }));

  root.querySelectorAll('[data-grp]').forEach(el =>
    el.addEventListener('click', () => openJournalFor(el.dataset.grp)));
  root.querySelectorAll('[data-att-grp]').forEach(el =>
    el.addEventListener('click', () => openJournalFor('10a')));
}

function renderSched(mode) {
  const body = document.getElementById('schedBody');
  if (mode === 'week') {
    body.innerHTML = `<div class="week-grid">
      ${Object.entries(WEEK_SCHEDULE).map(([day, items]) => `
        <div class="week-col">
          <div class="week-dow">${day}</div>
          ${items.map(it => `<div class="week-card" style="border-left-color:${it.color}"
              onclick="${it.group === '10 «А»' ? `openJournalFor('10a')` : `toast('Занятие: ${it.group}')`}">
            <div class="wc-time">${it.start}</div>
            <div class="wc-grp">${it.group}</div>
            <div class="wc-topic">${it.topic}</div>
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
  return `<div class="stat-tile">
    <div class="st-top">
      <span class="st-ico" style="background:${color}1a;color:${color}"><svg width="16" height="16" viewBox="0 0 20 20" fill="none">${icons[ico]}</svg></span>
      ${label}
    </div>
    <div class="st-val">${val}</div>
    <div class="st-delta ${dir}">${delta}</div>
  </div>`;
}

function schedRow(l) {
  const stateMap = {
    now:  '<span class="state-pill state-now">Идёт сейчас</span>',
    soon: '<span class="state-pill state-soon">Скоро</span>',
    done: '<span class="state-pill state-done">Завершён</span>',
  };
  return `<div class="lesson-row ${l.state === 'now' ? 'is-now' : ''}" onclick="${l.group.includes('10 «А»') ? `openJournalFor('10a')` : `toast('Занятие: ${l.group}')`}">
    <div class="lesson-time"><div class="lt-start">${l.start}</div><div class="lt-end">${l.end}</div></div>
    <div class="lesson-bar" style="background:${l.color}"></div>
    <div class="lesson-body">
      <div class="lesson-grp">${l.group}</div>
      <div class="lesson-topic">${l.topic}</div>
      <div class="lesson-meta">
        <span class="lm"><svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M8 2C5.5 2 4 3.8 4 6c0 3 4 8 4 8s4-5 4-8c0-2.2-1.5-4-4-4z" stroke="currentColor" stroke-width="1.3"/><circle cx="8" cy="6" r="1.4" fill="currentColor"/></svg>${l.room}</span>
      </div>
    </div>
    <div class="lesson-state">${stateMap[l.state]}</div>
  </div>`;
}

function attRow(w) {
  return `<div class="work-item" data-att-grp="${w.group}" style="cursor:pointer">
    <div class="work-ico att"><svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M4 6h12v10H4zM4 9h12M7 4v3M13 4v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></div>
    <div class="work-main">
      <div class="work-title">Заполнить посещаемость · ${w.group}</div>
      <div class="work-sub">${w.topic} · ${w.date}</div>
    </div>
    <span class="work-count">${w.missing}</span>
    <svg class="work-cta" width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M7 5l5 5-5 5" stroke="var(--muted-2)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
  </div>`;
}

function revRow(w) {
  const wt = WORK_TYPES[w.type];
  return `<div class="work-item" onclick="toast('Проверка: ${w.work}')" style="cursor:pointer">
    <div class="work-ico rev" style="background:${wt.raw}1a;color:${wt.raw}">
      <svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M5 4h10v12l-5-2.5L5 16z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
    </div>
    <div class="work-main">
      <div class="work-title">Проверить «${w.work}»</div>
      <div class="work-sub">${w.group} · ${wt.name}</div>
    </div>
    <span class="work-count">${w.count}</span>
    <svg class="work-cta" width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M7 5l5 5-5 5" stroke="var(--muted-2)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
  </div>`;
}

function grpCard(g) {
  return `<div class="grp-card" data-grp="${g.id}">
    <span class="group-chip" style="background:${g.color}">${g.name.replace(/[«»\s]/g,'')}</span>
    <div class="group-meta">
      <div class="group-name">${g.name} · ${g.subject}</div>
      <div class="group-sub">${g.students} учеников</div>
    </div>
    <svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M7 5l5 5-5 5" stroke="var(--muted-2)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
  </div>`;
}

Object.assign(window, { renderDashboard });
