/* ══════════════════════════════════════════════════════════════════════
   Журнал группы — рендер сетки, ввод оценок/посещаемости, 3 варианта
   ══════════════════════════════════════════════════════════════════════ */

let jVariant = 'a';            // set from tweak
const J = JOURNAL;             // mutable working copy

// ── month pagination ─────────────────────────────────────────────────
const MONTH_FULL = { '09':'Сентябрь','10':'Октябрь','11':'Ноябрь','12':'Декабрь','01':'Январь','02':'Февраль','03':'Март','04':'Апрель','05':'Май' };
const J_YEAR = 2025;
let jMonths = [];
let jMonthCursor = -1;         // -1 → default to last (текущий) month

function computeMonths() {
  jMonths = [];
  J.columns.forEach(c => { const m = c.d.split('.')[1]; if (!jMonths.includes(m)) jMonths.push(m); });
  if (jMonthCursor < 0 || jMonthCursor >= jMonths.length) jMonthCursor = jMonths.length - 1;
}
function shiftMonth(d) {
  jMonthCursor = Math.max(0, Math.min(jMonths.length - 1, jMonthCursor + d));
  renderGrid();
}
function visibleColIdx() {
  const m = jMonths[jMonthCursor];
  return J.columns.map((c, i) => i).filter(i => J.columns[i].d.split('.')[1] === m);
}

function setJournalVariant(v) {
  jVariant = v;
  const wrap = document.querySelector('.journal-wrap');
  if (wrap) wrap.className = 'journal-wrap var-' + v;
}

function renderJournal(root) {
  computeMonths();
  root.innerHTML = `
    <div class="j-monthnav">
      <button class="icon-ghost jm-arrow" id="jPrevM" title="Предыдущий месяц">
        <svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M12 5l-5 5 5 5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </button>
      <div class="jm-label" id="jmLabel"></div>
      <button class="icon-ghost jm-arrow" id="jNextM" title="Следующий месяц">
        <svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M8 5l5 5-5 5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </button>
      <span class="jm-count" id="jmCount"></span>
    </div>
    <div class="journal-wrap var-${jVariant}">
      <div class="j-scroll" id="jScroll"></div>
    </div>
    <div class="j-legend-bottom">
      <span class="jlb-label">Типы работ:</span>
      ${Object.entries(WORK_TYPES).map(([k,v])=>`<span class="jl"><span class="jl-sw" style="background:${v.raw}"></span>${v.name}</span>`).join('')}
    </div>
  `;
  document.getElementById('jPrevM').onclick = () => shiftMonth(-1);
  document.getElementById('jNextM').onclick = () => shiftMonth(1);
  renderGrid();
}

function renderGrid() {
  const scroll = document.getElementById('jScroll');
  if (!scroll) return;
  const vis = visibleColIdx();
  const lastVis = vis[vis.length - 1];

  // month nav chrome
  const lbl = document.getElementById('jmLabel');
  if (lbl) lbl.textContent = `${MONTH_FULL[jMonths[jMonthCursor]] || ''} ${J_YEAR}`;
  const cnt = document.getElementById('jmCount');
  if (cnt) cnt.textContent = `${vis.length} занятий`;
  const prev = document.getElementById('jPrevM'), next = document.getElementById('jNextM');
  if (prev) prev.disabled = jMonthCursor <= 0;
  if (next) next.disabled = jMonthCursor >= jMonths.length - 1;

  const head = `
    <thead>
      <tr>
        <th class="col-idx"><div class="hd-idx">#</div></th>
        <th class="col-name"><div class="hd-name">Ученик</div></th>
        ${vis.map((ci) => {
          const c = J.columns[ci];
          const wt = c.type ? WORK_TYPES[c.type] : null;
          return `<th class="hd-col ${c.type ? 'work' : ''} ${ci === lastVis ? 'today' : ''}" data-ci="${ci}" title="${esc(c.label)}"
                    style="${wt ? `--wt:${wt.raw}` : ''}">
            <div class="hd-date">${c.d}</div>
            <div class="hd-dow">${c.dow}</div>
            ${wt ? `<span class="hd-type-dot"></span><div class="hd-type" style="color:${wt.raw};font-size:9px;font-weight:700;margin-top:2px">${wt.short}</div>` : ''}
          </th>`;
        }).join('')}
      </tr>
    </thead>`;

  const body = `<tbody>${J.students.map((s, si) => `
    <tr data-si="${si}">
      <td class="col-idx cell-idx">${si + 1}</td>
      <td class="col-name cell-name">
        <span class="cn-name">${esc(s.name)}</span>
      </td>
      ${vis.map((ci) => cellTd(si, ci)).join('')}
    </tr>`).join('')}</tbody>`;

  scroll.innerHTML = `<table class="jgrid">${head}${body}</table>`;

  const table = scroll.querySelector('table');
  table.addEventListener('click', onGridClick);
}

function cellTd(si, ci) {
  const s = J.students[si];
  const c = s.cells[ci];
  const col = J.columns[ci];
  const wt = col.type ? WORK_TYPES[col.type] : null;
  const cls = ['gc', col.type ? 'work' : 'plain'];
  let inner = '';

  if (c.att === 'n') {
    cls.push('absent');
    inner = `<span class="g-val att-n">Н</span>
      <span class="att-makeup" data-mk="1"><svg width="11" height="11" viewBox="0 0 14 14" fill="none"><path d="M7 3v8M3 7h8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>Отработка</span>`;
  } else if (c.grade != null) {
    cls.push('has-grade');
    inner = `<span class="g-val">${c.grade}</span>`;
  } else if (c.att === 'l') {
    cls.push('late');
    inner = `<span class="g-val att-l">О</span>`;
  } else {
    inner = `<span class="att-p"></span>`;
  }

  return `<td class="${cls.join(' ')}" data-si="${si}" data-ci="${ci}" ${wt ? `style="--wt:${wt.raw}"` : ''}>${inner}</td>`;
}

function avgTd(si) {
  const a = avgOf(J.students[si].cells);
  let cls = 'avg-none', txt = '—';
  if (a != null) {
    txt = a.toFixed(1);
    cls = a >= 4.5 ? 'avg-good' : a >= 3.5 ? 'avg-mid' : 'avg-low';
  }
  return `<td class="col-avg"><span class="avg-pill ${cls}">${txt}</span></td>`;
}

function refreshCell(si, ci) {
  const td = document.querySelector(`td.gc[data-si="${si}"][data-ci="${ci}"]`);
  if (td) td.outerHTML = cellTd(si, ci);
}

function refreshColumn(ci) {
  J.students.forEach((_, si) => refreshCell(si, ci));
}

// ── interactions ─────────────────────────────────────────────────────
function onGridClick(e) {
  const mk = e.target.closest('.att-makeup');
  if (mk) {
    e.stopPropagation();
    const td = mk.closest('td.gc');
    createMakeup(+td.dataset.si, +td.dataset.ci);
    return;
  }
  const td = e.target.closest('td.gc');
  if (td) { openGradePop(+td.dataset.si, +td.dataset.ci, td); return; }
  const th = e.target.closest('th.hd-col');
  if (th) { openColMenu(+th.dataset.ci, th); }
}

function createMakeup(si, ci) {
  const s = J.students[si];
  toast(`Отработка создана: ${s.name.split(' ')[0]} · ${J.columns[ci].d}`);
}

// grade popover
let gpTarget = null;
function openGradePop(si, ci, td) {
  gpTarget = { si, ci };
  const pop = document.getElementById('gradePop');
  const s = J.students[si], col = J.columns[ci], c = s.cells[ci];
  pop.innerHTML = `
    <div class="gp-title">${esc(s.name.split(' ')[0])} · ${col.d}${col.type ? ' · ' + WORK_TYPES[col.type].short : ''}</div>
    <div class="gp-grades">
      ${[5,4,3,2].map(g => `<button class="gp-g" data-g="${g}" ${c.grade===g?'style="border-color:var(--accent);background:var(--accent-soft);color:var(--accent-700)"':''}>${g}</button>`).join('')}
      <button class="gp-g" data-g="clear" title="Очистить">×</button>
    </div>
    <div class="gp-row">
      <button class="btn btn-sm ${c.att==='p'?'btn-primary':''}" data-att="p">Был</button>
      <button class="btn btn-sm ${c.att==='n'?'btn-primary':''}" data-att="n" style="${c.att==='n'?'background:var(--absent);border-color:var(--absent)':''}">Н</button>
      <button class="btn btn-sm ${c.att==='l'?'btn-primary':''}" data-att="l" style="${c.att==='l'?'background:var(--late);border-color:var(--late)':''}">Опозд.</button>
    </div>
  `;
  pop.querySelectorAll('.gp-g').forEach(b => b.addEventListener('click', () => {
    const g = b.dataset.g;
    if (g === 'clear') c.grade = null;
    else { c.grade = +g; if (c.att === 'n') c.att = 'p'; }
    refreshCell(si, ci); closeGradePop();
  }));
  pop.querySelectorAll('[data-att]').forEach(b => b.addEventListener('click', () => {
    c.att = b.dataset.att;
    if (c.att === 'n') c.grade = null;
    refreshCell(si, ci); closeGradePop();
  }));

  // position
  const r = td.getBoundingClientRect();
  pop.classList.add('open');
  const pw = 188, ph = pop.offsetHeight;
  let left = r.left + r.width / 2 - pw / 2;
  left = Math.max(10, Math.min(left, window.innerWidth - pw - 10));
  let top = r.bottom + 6;
  if (top + ph > window.innerHeight - 10) top = r.top - ph - 6;
  pop.style.left = left + 'px';
  pop.style.top = top + 'px';
  document.getElementById('ctxBackdrop').classList.add('open');
}
function closeGradePop() {
  document.getElementById('gradePop').classList.remove('open');
  if (!document.querySelector('.ctx-menu.open')) document.getElementById('ctxBackdrop').classList.remove('open');
  gpTarget = null;
}

// column header menu (bulk default pattern)
function openColMenu(ci, th) {
  const menu = document.getElementById('ctxMenu');
  const col = J.columns[ci];
  menu.innerHTML = `
    <div class="ctx-title">${col.d} · ${esc(col.label)}</div>
    <div class="ctx-item" data-act="allp">
      <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M4 10.5 8 14l8-8.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Отметить всех присутствующими
    </div>
    <div class="ctx-item" data-act="grade">
      <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M10 3 12 7l4.5.6-3.3 3.2.8 4.5L10 13.2 6 15.5l.8-4.5L3.5 7.7 8 7z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
      Выставить оценку всем…
    </div>
    <div class="ctx-sep"></div>
    <div class="ctx-item" data-act="clear">
      <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M6 6l8 8M14 6l-8 8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
      Очистить колонку
    </div>
  `;
  menu.querySelectorAll('.ctx-item').forEach(it => it.addEventListener('click', () => {
    const act = it.dataset.act;
    if (act === 'allp') { J.students.forEach(s => { if (s.cells[ci].att === 'n') s.cells[ci].att = 'p'; }); refreshColumn(ci); toast('Все отмечены присутствующими'); }
    else if (act === 'clear') { J.students.forEach(s => { s.cells[ci] = { att: 'p', grade: null }; }); refreshColumn(ci); toast('Колонка очищена'); }
    else if (act === 'grade') { toast('Выберите оценку для всех учеников'); }
    closeCtx();
  }));
  const r = th.getBoundingClientRect();
  menu.classList.add('open');
  let left = Math.min(r.left, window.innerWidth - 220);
  menu.style.left = Math.max(10, left) + 'px';
  menu.style.top = (r.bottom + 4) + 'px';
  document.getElementById('ctxBackdrop').classList.add('open');
}
function closeCtx() {
  document.getElementById('ctxMenu').classList.remove('open');
  if (!document.querySelector('.grade-pop.open')) document.getElementById('ctxBackdrop').classList.remove('open');
}

function addWorkColumn() {
  J.columns.push({ d: '30.12', dow: 'Вт', type: 'sam', label: 'Новая работа' });
  J.students.forEach(s => s.cells.push({ att: 'p', grade: null }));
  computeMonths();
  renderGrid();
  toast('Колонка работы добавлена');
  const sc = document.getElementById('jScroll'); sc.scrollLeft = sc.scrollWidth;
}

function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

Object.assign(window, { renderJournal, setJournalVariant, addWorkColumn, closeGradePop, closeCtx });
