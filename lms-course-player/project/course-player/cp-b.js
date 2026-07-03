// ══════════════════════════════════════════════════════════════════════
// cp-b.js — Вариант B «Дерево курса» (LMS-паттерн)
// Структура модулей слева; в режиме работы дерево сворачивается,
// справа появляются карта задач и таймер.
// ══════════════════════════════════════════════════════════════════════

function bTree(active, doneCount) {
  let steps = "";
  STEPS.forEach(function (s, i) {
    const cls = i === active ? "on" : i < doneCount ? "done" : "";
    steps += '<div class="b-step ' + cls + '">' +
      (i < doneCount && i !== active ? ICO.check(14) : STEP_ICO[s.type](14)) +
      '<span class="txt">' + s.title + '</span></div>';
  });
  return '' +
  '<div class="b-tree">' +
    '<div class="b-tree-h"><b>' + COURSE.title + '</b>' +
      '<div class="bar"><span style="width:' + COURSE.progress + '%"></span></div>' +
      '<div class="pct">Пройдено ' + COURSE.progress + '% · 21 из 46 уроков</div>' +
    '</div>' +
    '<div class="b-tree-l">' +
      '<div class="b-mod dim"><span class="bmi ok">' + ICO.check(12) + '</span>Модуль 1 · Начала Python<span class="chev">' + ICO.chevR(13) + '</span></div>' +
      '<div class="b-mod"><span class="bmi cur">2</span>Ветвления и циклы<span class="chev">' + ICO.chevD(13) + '</span></div>' +
        '<div class="b-les dim">Урок 11. Цикл while<span class="lstat ok">' + ICO.check(13) + '</span></div>' +
        '<div class="b-les cur">Урок 12. Цикл for и range()</div>' +
        steps +
        '<div class="b-les dim">Урок 13. Вложенные циклы<span class="lstat">' + ICO.lock(13) + '</span></div>' +
      '<div class="b-mod dim"><span class="bmi lk">' + ICO.lock(11) + '</span>Модуль 3 · Коллекции<span class="chev">' + ICO.chevR(13) + '</span></div>' +
    '</div>' +
  '</div>';
}

function bSlim(active, doneCount) {
  let sq = "";
  STEPS.forEach(function (s, i) {
    const cls = i === active ? "on" : i < doneCount ? "done" : "";
    sq += '<span class="sq ' + cls + '">' + STEP_ICO[s.type](16) + '</span>';
  });
  return '<div class="b-slim"><span class="ex">' + ICO.chevR(15) + '</span>' + sq + '</div>';
}

function bHead(active, opts) {
  const s = STEPS[active];
  opts = opts || {};
  return '<div class="b-head">' +
    '<div class="r1">' + BADGE[s.type] +
      '<span class="pos">Шаг ' + (active + 1) + ' из ' + STEPS.length + '</span>' +
      '<span class="right">' +
        '<span class="b b-sm b-gh">' + ICO.chevL(14) + 'Назад</span>' +
        '<span class="b b-sm b-pri">Далее' + ICO.chevR(14) + '</span>' +
      '</span>' +
    '</div>' +
    '<h2>' + (opts.title || s.title) + '</h2>' +
    (opts.meta ? '<div class="b-hmeta">' + opts.meta + '</div>' : "") +
  '</div>';
}

function hm(ico, text) {
  return '<span class="hm">' + ico + text + '</span>';
}

// B1 — теория
function renderB_theory() {
  return bTree(0, 0) +
    '<div class="b-content">' +
      bHead(0, { title: "Цикл for: синтаксис и принцип работы",
        meta: hm(ICO.clock(14), "~4 мин чтения") + hm(ICO.doc(14), "Конспект приложен") }) +
      '<div class="b-scroll">' +
        '<div class="b-card"><div class="b-cap">Материал</div>' +
          '<div class="b-gap"><p>' + THEORY.lead + '</p>' + codeBlk(SN.forRange) + outBlk(SN.forRangeOut) + '</div>' +
        '</div>' +
        '<div class="b-card"><div class="b-cap">' + THEORY.formsTitle + '</div>' +
          '<div class="b-gap">' + formsBlk() + noteBlk(THEORY.noteHtml) + '</div>' +
        '</div>' +
      '</div>' +
    '</div>';
}

// B2 — видео
function renderB_video() {
  return bTree(1, 1) +
    '<div class="b-content">' +
      bHead(1, { meta: hm(ICO.clock(14), "7:24") + hm(ICO.playO(14), "Просмотрено 34%") + hm(ICO.doc(14), "1 вложение") }) +
      '<div class="b-scroll">' +
        '<div class="b-card">' + videoBlk() + '</div>' +
        '<div class="b-card"><div class="b-cap">Главы и материалы</div>' +
          '<div class="b-gap">' + chapsBlk() + attachBlk() + '</div>' +
        '</div>' +
      '</div>' +
    '</div>';
}

// B3 — задача (верный ответ)
function renderB_task() {
  return bTree(2, 2) +
    '<div class="b-content">' +
      bHead(2, { meta: hm(ICO.star(14), "10 баллов") + hm(ICO.bolt(14), "Мгновенная проверка") + hm(ICO.info(14), "3 попытки") }) +
      '<div class="b-scroll">' +
        '<div class="b-card"><div class="b-cap">Условие</div>' +
          '<div class="b-gap"><p>' + TASK3.q + '</p>' + codeBlk(SN.taskChoice) + '</div>' +
        '</div>' +
        '<div class="b-card"><div class="b-cap">Ваш ответ</div>' +
          '<div>' + optRow("2", "", false) + optRow("3", "ok", false, "ok") + optRow("4", "", false) + optRow("8", "", false) + '</div>' +
          '<div class="b-ansfoot"><span class="att">Попытка 1 из 3 · ответ засчитан</span><span class="sp"></span>' +
            '<span class="b b-dis">Ответить</span><span class="b b-pri">Следующий шаг' + ICO.chevR(14) + '</span></div>' +
          '<div style="margin-top:14px">' + vdBlk("ok", TASK3.verdictTitle, TASK3.verdictBody, ["+10 баллов"]) + '</div>' +
        '</div>' +
      '</div>' +
    '</div>';
}

// Карточка задачи в работе
function bTaskCard(t, chipKind, bodyHtml) {
  return '<div class="b-card">' +
    '<div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">' +
      '<span class="tkn">' + t.n + '</span><b style="font-size:14.5px;flex:1">' + t.title + '</b>' +
      (chipKind ? stChip(chipKind) : "") +
      '<span style="font:600 12px var(--font);color:var(--muted-2)">' + t.pts + ' баллов</span>' +
    '</div>' +
    '<p style="margin-bottom:13px">' + t.q + '</p>' +
    '<div class="b-gap">' + bodyHtml + '</div>' +
  '</div>';
}

function bWorkRail(state) {
  if (state === "progress" || state === "confirm") {
    return '<div class="b-rail">' +
      '<div class="b-timerbox"><div class="tt">18:26</div><div class="ts">осталось из 25:00</div><div class="tbar"><span style="width:74%"></span></div></div>' +
      '<div class="b-map"><div class="b-cap">Карта работы</div>' +
        '<div class="grid"><span class="b-cell saved">1</span><span class="b-cell saved">2</span><span class="b-cell">3</span><span class="b-cell draft">4</span></div>' +
        '<div class="leg">' +
          '<span class="li"><span class="sw saved"></span>Ответ сохранён — можно изменить</span>' +
          '<span class="li"><span class="sw draft"></span>Черновик не сохранён</span>' +
          '<span class="li"><span class="sw"></span>Нет ответа</span>' +
        '</div>' +
      '</div>' +
      '<div class="b-finish"><span class="b b-pri b-lg">' + ICO.flag(15) + 'Завершить работу</span>' +
        '<span class="hint">Проверка — только после завершения. До этого правильность не видна.</span>' +
      '</div>' +
    '</div>';
  }
  return '<div class="b-rail">' +
    '<div class="b-score"><div class="sv">16 <small>/ 24</small></div><div class="sl">баллы автопроверки</div>' +
      '<div class="row">' +
        '<span class="it"><b>2 / 3</b><span>задач верно</span></span>' +
        '<span class="it"><b>21:14</b><span>затрачено</span></span>' +
        '<span class="it"><b>до 6</b><span>на проверке</span></span>' +
      '</div>' +
    '</div>' +
    '<div class="b-map"><div class="b-cap">Итог по задачам</div>' +
      '<div class="grid"><span class="b-cell vok">1</span><span class="b-cell vno">2</span><span class="b-cell vok">3</span><span class="b-cell vwait">4</span></div>' +
      '<div class="leg">' +
        '<span class="li"><span class="sw" style="background:var(--ok-soft);border-color:var(--ok-line)"></span>Верно</span>' +
        '<span class="li"><span class="sw" style="background:var(--err-soft);border-color:var(--err-line)"></span>Неверно</span>' +
        '<span class="li"><span class="sw draft"></span>На проверке у преподавателя</span>' +
      '</div>' +
    '</div>' +
    '<span class="b">' + ICO.chevL(14) + 'Вернуться к уроку</span>' +
  '</div>';
}

function bWorkHead() {
  return '<div class="b-head">' +
    '<div class="r1">' + BADGE.work + '<span class="pos">Шаг 6 из 6</span>' +
      '<span class="right"><span class="stc stc-saved">Отвечено 2 из 4</span></span></div>' +
    '<h2>' + WORK.title + '</h2>' +
    '<div class="b-hmeta">' + hm(ICO.bolt(14), "4 задачи") + hm(ICO.star(14), "30 баллов") + hm(ICO.eyeOff(14), "Результаты — после завершения") + '</div>' +
  '</div>';
}

// B4 — работа в процессе
function renderB_work(withModal) {
  const T = WORK.tasks;
  const stage = bSlim(5, 5) +
    '<div class="b-content">' +
      bWorkHead() +
      '<div class="b-scroll">' +
        bTaskCard(T[0], "saved",
          codeBlk(T[0].code) +
          '<div>' + optRow("10", "", false) + optRow("15", "sel", false) + optRow("21", "", false) + '</div>') +
        bTaskCard(T[1], "saved", ansCode(SN.w2ans)) +
        bTaskCard(T[2], "none", codeBlk(T[2].code) + '<div>' + optRow("3", "", false) + optRow("4", "", false) + '</div>') +
      '</div>' +
    '</div>' +
    bWorkRail("progress");
  if (!withModal) return stage;
  return stage +
    '<div class="cp-dim"><div class="cp-modal">' +
      '<div class="mi">' + ICO.flag(20) + '</div>' +
      '<h3>Завершить работу?</h3>' +
      '<p>После завершения изменить ответы будет нельзя. Задачи с автопроверкой оценятся сразу, развёрнутый ответ проверит преподаватель.</p>' +
      '<div class="mm">' +
        '<span class="chip">' + ICO.check(12) + 'Отвечено 3 из 4</span>' +
        '<span class="chip warn">Задача 3 — без ответа</span>' +
        '<span class="chip">' + ICO.clock(12) + 'Осталось 12:05</span>' +
      '</div>' +
      '<div class="macts"><span class="b">Вернуться к работе</span><span class="b b-pri">' + ICO.flag(14) + 'Завершить</span></div>' +
    '</div></div>';
}

// B6 — результаты
function renderB_results() {
  const T = WORK.tasks;
  return bSlim(5, 6) +
    '<div class="b-content">' +
      '<div class="res" style="flex-shrink:0">' +
        '<span class="ri">' + ICO.check(22) + '</span>' +
        '<span class="rt"><b>Работа завершена</b><span>Разбор открыт: смотрите вердикты по каждой задаче</span></span>' +
      '</div>' +
      '<div class="b-scroll">' +
        bTaskCard(T[0], null,
          '<div>' + optRow("10", "", false) + optRow("15", "ok", false, "ok") + '</div>' +
          vdBlk("ok", "Верно · +8 баллов", "Сумма 1+2+3+4+5 = 15.")) +
        bTaskCard(T[1], null,
          ansCode(SN.w2ans, true) +
          vdBlk("no", "Неверно · 0 баллов", "Не выведено число 20 — правая граница range() не входит. Верно: range(2, 21, 2).")) +
        bTaskCard(T[3], null,
          ansText(WORK.draftText + " — например, чтение строк файла до пустой строки.", { locked: true }) +
          vdBlk("wait", "На проверке у преподавателя", "Оценка до 6 баллов появится в журнале.")) +
      '</div>' +
    '</div>' +
    bWorkRail("results");
}
