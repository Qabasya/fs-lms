// ══════════════════════════════════════════════════════════════════════
// cp-c.js — Вариант C «Фокус-режим»
// Минимум интерфейса: полоса прогресса сверху, крупная типографика,
// плавающий док. Работа — по одной задаче на экран.
// ══════════════════════════════════════════════════════════════════════

function cTop(active, doneCount) {
  let segs = "";
  STEPS.forEach(function (s, i) {
    segs += '<span class="c-seg' + (i < doneCount ? " done" : i === active ? " on" : "") + '"></span>';
  });
  return '<div class="c-top">' +
    '<span class="c-back">' + ICO.chevL(16) + '</span>' +
    '<span class="c-lbl">' + COURSE.lesson + ' <span>· ' + COURSE.moduleShort + '</span></span>' +
    '<span class="c-segs">' + segs + '</span>' +
    '<span class="c-count">Шаг ' + (active + 1) + ' из ' + STEPS.length + '</span>' +
  '</div>';
}

function cDock(prev, next, nextPrimary, pos) {
  return '<div class="c-dock">' +
    '<span class="b b-gh">' + ICO.chevL(15) + prev + '</span>' +
    '<span class="pos">' + pos + '</span>' +
    '<span class="b ' + (nextPrimary ? "b-pri" : "") + '">' + next + ICO.chevR(15) + '</span>' +
  '</div>';
}

// C1 — теория
function renderC_theory() {
  return cTop(0, 0) +
    '<div class="c-scroll"><div class="c-col">' +
      '<div class="c-kickrow">' + BADGE.theory + '<span class="pos">~4 мин чтения</span></div>' +
      '<h1>Цикл for: синтаксис и принцип работы</h1>' +
      '<div class="c-gap">' +
        '<p>' + THEORY.lead + '</p>' +
        codeBlk(SN.forRange) +
        outBlk(SN.forRangeOut) +
        noteBlk(THEORY.noteHtml) +
      '</div>' +
    '</div></div>' +
    cDock("Назад", "Далее", true, "Теория · 1 из 6");
}

// C2 — видео
function renderC_video() {
  return cTop(1, 1) +
    '<div class="c-scroll"><div class="c-col">' +
      '<div class="c-kickrow">' + BADGE.video + '<span class="pos">7:24 · просмотрено 34%</span></div>' +
      '<h1>range() на пяти примерах</h1>' +
      '<div class="c-gap">' + videoBlk() + chapsBlk() + attachBlk() + '</div>' +
    '</div></div>' +
    cDock("Назад", "Далее", true, "Видео · 2 из 6");
}

// C3 — задача (верный ответ)
function renderC_task() {
  return cTop(2, 2) +
    '<div class="c-scroll"><div class="c-col">' +
      '<div class="c-kickrow">' + BADGE.task + '<span class="pos">10 баллов · мгновенная проверка · попытка 1 из 3</span></div>' +
      '<h1>' + TASK3.q + '</h1>' +
      '<div class="c-gap">' +
        codeBlk(SN.taskChoice) +
        '<div>' +
          optRow("2", "", false).replace('class="opt ', 'class="opt c-opt ') +
          optRow("3", "ok", false, "ok").replace('class="opt ', 'class="opt c-opt ') +
          optRow("4", "", false).replace('class="opt ', 'class="opt c-opt ') +
          optRow("8", "", false).replace('class="opt ', 'class="opt c-opt ') +
        '</div>' +
        vdBlk("ok", TASK3.verdictTitle, TASK3.verdictBody, ["+10 баллов"]).replace('class="vd ', 'class="vd c-vd ') +
      '</div>' +
    '</div></div>' +
    cDock("Назад", "Следующий шаг", true, "Задача · 3 из 6");
}

// C: топ-бар работы — точки задач вместо сегментов
function cWorkTop(state) {
  const dots = state === "results"
    ? '<span class="c-dot vok">1</span><span class="c-dot vno">2</span><span class="c-dot vok">3</span><span class="c-dot vwait">4</span>'
    : '<span class="c-dot saved">1</span><span class="c-dot cur">2</span><span class="c-dot">3</span><span class="c-dot">4</span>';
  return '<div class="c-top">' +
    '<span class="c-back">' + ICO.chevL(16) + '</span>' +
    '<span class="c-lbl">' + WORK.title + ' <span>· ' + WORK.meta + '</span></span>' +
    '<span class="c-dots">' + dots + '</span>' +
    '<span class="c-topright">' +
      (state === "results"
        ? '<span class="stc stc-saved">' + ICO.check(11) + 'Завершена · 21:14</span>'
        : timerHtml("18:26") + '<span class="b b-sm b-pri">' + ICO.flag(13) + 'Завершить</span>') +
    '</span>' +
  '</div>';
}

// C4 — работа: одна задача на экран
function renderC_work() {
  const t = WORK.tasks[1];
  return cWorkTop("progress") +
    '<div class="c-scroll"><div class="c-col">' +
      '<div class="c-kickrow">' + BADGE.work + '<span class="pos">Задача 2 из 4 · ' + t.pts + ' баллов</span>' +
        '<span style="margin-left:auto">' + stChip("saved") + '</span></div>' +
      '<h1>' + t.title + '</h1>' +
      '<p>' + t.q + '</p>' +
      '<div class="c-anslbl">Ваш ответ — код</div>' +
      ansCode(SN.w2ans) +
      '<div class="c-savebar">' +
        '<span class="b b-pri">' + ICO.check(14) + 'Сохранить ответ</span>' +
        '<span class="hint">Ответ можно менять до завершения работы.<br>Правильность станет видна после кнопки «Завершить».</span>' +
      '</div>' +
    '</div></div>' +
    cDock("Задача 1", "Задача 3", false, "Отвечено 2 из 4");
}

// C5 — работа: результаты
function renderC_results() {
  return cWorkTop("results") +
    '<div class="c-scroll"><div class="c-col">' +
      '<div class="c-hero">' +
        '<div class="chk">' + ICO.check(38) + '</div>' +
        '<h1>Работа завершена</h1>' +
        '<div class="sub">2 из 3 автопроверяемых задач — верно · развёрнутый ответ на проверке</div>' +
        '<div class="stats">' +
          '<span class="cs"><b>16 / 24</b><span>баллы автопроверки</span></span>' +
          '<span class="cs"><b>21:14</b><span>затрачено из 25:00</span></span>' +
          '<span class="cs"><b>до 6</b><span>баллов на проверке</span></span>' +
        '</div>' +
      '</div>' +
      '<div class="c-rows">' +
        '<div class="c-row"><span class="vdot ok">' + ICO.check(14) + '</span>' +
          '<span class="rt"><b>1. Сумма цикла</b><span>Ответ: 15</span></span><span class="pts">+8 баллов</span><span class="chev">' + ICO.chevD(14) + '</span></div>' +
        '<div class="c-row" style="border-bottom:none"><span class="vdot no">' + ICO.cross(13) + '</span>' +
          '<span class="rt"><b>2. Чётные числа</b><span>Разбор раскрыт</span></span><span class="pts dim">0 баллов</span><span class="chev" style="transform:rotate(180deg)">' + ICO.chevD(14) + '</span></div>' +
        '<div class="c-rowx"><div class="xg">' +
          ansCode(SN.w2ans, true) +
          vdBlk("no", "Не выведено число 20", "Правая граница range() не входит в последовательность. Верно: range(2, 21, 2).") +
        '</div></div>' +
        '<div class="c-row"><span class="vdot ok">' + ICO.check(14) + '</span>' +
          '<span class="rt"><b>3. Цикл while</b><span>Ответ: 4</span></span><span class="pts">+8 баллов</span><span class="chev">' + ICO.chevD(14) + '</span></div>' +
        '<div class="c-row"><span class="vdot wait">' + ICO.clock(14) + '</span>' +
          '<span class="rt"><b>4. for против while</b><span>Развёрнутый ответ · проверяет преподаватель</span></span><span class="pts dim">до 6 баллов</span><span class="chev">' + ICO.chevD(14) + '</span></div>' +
      '</div>' +
    '</div></div>' +
    cDock("К уроку", "Следующий урок", true, "Работа · 6 из 6");
}
