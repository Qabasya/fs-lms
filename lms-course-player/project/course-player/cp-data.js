// ══════════════════════════════════════════════════════════════════════
// cp-data.js — данные курса, иконки, оболочка кабинета, общие билдеры
// ══════════════════════════════════════════════════════════════════════

const COURSE = {
  title: "Информатика. Python с нуля",
  moduleShort: "Модуль 2",
  module: "Модуль 2 · Ветвления и циклы",
  lesson: "Урок 12. Цикл for и функция range()",
  progress: 46,
};

const STEPS = [
  { type: "theory", label: "Теория", title: "Цикл for: синтаксис" },
  { type: "video",  label: "Видео",  title: "range() на примерах" },
  { type: "task",   label: "Задача", title: "Число итераций" },
  { type: "theory", label: "Теория", title: "Вложенные циклы" },
  { type: "task",   label: "Задача", title: "Чётные числа" },
  { type: "work",   label: "Работа", title: "Самостоятельная №2 «Циклы»" },
];

// ─── Иконки (stroke: currentColor) ──────────────────────────────────────

function svg(s, inner, vb) {
  return '<svg width="' + s + '" height="' + s + '" viewBox="0 0 ' + (vb || 20) + ' ' + (vb || 20) + '" fill="none">' + inner + '</svg>';
}
const ST = ' stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"';

const ICO = {
  rocket: (s) => svg(s, '<path d="M10 2.4c2.1 1.4 3.2 3.6 3.2 5.9 0 1.6-.4 3.1-1.1 4.5H7.9C7.2 11.4 6.8 9.9 6.8 8.3c0-2.3 1.1-4.5 3.2-5.9z" fill="#fff"/><circle cx="10" cy="7.7" r="1.25" fill="#3b5bdb"/><path d="M7.4 11.6 5.6 14.6l2.7-1M12.6 11.6l1.8 3-2.7-1" stroke="#fff" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/><path d="M10 13.4v2.4" stroke="#fff" stroke-width="1.3" stroke-linecap="round" stroke-dasharray="1.2 1.6"/>'),
  home:   (s) => svg(s, '<path d="M3 9.5 10 4l7 5.5M5 8.5V16h10V8.5"' + ST + '/>'),
  book:   (s) => svg(s, '<path d="M4 4.5A1.5 1.5 0 0 1 5.5 3H16v13H5.5A1.5 1.5 0 0 0 4 17.5v-13z"' + ST + '/><path d="M4 15.5A1.5 1.5 0 0 1 5.5 14H16"' + ST + '/><path d="M7.5 7h5"' + ST + '/>'),
  cal:    (s) => svg(s, '<rect x="3" y="4" width="14" height="13" rx="2"' + ST + '/><path d="M3 8h14M7 2.5v3M13 2.5v3"' + ST + '/>'),
  star:   (s) => svg(s, '<path d="M10 3l2.1 4.3 4.7.7-3.4 3.3.8 4.7-4.2-2.2-4.2 2.2.8-4.7L3.2 8l4.7-.7L10 3z"' + ST + '/>'),
  bell:   (s) => svg(s, '<path d="M10 3a4 4 0 0 0-4 4c0 4-1.5 5-1.5 5h11S14 11 14 7a4 4 0 0 0-4-4zM8.5 15a1.5 1.5 0 0 0 3 0"' + ST + '/>'),
  gear:   (s) => svg(s, '<circle cx="10" cy="10" r="2.4"' + ST + '/><path d="M10 3v2M10 15v2M3 10h2M15 10h2M5 5l1.4 1.4M13.6 13.6 15 15M15 5l-1.4 1.4M6.4 13.6 5 15"' + ST + '/>'),
  chevL:  (s) => svg(s, '<path d="M12 4.5 6.5 10 12 15.5"' + ST + '/>'),
  chevR:  (s) => svg(s, '<path d="M8 4.5 13.5 10 8 15.5"' + ST + '/>'),
  chevD:  (s) => svg(s, '<path d="M5 8l5 5 5-5"' + ST + '/>'),
  check:  (s) => svg(s, '<path d="M4 10.5 8 14l8-8.5"' + ST + '/>'),
  cross:  (s) => svg(s, '<path d="M5.5 5.5l9 9M14.5 5.5l-9 9"' + ST + '/>'),
  clock:  (s) => svg(s, '<circle cx="10" cy="10" r="7"' + ST + '/><path d="M10 6.2V10l2.6 1.8"' + ST + '/>'),
  lock:   (s) => svg(s, '<rect x="5" y="9" width="10" height="7.5" rx="1.8"' + ST + '/><path d="M7 9V7a3 3 0 0 1 6 0v2"' + ST + '/>'),
  doc:    (s) => svg(s, '<path d="M5 3h7l3 3v11H5V3z"' + ST + '/><path d="M12 3v3h3M8 10h4M8 13h4"' + ST + '/>'),
  play:   (s) => svg(s, '<path d="M7 5.2v9.6L15 10 7 5.2z" fill="currentColor"/>'),
  playO:  (s) => svg(s, '<rect x="2.5" y="4" width="15" height="12" rx="2.5"' + ST + '/><path d="M8.5 7.5v5L13 10l-4.5-2.5z" fill="currentColor"/>'),
  bolt:   (s) => svg(s, '<path d="M11 2.5 4.5 11H9l-.8 6.5L14.8 9H10l1-6.5z"' + ST + '/>'),
  flag:   (s) => svg(s, '<path d="M5 17.5V3.2"' + ST + '/><path d="M5 4h9.5l-2.2 3.2L14.5 10H5"' + ST + '/>'),
  pen:    (s) => svg(s, '<path d="M12.8 3.7a1.8 1.8 0 0 1 2.5 2.5l-8 8L4 15l.8-3.3 8-8z"' + ST + '/>'),
  info:   (s) => svg(s, '<circle cx="10" cy="10" r="7"' + ST + '/><path d="M10 9v4.5"' + ST + '/><circle cx="10" cy="6.4" r=".9" fill="currentColor"/>'),
  dl:     (s) => svg(s, '<path d="M10 3.5v9M6.5 9.5 10 13l3.5-3.5M4.5 16h11"' + ST + '/>'),
  vol:    (s) => svg(s, '<path d="M4 8v4h2.8L11 15V5L6.8 8H4z"' + ST + '/><path d="M13.5 7.5a3.6 3.6 0 0 1 0 5"' + ST + '/>'),
  cc:     (s) => svg(s, '<rect x="2.5" y="5" width="15" height="10" rx="2"' + ST + '/><path d="M9 9.2a1.6 1.6 0 1 0 0 1.6M14 9.2a1.6 1.6 0 1 0 0 1.6"' + ST + '/>'),
  fs:     (s) => svg(s, '<path d="M3.5 7.5v-4h4M16.5 7.5v-4h-4M3.5 12.5v4h4M16.5 12.5v4h-4"' + ST + '/>'),
  back10: (s) => svg(s, '<path d="M10 4a6 6 0 1 1-5.6 3.8"' + ST + '/><path d="M4.5 3.5v4h4"' + ST + '/>'),
  fwd10:  (s) => svg(s, '<path d="M10 4a6 6 0 1 0 5.6 3.8"' + ST + '/><path d="M15.5 3.5v4h-4"' + ST + '/>'),
  send:   (s) => svg(s, '<path d="M3 10.5 17 3l-4 14-3.2-5.3L3 10.5z"' + ST + '/>'),
  eyeOff: (s) => svg(s, '<path d="M3 10s2.5-4.5 7-4.5S17 10 17 10s-2.5 4.5-7 4.5S3 10 3 10z"' + ST + '/><path d="M4 4l12 12"' + ST + '/>'),
  code:   (s) => svg(s, '<path d="M7 6.5 3.5 10 7 13.5M13 6.5 16.5 10 13 13.5"' + ST + '/>'),
};

const STEP_ICO = { theory: ICO.doc, video: ICO.playO, task: ICO.bolt, work: ICO.flag };
const BADGE = {
  theory: '<span class="tb tb-theory">' + ICO.doc(12) + 'Теория</span>',
  video:  '<span class="tb tb-video">' + ICO.playO(12) + 'Видео</span>',
  task:   '<span class="tb tb-task">' + ICO.bolt(12) + 'Задача</span>',
  work:   '<span class="tb tb-work">' + ICO.flag(12) + 'Самостоятельная</span>',
};

// ─── Оболочка кабинета ───────────────────────────────────────────────────

function sideHtml() {
  return '' +
  '<aside class="s-side">' +
    '<div class="s-brand">' +
      '<div class="s-mark">' + ICO.rocket(21) + '</div>' +
      '<div><div class="s-name">Шаг в будущее</div><div class="s-bsub">Личный кабинет</div></div>' +
    '</div>' +
    '<nav class="s-nav">' +
      '<div class="s-navlabel">Меню</div>' +
      '<div class="s-item">' + ICO.home(19) + 'Главная</div>' +
      '<div class="s-item on">' + ICO.book(19) + 'Мои курсы</div>' +
      '<div class="s-item">' + ICO.cal(19) + 'Расписание</div>' +
      '<div class="s-item">' + ICO.star(19) + 'Оценки</div>' +
      '<div class="s-now">' +
        '<div class="sn-lbl">Сейчас проходите</div>' +
        '<div class="sn-course">' + COURSE.title + '</div>' +
        '<div class="sn-bar"><span style="width:' + COURSE.progress + '%"></span></div>' +
        '<div class="sn-pct">Пройдено ' + COURSE.progress + '% · Модуль 2</div>' +
      '</div>' +
    '</nav>' +
    '<div class="s-foot">' +
      '<div class="s-ava">ИМ</div>' +
      '<div><div class="s-uname">Иван Морозов</div><div class="s-urole">Ученик · 10 «А»</div></div>' +
      '<span class="s-gear">' + ICO.gear(18) + '</span>' +
    '</div>' +
  '</aside>';
}

function frameHtml(opts) {
  // opts: { label, crumb, title, stageClass, stageHtml }
  return '' +
  sideHtml() +
  '<div class="s-main">' +
    '<header class="s-top">' +
      '<div style="min-width:0">' +
        '<div class="s-crumb">' + opts.crumb + '</div>' +
        '<div class="s-title">' + opts.title + '</div>' +
      '</div>' +
      '<div class="s-right">' +
        '<div class="s-prog"><span class="sp-txt">Курс · ' + COURSE.progress + '%</span><span class="sp-bar"><span style="width:' + COURSE.progress + '%"></span></span></div>' +
        '<span class="s-ibtn">' + ICO.bell(20) + '</span>' +
        '<span class="s-ibtn">' + ICO.home(20) + '</span>' +
      '</div>' +
    '</header>' +
    '<div class="s-stage"><div class="' + opts.stageClass + '">' + opts.stageHtml + '</div></div>' +
  '</div>';
}

const CRUMB_LESSON = 'Мои курсы · <b>' + COURSE.title + '</b> · ' + COURSE.module;

// ─── Фрагменты кода ──────────────────────────────────────────────────────

const SN = {
  forRange:
'<span class="k">for</span> i <span class="k">in</span> <span class="f">range</span>(<span class="n">5</span>):\n' +
'    <span class="f">print</span>(<span class="s">"Шаг"</span>, i)',
  forRangeOut: 'Шаг 0\nШаг 1\nШаг 2\nШаг 3\nШаг 4',
  taskChoice:
'<span class="k">for</span> i <span class="k">in</span> <span class="f">range</span>(<span class="n">2</span>, <span class="n">10</span>, <span class="n">3</span>):\n' +
'    <span class="f">print</span>(i)',
  w1:
's = <span class="n">0</span>\n' +
'<span class="k">for</span> i <span class="k">in</span> <span class="f">range</span>(<span class="n">1</span>, <span class="n">6</span>):\n' +
'    s += i\n' +
'<span class="f">print</span>(s)',
  w2ans:
'<span class="k">for</span> i <span class="k">in</span> <span class="f">range</span>(<span class="n">2</span>, <span class="n">20</span>, <span class="n">2</span>):\n' +
'    <span class="f">print</span>(i)',
  w3:
'i = <span class="n">10</span>\n' +
'<span class="k">while</span> i > <span class="n">0</span>:\n' +
'    i -= <span class="n">3</span>',
};

// ─── Работа: задачи ──────────────────────────────────────────────────────

const WORK = {
  title: "Самостоятельная работа №2 «Циклы»",
  meta: "4 задачи · 30 баллов · 25 минут",
  tasks: [
    { n: 1, kind: "choice", pts: 8, title: "Сумма цикла",
      q: "Что выведет этот код?", code: SN.w1,
      opts: ["10", "15", "21", "5"], sel: 1, correct: 1 },
    { n: 2, kind: "code", pts: 8, title: "Чётные числа",
      q: "Выведите все чётные числа от 2 до 20 включительно, каждое с новой строки." },
    { n: 3, kind: "choice", pts: 8, title: "Цикл while",
      q: "Сколько раз выполнится тело цикла?", code: SN.w3,
      opts: ["3", "4", "10", "Цикл бесконечный"], sel: -1, correct: 1 },
    { n: 4, kind: "text", pts: 6, title: "for против while",
      q: "Объясните, чем цикл for отличается от while, и приведите пример, когда каждый из них удобнее." },
  ],
  draftText: "Цикл for удобен, когда число повторений известно заранее или перебирается готовая последовательность. while — когда условие остановки зависит от вычислений внутри цикла",
};

// ─── Общие билдеры блоков ────────────────────────────────────────────────

function codeBlk(code) {
  return '<div class="code"><span class="lang">Python</span><pre>' + code + '</pre></div>';
}
function outBlk(text) {
  return '<div class="out"><div class="out-lbl">Вывод</div><pre>' + text + '</pre></div>';
}
function noteBlk(html) {
  return '<div class="note"><span class="ni">' + ICO.info(15) + '</span><div>' + html + '</div></div>';
}
function formsBlk() {
  return '<div class="forms">' +
    '<div class="fr"><code>range(stop)</code><span>числа от 0 до stop − 1</span></div>' +
    '<div class="fr"><code>range(start, stop)</code><span>от start до stop − 1</span></div>' +
    '<div class="fr"><code>range(start, stop, step)</code><span>от start с шагом step, не доходя до stop</span></div>' +
  '</div>';
}
function optRow(text, state, isCode, tail) {
  // state: '', 'sel', 'ok', 'no'
  const body = isCode ? '<code>' + text + '</code>' : text;
  let tailHtml = "";
  if (tail === "ok") tailHtml = '<span class="tail t-ok">' + ICO.check(13) + 'Ваш ответ</span>';
  if (tail === "no") tailHtml = '<span class="tail t-no">' + ICO.cross(12) + 'Ваш ответ</span>';
  if (tail === "right") tailHtml = '<span class="tail t-ok">Правильный ответ</span>';
  return '<div class="opt ' + (state || "") + '"><span class="radio"></span>' + body + tailHtml + '</div>';
}
function vdBlk(kind, title, body, meta) {
  const ic = kind === "ok" ? ICO.check(13) : kind === "no" ? ICO.cross(12) : ICO.clock(13);
  return '<div class="vd vd-' + kind + '"><span class="vi">' + ic + '</span>' +
    '<div><b>' + title + '</b>' + body +
    (meta ? '<div class="vmeta">' + meta.map(function (m) { return "<span>" + m + "</span>"; }).join("") + '</div>' : "") +
    '</div></div>';
}
function timerHtml(t, label) {
  return '<span class="timer">' + ICO.clock(16) + '<b>' + t + '</b><span>' + (label || "осталось") + '</span></span>';
}
function stChip(kind) {
  if (kind === "saved") return '<span class="stc stc-saved">' + ICO.check(11) + 'Ответ сохранён</span>';
  if (kind === "draft") return '<span class="stc stc-draft">' + ICO.pen(11) + 'Черновик — не сохранён</span>';
  return '<span class="stc stc-none">Нет ответа</span>';
}
function ansCode(code, locked) {
  return '<div class="ansbox' + (locked ? " lock" : "") + '"><pre style="font:inherit;white-space:pre">' + code + '</pre></div>';
}
function ansText(text, opts) {
  opts = opts || {};
  if (!text) return '<div class="ansbox txt"><span class="ph">' + (opts.ph || "Введите ответ…") + '</span></div>';
  return '<div class="ansbox txt' + (opts.locked ? " lock" : "") + '">' + text + (opts.caret ? '<span class="caret"></span>' : "") + '</div>';
}

// ─── Видео-блок ──────────────────────────────────────────────────────────

function videoBlk() {
  return '' +
  '<div class="vp">' +
    '<div class="vp-cover"></div>' +
    '<div class="vp-ep"><div class="ep-k">' + COURSE.moduleShort + ' · Видео 4</div><div class="ep-t">range() на пяти примерах</div></div>' +
    '<div class="vp-wm">for i in range(start, stop, step)</div>' +
    '<div class="vp-play">' + ICO.play(30) + '</div>' +
    '<div class="vp-bar">' +
      '<div class="vp-line"><span class="fill"></span><span class="knob"></span></div>' +
      '<div class="vp-ctrls">' +
        ICO.play(16) + ICO.back10(17) + ICO.fwd10(17) + ICO.vol(17) +
        '<span class="vp-time">2:31 / 7:24</span><span class="grow"></span>' +
        '<span class="vp-chip">1×</span>' + ICO.cc(17) + ICO.gear(16) + ICO.fs(16) +
      '</div>' +
    '</div>' +
  '</div>';
}
function chapsBlk() {
  return '<div class="chaps">' +
    '<span class="chap"><b>0:00</b>Зачем нужен range()</span>' +
    '<span class="chap"><b>1:12</b>range(stop) и range(start, stop)</span>' +
    '<span class="chap"><b>3:40</b>Шаг и обратный отсчёт</span>' +
    '<span class="chap"><b>5:55</b>Частые ошибки</span>' +
  '</div>';
}
function attachBlk() {
  return '<div class="attach"><span class="ai">' + ICO.doc(18) + '</span>' +
    '<span class="at"><b>Конспект: три формы range().pdf</b><span>PDF · 240 КБ</span></span>' +
    '<span class="adl">' + ICO.dl(17) + '</span></div>';
}

// ─── Теория: блоки ───────────────────────────────────────────────────────

const THEORY = {
  lead: 'Цикл <b>for</b> повторяет блок кода для каждого элемента последовательности. Чаще всего его используют вместе с функцией <b>range()</b>, которая порождает последовательность целых чисел.',
  noteHtml: '<b>Обратите внимание:</b> range(5) даёт числа от 0 до 4 — правая граница <b>не входит</b> в последовательность. Это самая частая ошибка в контрольных работах.',
  formsTitle: "Три формы range()",
};

// ─── Задача с мгновенной проверкой (одиночный шаг) ──────────────────────

const TASK3 = {
  q: "Сколько строк выведет этот код?",
  opts: ["2", "3", "4", "8"],
  correct: 1,
  verdictTitle: "Верно!",
  verdictBody: "range(2, 10, 3) порождает числа 2, 5 и 8 — цикл сделает три итерации.",
  verdictMeta: ["+10 баллов", "Попытка 1 из 3", "Проверено мгновенно"],
};
