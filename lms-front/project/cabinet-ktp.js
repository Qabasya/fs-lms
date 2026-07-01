/* ══════════════════════════════════════════════════════════════════════
   КТП / Расписание
   1) Выбор целевой группы → 2) назначение курса группе →
   3) перетаскивание тем курса на занятия календаря (с автораспределением)
   ══════════════════════════════════════════════════════════════════════ */

// ── Курсы и их темы ───────────────────────────────────────────────────
const KTP_COURSES = {
  py: {
    name: 'Python — основы программирования',
    themes: KTP_THEMES,
  },
  inf9: {
    name: 'Информатика, 9 класс',
    themes: [
      { id: 'i1', n: 1, title: 'Информация и информационные процессы', hours: 2 },
      { id: 'i2', n: 2, title: 'Системы счисления', hours: 2 },
      { id: 'i3', n: 3, title: 'Самостоятельная: перевод чисел', hours: 1, work: 'sam' },
      { id: 'i4', n: 4, title: 'Логические операции', hours: 2 },
      { id: 'i5', n: 5, title: 'Таблицы истинности', hours: 2 },
      { id: 'i6', n: 6, title: 'Практическая: логические схемы', hours: 2, work: 'prakt' },
      { id: 'i7', n: 7, title: 'Кодирование информации', hours: 2 },
      { id: 'i8', n: 8, title: 'Контрольная работа за четверть', hours: 1, work: 'contr' },
    ],
  },
  alg: {
    name: 'Алгоритмы и структуры данных',
    themes: [
      { id: 'a1', n: 1, title: 'Введение в алгоритмы. Сложность', hours: 2 },
      { id: 'a2', n: 2, title: 'Линейный и бинарный поиск', hours: 2 },
      { id: 'a3', n: 3, title: 'Сортировки: пузырёк, выбор', hours: 2 },
      { id: 'a4', n: 4, title: 'Практическая: сортировки', hours: 2, work: 'prakt' },
      { id: 'a5', n: 5, title: 'Стек, очередь, дек', hours: 2 },
      { id: 'a6', n: 6, title: 'Зачёт по структурам данных', hours: 1, work: 'zachet' },
    ],
  },
};

// ── Состояние ─────────────────────────────────────────────────────────
let ktpRoot = null;
let ktpGroup = '10a';                                   // целевая группа
let groupCourse = { '10a': 'py', '11a': 'alg' };        // группа → курс (10б, 9в — не назначены)
let pinsByGroup = { '10a': Object.assign({}, KTP_CONFIG.pins) }; // закрепления по группам
let ktpPlacement = {};                                  // day → themeId (вычисляется)

function activeCourseId() { return groupCourse[ktpGroup] || null; }
function activeThemes() { const c = activeCourseId(); return c ? KTP_COURSES[c].themes : []; }
function activePins() { if (!pinsByGroup[ktpGroup]) pinsByGroup[ktpGroup] = {}; return pinsByGroup[ktpGroup]; }

// ── Дни занятий месяца ────────────────────────────────────────────────
function lessonDaysOf() {
  const { year, month, lessonDows, holidays } = KTP_CONFIG;
  const holDays = new Set(holidays.map(h => h.day));
  const last = new Date(year, month + 1, 0).getDate();
  const days = [];
  for (let d = 1; d <= last; d++) {
    const jsDow = new Date(year, month, d).getDay(); // Sun=0 … Sat=6 (Вт=2, Пт=5)
    if (lessonDows.includes(jsDow) && !holDays.has(d)) days.push(d);
  }
  return days;
}

// пересчёт раскладки: закреплённые темы на своих днях, остальные — по порядку
function reflow() {
  const slots = lessonDaysOf();
  const themes = activeThemes();
  const pins = activePins();
  const placement = {}, placedTheme = {};
  const slotSet = new Set(slots);
  Object.entries(pins).forEach(([tid, day]) => {
    if (slotSet.has(day) && themes.some(t => t.id === tid)) { placement[day] = tid; placedTheme[tid] = day; }
  });
  let p = 0;
  for (const th of themes) {
    if (placedTheme[th.id] != null) continue;
    while (p < slots.length && placement[slots[p]] != null) p++;
    if (p >= slots.length) break;
    placement[slots[p]] = th.id; placedTheme[th.id] = slots[p]; p++;
  }
  ktpPlacement = placement;
  return placedTheme;
}

// ── Рендер экрана ─────────────────────────────────────────────────────
function renderKTP(root) {
  ktpRoot = root;
  const g = GROUPS.find(x => x.id === ktpGroup);
  const courseId = activeCourseId();
  const course = courseId ? KTP_COURSES[courseId] : null;
  const { year, month } = KTP_CONFIG;

  root.innerHTML = `
  <div class="ktp">
    <div class="ktp-head">
      <div class="ktp-pickers">
        <div class="ktp-pick">
          <span class="kp-label">Группа</span>
          <button class="kp-btn" id="ktpGroupBtn">
            <span class="kp-chip" style="background:${g.color}">${g.name.replace(/[«»\s]/g,'')}</span>
            <span class="kp-txt">${g.name} · ${g.subject}</span>
            <svg class="kp-caret" width="12" height="12" viewBox="0 0 12 12"><path d="M3 4.5 6 8l3-3.5z" fill="currentColor"/></svg>
          </button>
        </div>
        <svg class="kp-arrow" width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M5 10h10m0 0-4-4m4 4-4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <div class="ktp-pick">
          <span class="kp-label">Курс</span>
          <button class="kp-btn ${course ? '' : 'kp-empty'}" id="ktpCourseBtn">
            <span class="kp-txt">${course ? esc(course.name) : 'Назначить курс…'}</span>
            <svg class="kp-caret" width="12" height="12" viewBox="0 0 12 12"><path d="M3 4.5 6 8l3-3.5z" fill="currentColor"/></svg>
          </button>
        </div>
      </div>
      <span style="flex:1"></span>
      ${course ? `
        <div class="ktp-legend">
          <span class="kl"><span class="dot" style="background:var(--accent)"></span>Тема по плану</span>
          <span class="kl"><span class="dot" style="background:var(--t-zachet)"></span>Закреплено</span>
          <span class="kl"><span class="dot" style="background:var(--absent)"></span>Выходной</span>
        </div>
        <button class="btn btn-sm" id="ktpReset">Сбросить</button>
        <button class="btn btn-sm btn-primary" id="ktpAuto">
          <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M4 7h9m0 0-3-3m3 3-3 3M16 13H7m0 0 3-3m-3 3 3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
          Распределить
        </button>` : ''}
    </div>

    ${course ? `
    <div class="ktp-grid">
      <div class="theme-bank">
        <div class="tb-head">
          <h3>Темы курса</h3>
          <span class="tbh-count" id="tbCount"></span>
        </div>
        <div class="theme-list" id="themeList"></div>
      </div>

      <div class="kal">
        <div class="kal-head">
          <button class="icon-ghost" onclick="toast('Предыдущий месяц')"><svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M12 5l-5 5 5 5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
          <div class="kal-month">${MONTHS_RU[month]} ${year}</div>
          <button class="icon-ghost" onclick="toast('Следующий месяц')"><svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M8 5l5 5-5 5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
          <span style="flex:1"></span>
          <span class="kh-sub" id="kalHint" style="font-size:12px;color:var(--muted)">Перетащите тему на дату, чтобы закрепить</span>
        </div>
        <div class="kal-grid-wrap">
          <div class="kal-dow">${DOW_RU.map(d => `<span>${d}</span>`).join('')}</div>
          <div class="kal-grid" id="kalGrid"></div>
        </div>
      </div>
    </div>` : ktpEmpty(g)}
  </div>`;

  document.getElementById('ktpGroupBtn').onclick = openGroupMenu;
  document.getElementById('ktpCourseBtn').onclick = openCourseMenu;

  if (course) {
    document.getElementById('ktpReset').onclick = resetPins;
    document.getElementById('ktpAuto').onclick = autoDistribute;
    renderThemeList();
    renderCalendar();
  } else {
    const ab = document.getElementById('ktpAssignBtn');
    if (ab) ab.onclick = openCourseMenu;
  }
}

function ktpEmpty(g) {
  return `<div class="ktp-empty">
    <div class="ke-ico">
      <svg width="34" height="34" viewBox="0 0 24 24" fill="none"><rect x="3" y="4.5" width="18" height="16" rx="2.5" stroke="currentColor" stroke-width="1.6"/><path d="M3 9h18M8 2.5v4M16 2.5v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M8.5 14.5h7M8.5 17.5h4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
    </div>
    <h3>Для группы ${g.name} не назначен курс</h3>
    <p>Выберите курс — появятся его темы и календарь,<br>чтобы распределить занятия по датам.</p>
    <button class="btn btn-primary btn-lg" id="ktpAssignBtn">
      <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M10 5v10M5 10h10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
      Назначить курс
    </button>
  </div>`;
}

// ── Выпадающие меню (группа / курс) ───────────────────────────────────
function ktpMenu(anchor, items, onPick) {
  const menu = document.getElementById('ctxMenu');
  menu.innerHTML = items.map(it => `<div class="ctx-item ${it.active ? 'on' : ''}" data-v="${it.v}">
    <span class="ctx-check">${it.active ? '<svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M4 10.5 8 14l8-8.5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>' : ''}</span>
    ${it.swatch ? `<span class="ctx-sw" style="background:${it.swatch}">${it.chip || ''}</span>` : ''}
    <span class="ctx-lbl">${esc(it.label)}</span>
  </div>`).join('');
  menu.querySelectorAll('.ctx-item').forEach(el => el.addEventListener('click', () => { onPick(el.dataset.v); closeCtx(); }));
  const r = anchor.getBoundingClientRect();
  menu.classList.add('open');
  menu.style.minWidth = Math.max(220, r.width) + 'px';
  menu.style.left = Math.max(10, Math.min(r.left, window.innerWidth - 300)) + 'px';
  menu.style.top = (r.bottom + 6) + 'px';
  document.getElementById('ctxBackdrop').classList.add('open');
}

function openGroupMenu() {
  ktpMenu(document.getElementById('ktpGroupBtn'),
    GROUPS.map(g => ({
      v: g.id, label: `${g.name} · ${g.subject}`, active: g.id === ktpGroup,
      swatch: g.color, chip: g.name.replace(/[«»\s]/g,''),
    })),
    v => { if (v !== ktpGroup) { ktpGroup = v; renderKTP(ktpRoot); } });
}

function openCourseMenu() {
  const cur = activeCourseId();
  const items = Object.entries(KTP_COURSES).map(([id, c]) => ({ v: id, label: c.name, active: id === cur }));
  ktpMenu(document.getElementById('ktpCourseBtn'), items, v => {
    const changed = groupCourse[ktpGroup] !== v;
    if (changed) { groupCourse[ktpGroup] = v; pinsByGroup[ktpGroup] = {}; }
    renderKTP(ktpRoot);
    const g = GROUPS.find(x => x.id === ktpGroup);
    toast(`${g.name}: назначен курс «${KTP_COURSES[v].name}»`);
  });
}

// ── Банк тем ──────────────────────────────────────────────────────────
function renderThemeList() {
  const placedTheme = reflow();
  const list = document.getElementById('themeList');
  if (!list) return;
  const themes = activeThemes();
  const pins = activePins();
  list.innerHTML = themes.map(th => {
    const placed = placedTheme[th.id] != null;
    const pinned = pins[th.id] != null;
    const wt = th.work ? WORK_TYPES[th.work] : null;
    return `<div class="theme-card ${placed ? 'placed' : ''}" draggable="true" data-tid="${th.id}">
      <span class="tc-num">${th.n}</span>
      <div class="tc-body">
        <div class="tc-title">${esc(th.title)}</div>
        <div class="tc-meta">
          <span>${th.hours} ч</span>
          ${wt ? `<span style="color:${wt.raw};font-weight:600">${wt.name}</span>` : ''}
          ${pinned ? `<span style="color:var(--t-zachet);font-weight:600">закреплено</span>` : ''}
        </div>
      </div>
      <span class="tc-grip"><svg width="14" height="14" viewBox="0 0 14 14"><path fill="currentColor" d="M5 3h1v1H5zm3 0h1v1H8zM5 6.5h1v1H5zm3 0h1v1H8zM5 10h1v1H5zm3 0h1v1H8z"/></svg></span>
    </div>`;
  }).join('');

  const placedCount = Object.keys(placedTheme).length;
  document.getElementById('tbCount').textContent = `${placedCount} / ${themes.length} распределено`;
  list.querySelectorAll('.theme-card').forEach(card => attachThemeDrag(card));
}

// ── Календарь ─────────────────────────────────────────────────────────
function renderCalendar() {
  reflow();
  const { year, month, holidays } = KTP_CONFIG;
  const themes = activeThemes();
  const pins = activePins();
  const holMap = {}; holidays.forEach(h => holMap[h.day] = h.name);
  const lessonSet = new Set(lessonDaysOf());
  const first = new Date(year, month, 1);
  const offset = (first.getDay() + 6) % 7;
  const last = new Date(year, month + 1, 0).getDate();

  let cells = '';
  for (let i = 0; i < offset; i++) cells += `<div class="kal-cell empty"></div>`;

  for (let d = 1; d <= last; d++) {
    const isHol = holMap[d] != null;
    const isLesson = lessonSet.has(d);
    const tid = ktpPlacement[d];
    const th = tid ? themes.find(t => t.id === tid) : null;
    const pinned = th && pins[th.id] != null;

    let cls = 'kal-cell';
    if (isHol) cls += ' holiday';
    else if (!isLesson) cls += ' no-lesson';

    let body = '';
    if (isHol) {
      body = `<div class="placed-theme" style="border-left-color:var(--absent);cursor:default">
        <span class="pt-title" style="color:var(--absent)">${esc(holMap[d])}</span></div>`;
    } else if (th) {
      const wt = th.work ? WORK_TYPES[th.work] : null;
      body = `<div class="placed-theme ${pinned ? 'pinned' : ''}" draggable="true" data-tid="${th.id}" title="${esc(th.title)}"
                style="${wt ? `border-left-color:${wt.raw}` : ''}">
        <span class="pt-pin"><svg width="11" height="11" viewBox="0 0 14 14" fill="currentColor"><path d="M9.5 1.5 12.5 4.5 10 7l.5 3-3-2-3.5 3.5L4.5 8 2 7.5 4.5 5 7 4z"/></svg></span>
        <span class="pt-num" ${wt ? `style="color:${wt.raw}"` : ''}>№${th.n}</span>
        <span class="pt-title">${esc(th.title)}</span>
      </div>`;
    }

    cells += `<div class="${cls}" data-day="${d}" data-lesson="${isLesson && !isHol ? 1 : 0}">
      <div class="kal-date">
        <span class="kd-num">${d}</span>
        ${isHol ? `<span class="kd-tag hol">вых</span>` : ''}
        ${isLesson && !isHol ? `<span class="kd-lesson">урок</span>` : ''}
      </div>
      ${body}
    </div>`;
  }

  const grid = document.getElementById('kalGrid');
  grid.innerHTML = cells;
  grid.querySelectorAll('.kal-cell[data-lesson="1"]').forEach(cell => attachCellDrop(cell));
  grid.querySelectorAll('.placed-theme[draggable="true"]').forEach(card => attachThemeDrag(card));
}

// ── Drag & drop ───────────────────────────────────────────────────────
let dragTid = null;
function attachThemeDrag(el) {
  el.addEventListener('dragstart', e => {
    dragTid = el.dataset.tid;
    el.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', dragTid);
  });
  el.addEventListener('dragend', () => {
    dragTid = null;
    el.classList.remove('dragging');
    document.querySelectorAll('.drop-ok').forEach(n => n.classList.remove('drop-ok'));
  });
}
function attachCellDrop(cell) {
  cell.addEventListener('dragover', e => { e.preventDefault(); cell.classList.add('drop-ok'); });
  cell.addEventListener('dragleave', () => cell.classList.remove('drop-ok'));
  cell.addEventListener('drop', e => {
    e.preventDefault();
    cell.classList.remove('drop-ok');
    if (!dragTid) return;
    const day = +cell.dataset.day;
    const pins = activePins();
    Object.keys(pins).forEach(tid => { if (pins[tid] === day && tid !== dragTid) delete pins[tid]; });
    pins[dragTid] = day;
    const th = activeThemes().find(t => t.id === dragTid);
    reflow(); renderThemeList(); renderCalendar();
    toast(`Тема «${th.title.slice(0,24)}${th.title.length>24?'…':''}» закреплена на ${day}.${String(KTP_CONFIG.month+1).padStart(2,'0')}`);
  });
}

function autoDistribute() {
  reflow(); renderThemeList(); renderCalendar();
  const hint = document.getElementById('kalHint');
  if (hint) { hint.textContent = 'Темы распределены вокруг закреплённых и выходных'; hint.style.color = 'var(--g-good)'; setTimeout(() => { hint.textContent = 'Перетащите тему на дату, чтобы закрепить'; hint.style.color = 'var(--muted)'; }, 2600); }
  toast('Темы распределены автоматически');
}
function resetPins() {
  pinsByGroup[ktpGroup] = {};
  reflow(); renderThemeList(); renderCalendar();
  toast('Закрепления сброшены');
}

Object.assign(window, { renderKTP, autoDistribute, resetPins });
