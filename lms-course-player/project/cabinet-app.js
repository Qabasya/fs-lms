/* ══════════════════════════════════════════════════════════════════════
   Оболочка кабинета — навигация, роутинг, Tweaks (host protocol), init
   ══════════════════════════════════════════════════════════════════════ */

// ── Tweak defaults (host persists this block) ────────────────────────
const TWEAK_DEFAULTS = /*EDITMODE-BEGIN*/{
  "accent": "blue",
  "journalVariant": "a",
  "density": "compact"
}/*EDITMODE-END*/;

const ACCENTS = {
  indigo: { name: 'Индиго', sw: '#3b5bdb', vars: { '--accent':'#3b5bdb','--accent-600':'#364fc7','--accent-700':'#2f44b3','--accent-soft':'#edf0fe','--accent-soft-2':'#dde3fb' } },
  blue:   { name: 'Синий',  sw: '#1c7ed6', vars: { '--accent':'#1c7ed6','--accent-600':'#1971c2','--accent-700':'#1864ab','--accent-soft':'#e7f5ff','--accent-soft-2':'#d0ebff' } },
  teal:   { name: 'Бирюза', sw: '#0ca678', vars: { '--accent':'#0ca678','--accent-600':'#099268','--accent-700':'#087f5b','--accent-soft':'#e6fcf5','--accent-soft-2':'#c3fae8' } },
  violet: { name: 'Фиолет', sw: '#7048e8', vars: { '--accent':'#7048e8','--accent-600':'#6741d9','--accent-700':'#5f3dc4','--accent-soft':'#f3f0ff','--accent-soft-2':'#e5dbff' } },
};

const VAR_DESC = {
  a: 'A · Классическая плотная сетка',
  b: 'B · Цветные чипы-оценки',
  c: 'C · Минимал, тонкие линии',
  d: 'D · Оценки в кружках',
  e: 'E · Цветные колонки работ',
};

let tweaks = Object.assign({}, TWEAK_DEFAULTS);

function applyAccent(key) {
  const a = ACCENTS[key] || ACCENTS.indigo;
  Object.entries(a.vars).forEach(([k, v]) => document.documentElement.style.setProperty(k, v));
}
function applyDensity(key) {
  document.body.classList.toggle('density-cozy', key === 'cozy');
}
function applyVariant(key) {
  jVariant = key;
  setJournalVariant(key);
}
function applyAllTweaks() {
  applyAccent(tweaks.accent);
  applyDensity(tweaks.density);
  applyVariant(tweaks.journalVariant);
}

// ── Routing ──────────────────────────────────────────────────────────
const TOPBAR = {
  dashboard: { crumb: 'Кабинет преподавателя', title: 'Главная' },
  journal:   { crumb: 'Журнал',                title: '10 «А» · Информатика' },
  ktp:       { crumb: 'Планирование',          title: 'КТП и расписание' },
};

function go(screen) {
  document.querySelectorAll('.screen').forEach(s => s.classList.toggle('active', s.dataset.screen === screen));
  document.querySelectorAll('.nav-item[data-go]').forEach(n => n.classList.toggle('active', n.dataset.go === screen));
  setTopbar(screen);
  const act = document.querySelector(`.screen[data-screen="${screen}"]`);
  if (act) act.scrollTop = 0;
}
function setTopbar(screen, override) {
  const t = override || TOPBAR[screen];
  document.getElementById('tbCrumb').textContent = t.crumb;
  document.getElementById('tbTitle').textContent = t.title;
}
function openJournalFor(gid) {
  const g = GROUPS.find(x => x.id === gid) || GROUPS[0];
  // sync sidebar active group
  document.querySelectorAll('.group-item').forEach(el => el.classList.toggle('active', el.dataset.grp === gid));
  go('journal');
  setTopbar('journal', { crumb: 'Журнал', title: `${g.name} · ${g.subject}` });
  if (gid !== '10a') toast(`${g.name}: показаны демонстрационные данные`);
}

// ── Toast ────────────────────────────────────────────────────────────
let toastTimer;
function toast(msg) {
  const t = document.getElementById('toast');
  t.querySelector('span').textContent = msg;
  t.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), 2000);
}

// ── Tweaks panel (vanilla; mirrors host protocol) ────────────────────
function buildTweaksPanel() {
  const panel = document.createElement('div');
  panel.className = 'twk';
  panel.id = 'twkPanel';
  panel.innerHTML = `
    <div class="twk-hd">
      <b>Tweaks</b>
      <button class="twk-x" id="twkClose" title="Закрыть">✕</button>
    </div>
    <div class="twk-body">
      <div class="twk-sec">Акцентный цвет</div>
      <div class="twk-swatches" id="twkAccent">
        ${Object.entries(ACCENTS).map(([k, a]) => `<button class="twk-sw ${tweaks.accent===k?'on':''}" data-k="${k}" title="${a.name}" style="background:${a.sw}"></button>`).join('')}
      </div>

      <div class="twk-sec">Вариант журнала</div>
      <div class="twk-seg twk-seg-letters" id="twkVariant">
        ${['a','b','c','d','e'].map(k=>`<button class="${tweaks.journalVariant===k?'on':''}" data-k="${k}">${k.toUpperCase()}</button>`).join('')}
      </div>
      <div class="twk-hint" id="twkVarHint">${VAR_DESC[tweaks.journalVariant]}</div>

      <div class="twk-sec">Плотность</div>
      <div class="twk-seg" id="twkDensity">
        ${[['compact','Компактно'],['cozy','Свободно']].map(([k,l])=>`<button class="${tweaks.density===k?'on':''}" data-k="${k}">${l}</button>`).join('')}
      </div>
    </div>`;
  document.body.appendChild(panel);

  panel.querySelector('#twkClose').addEventListener('click', dismissTweaks);

  panel.querySelectorAll('#twkAccent .twk-sw').forEach(b => b.addEventListener('click', () => {
    setTweak('accent', b.dataset.k);
    panel.querySelectorAll('#twkAccent .twk-sw').forEach(x => x.classList.toggle('on', x === b));
  }));
  panel.querySelectorAll('#twkVariant button').forEach(b => b.addEventListener('click', () => {
    setTweak('journalVariant', b.dataset.k);
    panel.querySelectorAll('#twkVariant button').forEach(x => x.classList.toggle('on', x === b));
    document.getElementById('twkVarHint').textContent = VAR_DESC[b.dataset.k];
    if (!document.querySelector('.screen[data-screen="journal"]').classList.contains('active')) {
      toast('Вариант применён — откройте Журнал');
    }
  }));
  panel.querySelectorAll('#twkDensity button').forEach(b => b.addEventListener('click', () => {
    setTweak('density', b.dataset.k);
    panel.querySelectorAll('#twkDensity button').forEach(x => x.classList.toggle('on', x === b));
  }));
}

function setTweak(key, val) {
  tweaks[key] = val;
  if (key === 'accent') applyAccent(val);
  else if (key === 'density') applyDensity(val);
  else if (key === 'journalVariant') applyVariant(val);
  try { window.parent.postMessage({ type: '__edit_mode_set_keys', edits: { [key]: val } }, '*'); } catch (e) {}
}

function showTweaks() { document.getElementById('twkPanel').classList.add('open'); }
function hideTweaks() { document.getElementById('twkPanel').classList.remove('open'); }
function dismissTweaks() {
  hideTweaks();
  try { window.parent.postMessage({ type: '__edit_mode_dismissed' }, '*'); } catch (e) {}
}

window.addEventListener('message', e => {
  const t = e?.data?.type;
  if (t === '__activate_edit_mode') showTweaks();
  else if (t === '__deactivate_edit_mode') hideTweaks();
});

// ── close popovers on backdrop / escape ──────────────────────────────
function wireDismissers() {
  document.getElementById('ctxBackdrop').addEventListener('click', () => { closeGradePop(); closeCtx(); });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeGradePop(); closeCtx(); }
  });
}

// ── init ─────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  // nav
  document.querySelectorAll('.nav-item[data-go]').forEach(n =>
    n.addEventListener('click', () => go(n.dataset.go)));
  document.querySelectorAll('.nav-item[data-toast]').forEach(n =>
    n.addEventListener('click', () => toast(n.dataset.toast)));
  document.querySelectorAll('.group-item').forEach(el =>
    el.addEventListener('click', () => openJournalFor(el.dataset.grp)));

  // render screens
  renderDashboard(document.querySelector('[data-screen="dashboard"]'));
  renderJournal(document.querySelector('[data-screen="journal"]'));
  renderKTP(document.querySelector('[data-screen="ktp"]'));

  // tweaks + dismissers
  buildTweaksPanel();
  applyAllTweaks();
  wireDismissers();

  // announce edit-mode availability to host
  try { window.parent.postMessage({ type: '__edit_mode_available' }, '*'); } catch (e) {}

  go('dashboard');
});

Object.assign(window, { go, openJournalFor, toast, setTweak });
