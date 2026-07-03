// ══════════════════════════════════════════════════════════════════════
// final-core.js — итоговый плеер: типы шагов, состояние, оболочка,
// рейка дерева, лента шагов, тик таймеров, Tweaks (host protocol)
// ══════════════════════════════════════════════════════════════════════

// ── Типы шагов (цвета и иконки — по скриншоту конструктора) ───────────
const TYPES = {
  text:    { label: "Текст",       c: "#1c7ed6", soft: "#e7f2fb" },
  video:   { label: "Видео",       c: "#7048e8", soft: "#f1ecfd" },
  task:    { label: "Задача",      c: "#099268", soft: "#e6f7f1" },
  work:    { label: "Работа",      c: "#e8590c", soft: "#fdeee3" },
  control: { label: "Контрольная", c: "#e03131", soft: "#fdecec" },
};

function typeIco(type, color, s) {
  s = s || 22;
  const head = '<svg width="' + s + '" height="' + s + '" viewBox="0 0 20 20" fill="none">';
  const tail = "</svg>";
  if (type === "text") return head +
    '<path d="M5 2.5h6.2L15.5 6.8V17.5H5V2.5z" fill="' + color + '"/>' +
    '<path d="M11.2 2.5v4.3h4.3L11.2 2.5z" fill="#fff" opacity=".45"/>' +
    '<rect x="7" y="10" width="6.4" height="1.4" rx=".7" fill="#fff" opacity=".9"/>' +
    '<rect x="7" y="13" width="4.6" height="1.4" rx=".7" fill="#fff" opacity=".9"/>' + tail;
  if (type === "video") return head +
    '<rect x="2.5" y="4.2" width="15" height="11.6" rx="2.6" fill="' + color + '"/>' +
    '<path d="M8.4 7.3v5.4L13 10 8.4 7.3z" fill="#fff"/>' + tail;
  if (type === "task") return head +
    '<circle cx="10" cy="10" r="7.6" fill="' + color + '"/>' +
    '<path d="M7.9 7.9a2.15 2.15 0 1 1 3.3 1.85c-.65.42-1.2.85-1.2 1.65v.25" stroke="#fff" stroke-width="1.7" stroke-linecap="round" fill="none"/>' +
    '<circle cx="10" cy="14.1" r="1" fill="#fff"/>' + tail;
  if (type === "work") return head +
    '<path d="M7 5.5 3.4 10 7 14.5M13 5.5 16.6 10 13 14.5" stroke="' + color + '" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" fill="none"/>' + tail;
  // control — список с галочками
  return head +
    '<path d="M3.4 5.2l1.2 1.2 2-2.1" stroke="' + color + '" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" fill="none"/>' +
    '<rect x="8.6" y="4.6" width="8" height="1.6" rx=".8" fill="' + color + '"/>' +
    '<path d="M3.4 10.2l1.2 1.2 2-2.1" stroke="' + color + '" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" fill="none"/>' +
    '<rect x="8.6" y="9.6" width="8" height="1.6" rx=".8" fill="' + color + '"/>' +
    '<path d="M3.4 15.2l1.2 1.2 2-2.1" stroke="' + color + '" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" fill="none"/>' +
    '<rect x="8.6" y="14.6" width="8" height="1.6" rx=".8" fill="' + color + '"/>' + tail;
}
function tBadge(type) {
  const t = TYPES[type];
  return '<span class="tbadge" style="--tc:' + t.c + ';--tcs:' + t.soft + '">' + typeIco(type, t.c, 13) + t.label + '</span>';
}

// ── Шаги урока ─────────────────────────────────────────────────────────
const FSTEPS = [
  { type: "text",  title: "Цикл for: синтаксис" },
  { type: "video", title: "range() на примерах" },
  { type: "task",  title: "Число итераций" },
  { type: "text",  title: "Вложенные циклы" },
  { type: "work",  title: "Работа №2 «Циклы»" },
];
const WORK_LIMIT = 25 * 60; // сек

// ── Состояние (persist в localStorage) ────────────────────────────────
const LS_KEY = "cpFinal_v1";
const DEF_STATE = {
  step: 0, menuOn: false, pinned: false,
  done: [false, false, false, false, false],
  video: { sec: 151, playing: false },
  task: { sel: null, answered: false, correct: false, attempts: 3, revealed: false },
  work: { a1: null, a2: "", a3: null, a4: "", startTs: null, submitted: false, submitTs: null },
};
let S;
try {
  S = Object.assign({}, DEF_STATE, JSON.parse(localStorage.getItem(LS_KEY) || "{}"));
  S.video = Object.assign({}, DEF_STATE.video, S.video);
  S.task = Object.assign({}, DEF_STATE.task, S.task);
  S.work = Object.assign({}, DEF_STATE.work, S.work);
  if (!Array.isArray(S.done) || S.done.length !== 5) S.done = DEF_STATE.done.slice();
} catch (e) { S = JSON.parse(JSON.stringify(DEF_STATE)); }
S.video.playing = false;
function save() { try { localStorage.setItem(LS_KEY, JSON.stringify(S)); } catch (e) {} }

function fmt(sec) {
  sec = Math.max(0, Math.round(sec));
  return Math.floor(sec / 60) + ":" + String(sec % 60).padStart(2, "0");
}

// ── Тост ───────────────────────────────────────────────────────────────
function toast(msg) {
  const t = document.getElementById("toast");
  t.querySelector("span").textContent = msg;
  t.classList.add("show");
  clearTimeout(toast._t);
  toast._t = setTimeout(function () { t.classList.remove("show"); }, 2200);
}

// ── Меню кабинета ──────────────────────────────────────────────────────
function applyMenu() { document.body.classList.toggle("menu-off", !S.menuOn); }
function toggleMenu() { S.menuOn = !S.menuOn; applyMenu(); save(); }

// ── Рейка дерева ───────────────────────────────────────────────────────
function railSlim() {
  let h = '<div class="rail-slim">';
  h += '<span class="rs-x" title="Развернуть структуру курса">' + ICO.chevR(15) + '</span>';
  h += '<span class="rs-sep"></span>';
  h += '<span class="rs-les done" title="Урок 11. Цикл while — пройден">' + ICO.check(15) + '</span>';
  h += '<span class="rs-les cur" title="Урок 12. Цикл for и range() — вы здесь">12</span>';
  h += '<span class="rs-les lk" title="Урок 13. Вложенные циклы — закрыт">13</span>';
  h += '<span class="rs-les lk" title="Урок 14. Практикум — закрыт">14</span>';
  h += '<span class="rs-sep"></span>';
  h += '<span class="rs-ico" title="Контрольная модуля — откроется после урока 14">' + typeIco("control", TYPES.control.c, 17) + '</span>';
  h += "</div>";
  return h;
}
function railFull() {
  let steps = "";
  FSTEPS.forEach(function (st, i) {
    steps += '<div class="t-step' + (i === S.step ? " on" : "") + '" data-goto="' + i + '">' +
      '<span class="tsi">' + typeIco(st.type, TYPES[st.type].c, 15) + '</span>' +
      '<span class="txt">' + (i + 1) + ". " + st.title + '</span>' +
      (S.done[i] ? '<span class="tick">' + ICO.check(13) + '</span>' : "") +
      "</div>";
  });
  return '' +
  '<div class="rail-full">' +
    '<div class="rf-h">' +
      '<div class="rf-t"><b>' + COURSE.title + '</b>' +
        '<div class="bar"><span style="width:' + COURSE.progress + '%"></span></div>' +
        '<div class="pct">Пройдено ' + COURSE.progress + '% · 21 из 46 уроков</div></div>' +
      '<button class="rf-pin' + (S.pinned ? " on" : "") + '" id="rfPin" title="Закрепить панель">' +
        '<svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M8 3h4l.6 5.2 2.4 2.3v1.5H5v-1.5l2.4-2.3L8 3zM10 12v5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
      '</button>' +
    '</div>' +
    '<div class="rf-list">' +
      '<div class="t-mod dim"><span class="tmi ok">' + ICO.check(12) + '</span>Модуль 1 · Начала Python<span class="chev">' + ICO.chevR(13) + '</span></div>' +
      '<div class="t-mod"><span class="tmi cur">2</span>Ветвления и циклы<span class="chev">' + ICO.chevD(13) + '</span></div>' +
      '<div class="t-les dim">Урок 11. Цикл while<span class="ls ok">' + ICO.check(13) + '</span></div>' +
      '<div class="t-les cur">Урок 12. Цикл for и range()</div>' +
      steps +
      '<div class="t-les dim">Урок 13. Вложенные циклы<span class="ls">' + ICO.lock(13) + '</span></div>' +
      '<div class="t-les dim">Урок 14. Практикум по циклам<span class="ls">' + ICO.lock(13) + '</span></div>' +
      '<div class="t-les dim"><span class="tsi" style="display:flex;margin-right:1px">' + typeIco("control", TYPES.control.c, 14) + '</span>Контрольная модуля<span class="ls">' + ICO.lock(13) + '</span></div>' +
      '<div class="t-mod dim"><span class="tmi lk">' + ICO.lock(11) + '</span>Модуль 3 · Коллекции<span class="chev">' + ICO.chevR(13) + '</span></div>' +
    '</div>' +
  '</div>';
}
function renderRail() {
  const rail = document.getElementById("rail");
  rail.classList.toggle("pin", S.pinned);
  rail.innerHTML = railSlim() + railFull();
  rail.querySelector("#rfPin").addEventListener("click", function () {
    S.pinned = !S.pinned; save(); renderRail();
  });
  rail.querySelectorAll("[data-goto]").forEach(function (el) {
    el.addEventListener("click", function () { gotoStep(+el.dataset.goto); });
  });
  rail.querySelector(".rs-x").addEventListener("click", function () {
    S.pinned = true; save(); renderRail();
  });
  rail.querySelectorAll(".rs-les.lk, .t-les.dim").forEach(function (el) {
    el.addEventListener("click", function () { toast("Откроется после текущего урока"); });
  });
}

// ── Лента шагов ────────────────────────────────────────────────────────
function renderStrip() {
  const st = FSTEPS[S.step];
  let h = "";
  FSTEPS.forEach(function (s, i) {
    const t = TYPES[s.type];
    const cur = i === S.step;
    h += '<div class="stp' + (cur ? " cur" : "") + (S.done[i] ? " done" : "") + '" data-step="' + i +
      '" style="--tc:' + t.c + ';--tcs:' + t.soft + '" title="' + s.title + '">' +
      '<span class="sq"><span class="num">' + (i + 1) + '</span>' + typeIco(s.type, cur ? "#fff" : t.c, 22) + '</span>' +
      '<span class="lbl">' + t.label + '</span></div>';
  });
  h += '<div class="smeta"><b>Шаг ' + (S.step + 1) + " из " + FSTEPS.length + " · " + TYPES[st.type].label + '</b><span>' + st.title + '</span></div>';
  const strip = document.getElementById("strip");
  strip.innerHTML = h;
  strip.querySelectorAll("[data-step]").forEach(function (el) {
    el.addEventListener("click", function () { gotoStep(+el.dataset.step); });
  });
  const dc = S.done.filter(Boolean).length;
  const tEl = document.getElementById("spTxt"), bEl = document.getElementById("spBar");
  if (tEl) tEl.textContent = "Урок · " + dc + " из 5";
  if (bEl) bEl.style.width = (dc / 5) * 100 + "%";
}

// ── Навигация ──────────────────────────────────────────────────────────
function gotoStep(i) {
  if (i < 0 || i >= FSTEPS.length || i === S.step) return;
  S.step = i;
  save();
  renderStrip();
  renderRail();
  renderStep();
  document.querySelector(".cscroll").scrollTop = 0;
}
function markDone(i) {
  if (!S.done[i]) { S.done[i] = true; save(); renderStrip(); renderRail(); }
}

// ── Тик (таймер работы + видео) ────────────────────────────────────────
setInterval(function () {
  // видео
  if (S.video.playing) {
    S.video.sec = Math.min(444, S.video.sec + 1);
    if (S.video.sec >= 444) { S.video.playing = false; markDone(1); }
    if (typeof updateVideoUI === "function") updateVideoUI();
    if (S.video.sec % 5 === 0) save();
  }
  // таймер работы
  const w = S.work;
  if (w.startTs && !w.submitted) {
    const left = WORK_LIMIT - (Date.now() - w.startTs) / 1000;
    if (typeof updateWorkTimerUI === "function") updateWorkTimerUI(left);
    if (left <= 0) {
      submitWork(true);
    }
  }
}, 1000);

// ── Tweaks (host protocol, как в кабинете) ─────────────────────────────
const TWEAK_DEFAULTS = /*EDITMODE-BEGIN*/{
  "accent": "indigo",
  "colw": "std",
  "lbls": "on"
}/*EDITMODE-END*/;
let tweaks = Object.assign({}, TWEAK_DEFAULTS);

const ACCENTS = {
  indigo: { name: "Индиго",  sw: "#3b5bdb", v: ["#3b5bdb", "#364fc7", "#2f44b3", "#edf0fe", "#dde3fb"] },
  teal:   { name: "Изумруд", sw: "#0ca678", v: ["#0ca678", "#099268", "#087f5b", "#e6f7f1", "#c3ecdd"] },
  violet: { name: "Фиолет",  sw: "#7048e8", v: ["#7048e8", "#6741d9", "#5f3dc4", "#f1ecfd", "#e3d9fb"] },
};
const COLW = { narrow: "820px", std: "900px", wide: "1020px" };

function applyTweaks() {
  const a = ACCENTS[tweaks.accent] || ACCENTS.indigo;
  const r = document.documentElement.style;
  r.setProperty("--accent", a.v[0]);
  r.setProperty("--accent-600", a.v[1]);
  r.setProperty("--accent-700", a.v[2]);
  r.setProperty("--accent-soft", a.v[3]);
  r.setProperty("--accent-soft-2", a.v[4]);
  r.setProperty("--colw", COLW[tweaks.colw] || COLW.std);
  document.body.classList.toggle("no-lbls", tweaks.lbls === "off");
}
function setTweak(key, val) {
  tweaks[key] = val;
  applyTweaks();
  try { window.parent.postMessage({ type: "__edit_mode_set_keys", edits: (function (o) { o[key] = val; return o; })({}) }, "*"); } catch (e) {}
}
function buildTweaksPanel() {
  const p = document.createElement("div");
  p.className = "twk"; p.id = "twkPanel";
  p.innerHTML =
    '<div class="twk-hd"><b>Tweaks</b><button class="twk-x" id="twkClose">✕</button></div>' +
    '<div class="twk-body">' +
      '<div class="twk-sec">Акцентный цвет</div>' +
      '<div class="twk-swatches" id="twkAccent">' +
        Object.keys(ACCENTS).map(function (k) {
          return '<button class="twk-sw' + (tweaks.accent === k ? " on" : "") + '" data-k="' + k + '" title="' + ACCENTS[k].name + '" style="background:' + ACCENTS[k].sw + '"></button>';
        }).join("") +
      '</div>' +
      '<div class="twk-sec">Ширина контента</div>' +
      '<div class="twk-seg" id="twkColw">' +
        [["narrow", "Уже"], ["std", "Стандарт"], ["wide", "Шире"]].map(function (o) {
          return '<button class="' + (tweaks.colw === o[0] ? "on" : "") + '" data-k="' + o[0] + '">' + o[1] + "</button>";
        }).join("") +
      '</div>' +
      '<div class="twk-sec">Подписи шагов</div>' +
      '<div class="twk-seg" id="twkLbls">' +
        [["on", "Показывать"], ["off", "Скрывать"]].map(function (o) {
          return '<button class="' + (tweaks.lbls === o[0] ? "on" : "") + '" data-k="' + o[0] + '">' + o[1] + "</button>";
        }).join("") +
      '</div>' +
    '</div>';
  document.body.appendChild(p);
  p.querySelector("#twkClose").addEventListener("click", function () {
    p.classList.remove("open");
    try { window.parent.postMessage({ type: "__edit_mode_dismissed" }, "*"); } catch (e) {}
  });
  [["twkAccent", "accent"], ["twkColw", "colw"], ["twkLbls", "lbls"]].forEach(function (pair) {
    p.querySelectorAll("#" + pair[0] + " button").forEach(function (b) {
      b.addEventListener("click", function () {
        setTweak(pair[1], b.dataset.k);
        p.querySelectorAll("#" + pair[0] + " button").forEach(function (x) { x.classList.toggle("on", x === b); });
      });
    });
  });
}
window.addEventListener("message", function (e) {
  const t = e && e.data && e.data.type;
  if (t === "__activate_edit_mode") document.getElementById("twkPanel").classList.add("open");
  else if (t === "__deactivate_edit_mode") document.getElementById("twkPanel").classList.remove("open");
  else if (t === "__edit_mode_load_keys" && e.data.edits) {
    Object.assign(tweaks, e.data.edits); applyTweaks();
  }
});

// ── Клавиатура ─────────────────────────────────────────────────────────
document.addEventListener("keydown", function (e) {
  const tag = (e.target.tagName || "").toLowerCase();
  if (tag === "textarea" || tag === "input" || document.querySelector(".cp-dim")) return;
  if (e.key === "ArrowRight") gotoStep(S.step + 1);
  if (e.key === "ArrowLeft") gotoStep(S.step - 1);
});

// ── Инициализация ──────────────────────────────────────────────────────
function initPlayer() {
  applyMenu();
  renderRail();
  renderStrip();
  renderStep();
  buildTweaksPanel();
  applyTweaks();
  document.getElementById("mtoggle").addEventListener("click", toggleMenu);
  document.getElementById("sCollapse").addEventListener("click", toggleMenu);
  document.querySelectorAll("[data-toast]").forEach(function (el) {
    el.addEventListener("click", function () { toast(el.dataset.toast); });
  });
  try { window.parent.postMessage({ type: "__edit_mode_available" }, "*"); } catch (e) {}
}
