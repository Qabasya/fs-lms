// ══════════════════════════════════════════════════════════════════════
// cp-a.js — Вариант A «Лента шагов» (Stepik-паттерн)
// Горизонтальная лента шагов над контентом, одна колонка чтения.
// ══════════════════════════════════════════════════════════════════════

function aStrip(active, doneCount) {
  let out = '<div class="a-strip">';
  STEPS.forEach(function (s, i) {
    if (i > 0) out += '<span class="a-ln' + (i <= doneCount ? " done" : "") + '"></span>';
    const cls = i === active ? "on" : i < doneCount ? "done" : "";
    out += '<span class="a-sq ' + cls + '" title="' + s.title + '">' + STEP_ICO[s.type](20) + '</span>';
  });
  out += "</div>";
  return out;
}

function aShell(active, doneCount, inner, foot) {
  const s = STEPS[active];
  return '' +
    '<div class="a-mid">' +
      '<div class="a-striprow">' + aStrip(active, doneCount) +
        '<div class="a-meta"><b>Шаг ' + (active + 1) + ' из ' + STEPS.length + " · " + s.label + '</b><span>' + s.title + '</span></div>' +
      '</div>' +
      inner +
      (foot || '') +
    '</div>';
}

function aFoot(prevLabel, nextLabel, nextPrimary) {
  return '<div class="a-foot">' +
    '<span class="b b-gh">' + ICO.chevL(15) + (prevLabel || "Назад") + '</span>' +
    '<span class="pos">' + COURSE.lesson + '</span>' +
    '<span class="b ' + (nextPrimary ? "b-pri" : "") + '">' + (nextLabel || "Далее") + ICO.chevR(15) + '</span>' +
  '</div>';
}

// A1 — теория
function renderA_theory() {
  const inner =
    '<div class="a-card">' +
      '<div class="a-kick">' + BADGE.theory + '<span>· ~4 мин чтения</span></div>' +
      '<h2>Цикл for: синтаксис и принцип работы</h2>' +
      '<div class="a-gap">' +
        '<p>' + THEORY.lead + '</p>' +
        codeBlk(SN.forRange) +
        outBlk(SN.forRangeOut) +
        noteBlk(THEORY.noteHtml) +
      '</div>' +
    '</div>';
  return aShell(0, 0, inner, aFoot("К уроку 11", "Далее", true));
}

// A2 — видео
function renderA_video() {
  const inner =
    '<div class="a-card">' +
      '<div class="a-kick">' + BADGE.video + '<span>· 7:24</span></div>' +
      '<div class="a-gap" style="margin-top:4px">' +
        videoBlk() +
        chapsBlk() +
        attachBlk() +
      '</div>' +
    '</div>';
  return aShell(1, 1, inner, aFoot("Назад", "Далее", true));
}

// A3 — задача с мгновенной проверкой (отвечена верно)
function renderA_task() {
  const inner =
    '<div class="a-card">' +
      '<div class="a-kick">' + BADGE.task + '<span>· 10 баллов · мгновенная проверка</span></div>' +
      '<h2>' + TASK3.q + '</h2>' +
      '<div class="a-gap">' +
        codeBlk(SN.taskChoice) +
        '<div>' +
          optRow("2", "", false) +
          optRow("3", "ok", false, "ok") +
          optRow("4", "", false) +
          optRow("8", "", false) +
        '</div>' +
        vdBlk("ok", TASK3.verdictTitle, TASK3.verdictBody, TASK3.verdictMeta) +
      '</div>' +
    '</div>';
  return aShell(2, 2, inner, aFoot("Назад", "Следующий шаг", true));
}

// Панель работы (A4 / A5)
function aWorkbar(state) {
  if (state === "progress") {
    return '<div class="a-workbar">' + BADGE.work +
      '<div class="wb-t"><b>' + WORK.title + '</b><span>' + WORK.meta + ' · результаты после завершения</span></div>' +
      '<span class="wb-sp"></span>' +
      '<div class="a-prog"><span class="ap-txt">Отвечено 2 из 4</span><span class="ap-bar"><span style="width:50%"></span></span></div>' +
      timerHtml("18:26") +
      '<span class="b b-pri">' + ICO.flag(14) + 'Завершить работу</span>' +
    '</div>';
  }
  return '<div class="a-workbar">' + BADGE.work +
    '<div class="wb-t"><b>' + WORK.title + '</b><span>Завершена сегодня в 14:52 · затрачено 21:14 из 25:00</span></div>' +
    '<span class="wb-sp"></span>' +
    '<div class="a-prog"><span class="ap-txt">16 из 24 баллов</span><span class="ap-bar"><span style="width:66%;background:var(--ok)"></span></span></div>' +
    '<span class="stc stc-draft">' + ICO.clock(11) + 'Задача 4 — на проверке</span>' +
  '</div>';
}

function aTaskCard(t, bodyHtml, chipKind) {
  return '<div class="a-task">' +
    '<div class="th"><span class="tkn">' + t.n + '</span><b>' + t.title + '</b>' +
    (chipKind ? stChip(chipKind) : "") +
    '<span class="pts">' + t.pts + ' баллов</span></div>' +
    '<p class="q">' + t.q + '</p>' +
    '<div class="tgap">' + bodyHtml + '</div>' +
  '</div>';
}

// A4 — работа в процессе
function renderA_work() {
  const T = WORK.tasks;
  const inner =
    aWorkbar("progress") +
    '<div class="a-scroll">' +
      aTaskCard(T[0],
        codeBlk(T[0].code) +
        '<div>' + optRow("10", "", false) + optRow("15", "sel", false) + optRow("21", "", false) + optRow("5", "", false) + '</div>' +
        '<div style="display:flex;gap:10px;align-items:center"><span class="b b-sm">Изменить ответ</span><span style="font:500 12px var(--font);color:var(--muted-2)">Правильность станет видна после завершения работы</span></div>',
        "saved") +
      aTaskCard(T[1],
        ansCode(SN.w2ans) +
        '<div style="display:flex;gap:10px;align-items:center"><span class="b b-sm">Изменить ответ</span></div>',
        "saved") +
      aTaskCard(T[2],
        codeBlk(T[2].code) +
        '<div>' + optRow("3", "", false) + optRow("4", "", false) + optRow("10", "", false) + optRow("Цикл бесконечный", "", false) + '</div>',
        "none") +
    '</div>';
  return aShell(5, 5, inner);
}

// A5 — работа: результаты
function renderA_results() {
  const T = WORK.tasks;
  const inner =
    '<div class="res" style="margin-bottom:14px;flex-shrink:0">' +
      '<span class="ri">' + ICO.check(22) + '</span>' +
      '<span class="rt"><b>Работа завершена</b><span>2 из 3 автопроверяемых задач решены верно · развёрнутый ответ ждёт проверки преподавателя</span></span>' +
      '<span class="rstats">' +
        '<span class="rs"><b>16 / 24</b><span>баллы автопроверки</span></span>' +
        '<span class="rs"><b>21:14</b><span>затрачено</span></span>' +
        '<span class="rs"><b>до 6</b><span>за задачу 4</span></span>' +
      '</span>' +
    '</div>' +
    '<div class="a-scroll">' +
      aTaskCard(T[0],
        '<div>' + optRow("10", "", false) + optRow("15", "ok", false, "ok") + optRow("21", "", false) + '</div>' +
        vdBlk("ok", "Верно · +8 баллов", "Сумма 1+2+3+4+5 = 15."),
        null) +
      aTaskCard(T[1],
        ansCode(SN.w2ans, true) +
        vdBlk("no", "Неверно · 0 баллов", "Не выведено число 20: правая граница range() не входит в последовательность. Нужно range(2, 21, 2)."),
        null) +
      aTaskCard(T[3],
        ansText(WORK.draftText + " — например, чтение строк файла до пустой строки.", { locked: true }) +
        vdBlk("wait", "На проверке у преподавателя", "Оценка появится в журнале — обычно в течение двух дней."),
        null) +
    '</div>';
  return aShell(5, 6, inner);
}
