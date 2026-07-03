// ══════════════════════════════════════════════════════════════════════
// final-steps.js — контент шагов: текст, видео, задача (мгновенная
// проверка), работа (проверка после завершения) + модалка и результаты
// ══════════════════════════════════════════════════════════════════════

function esc(s) {
  return String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
}

const SN_NESTED =
'<span class="k">for</span> i <span class="k">in</span> <span class="f">range</span>(<span class="n">1</span>, <span class="n">4</span>):\n' +
'    <span class="k">for</span> j <span class="k">in</span> <span class="f">range</span>(<span class="n">1</span>, <span class="n">4</span>):\n' +
'        <span class="f">print</span>(i, j, i * j)';
const OUT_NESTED = "1 1 1\n1 2 2\n1 3 3\n2 1 2\n2 2 4\n2 3 6\n3 1 3\n3 2 6\n3 3 9";

function navRow(opts) {
  const first = S.step === 0;
  return '<div class="cnav">' +
    '<span class="b b-gh' + (first ? " b-dis" : "") + '" id="navPrev">' + ICO.chevL(15) + 'Назад</span>' +
    '<span class="pos">Урок 12 · шаг ' + (S.step + 1) + " из " + FSTEPS.length + '</span>' +
    (opts.next ? '<span class="b b-pri" id="navNext">' + opts.next + ICO.chevR(15) + '</span>' : '<span style="width:86px"></span>') +
  '</div>';
}
function bindNav(marksDone) {
  const p = document.getElementById("navPrev");
  if (p) p.addEventListener("click", function () { gotoStep(S.step - 1); });
  const n = document.getElementById("navNext");
  if (n) n.addEventListener("click", function () {
    if (marksDone) markDone(S.step);
    if (S.step < FSTEPS.length - 1) gotoStep(S.step + 1);
  });
}

// ── Текстовые шаги ─────────────────────────────────────────────────────
function stepText1() {
  return '<div class="card16">' +
    '<div class="kick">' + tBadge("text") + '<span>· ~4 мин чтения</span></div>' +
    '<h2>Цикл for: синтаксис и принцип работы</h2>' +
    '<div class="gap16">' +
      '<p>' + THEORY.lead + '</p>' +
      codeBlk(SN.forRange) + outBlk(SN.forRangeOut) + formsBlk() + noteBlk(THEORY.noteHtml) +
    '</div></div>' + navRow({ next: "Далее" });
}
function stepText2() {
  return '<div class="card16">' +
    '<div class="kick">' + tBadge("text") + '<span>· ~3 мин чтения</span></div>' +
    '<h2>Вложенные циклы</h2>' +
    '<div class="gap16">' +
      '<p>Циклы можно вкладывать друг в друга: внутренний цикл выполняется полностью для каждой итерации внешнего. Так перебирают пары значений — например, строки и столбцы таблицы.</p>' +
      codeBlk(SN_NESTED) + outBlk(OUT_NESTED) +
      noteBlk("<b>Сколько строк в выводе?</b> Внешний × внутренний: здесь 3 × 3 = 9. С вложенными циклами объём работы растёт очень быстро.") +
    '</div></div>' + navRow({ next: "К работе" });
}

// ── Видео ──────────────────────────────────────────────────────────────
const VDUR = 444;
function stepVideo() {
  const v = S.video;
  return '<div class="card16">' +
    '<div class="kick">' + tBadge("video") + '<span>· 7:24</span></div>' +
    '<div class="gap16" style="margin-top:6px">' +
      '<div class="vp' + (v.playing ? " playing" : "") + '" id="vp">' +
        '<div class="vp-cover"></div>' +
        '<div class="vp-ep"><div class="ep-k">' + COURSE.moduleShort + ' · Видео 4</div><div class="ep-t">range() на пяти примерах</div></div>' +
        '<div class="vp-wm">for i in range(start, stop, step)</div>' +
        '<div class="vp-play' + (v.playing ? " hid" : "") + '" id="vpBig">' + ICO.play(30) + '</div>' +
        '<div class="vp-bar">' +
          '<div class="vp-line" id="vpLine"><span class="fill" id="vpFill"></span><span class="knob" id="vpKnob"></span></div>' +
          '<div class="vp-ctrls">' +
            '<span id="vpIco" style="display:flex"></span>' +
            '<span id="vpB10" style="display:flex">' + ICO.back10(17) + '</span>' +
            '<span id="vpF10" style="display:flex">' + ICO.fwd10(17) + '</span>' + ICO.vol(17) +
            '<span class="vp-time" id="vpTime"></span><span class="grow"></span>' +
            '<span class="vp-chip">1×</span>' + ICO.cc(17) + ICO.gear(16) + ICO.fs(16) +
          '</div>' +
        '</div>' +
      '</div>' +
      '<div class="chaps">' +
        '<span class="chap" data-t="0"><b>0:00</b>Зачем нужен range()</span>' +
        '<span class="chap" data-t="72"><b>1:12</b>range(stop) и range(start, stop)</span>' +
        '<span class="chap" data-t="220"><b>3:40</b>Шаг и обратный отсчёт</span>' +
        '<span class="chap" data-t="355"><b>5:55</b>Частые ошибки</span>' +
      '</div>' +
      attachBlk() +
    '</div></div>' + navRow({ next: "Далее" });
}
function updateVideoUI() {
  const fill = document.getElementById("vpFill");
  if (!fill) return;
  const pct = (S.video.sec / VDUR) * 100;
  fill.style.width = pct + "%";
  document.getElementById("vpKnob").style.left = pct + "%";
  document.getElementById("vpTime").textContent = fmt(S.video.sec) + " / 7:24";
  document.getElementById("vp").classList.toggle("playing", S.video.playing);
  document.getElementById("vpBig").classList.toggle("hid", S.video.playing);
  document.getElementById("vpIco").innerHTML = S.video.playing
    ? '<svg width="16" height="16" viewBox="0 0 20 20" fill="none"><rect x="5.5" y="4.5" width="3.2" height="11" rx="1" fill="currentColor"/><rect x="11.3" y="4.5" width="3.2" height="11" rx="1" fill="currentColor"/></svg>'
    : ICO.play(16);
}
function bindVideo() {
  function tgl() { S.video.playing = !S.video.playing; save(); updateVideoUI(); }
  document.getElementById("vpBig").addEventListener("click", tgl);
  document.getElementById("vpIco").addEventListener("click", tgl);
  document.getElementById("vpB10").addEventListener("click", function () { S.video.sec = Math.max(0, S.video.sec - 10); save(); updateVideoUI(); });
  document.getElementById("vpF10").addEventListener("click", function () { S.video.sec = Math.min(VDUR, S.video.sec + 10); save(); updateVideoUI(); });
  document.getElementById("vpLine").addEventListener("click", function (e) {
    const r = e.currentTarget.getBoundingClientRect();
    S.video.sec = Math.round(((e.clientX - r.left) / r.width) * VDUR);
    save(); updateVideoUI();
  });
  document.querySelectorAll(".chap").forEach(function (c) {
    c.addEventListener("click", function () { S.video.sec = +c.dataset.t; S.video.playing = true; save(); updateVideoUI(); });
  });
  document.querySelector(".attach").addEventListener("click", function () { toast("Скачивание конспекта — вне прототипа"); });
  updateVideoUI();
}

// ── Задача с мгновенной проверкой ──────────────────────────────────────
function stepTask() {
  const t = S.task;
  const locked = t.correct || t.revealed;
  let optsHtml = "";
  TASK3.opts.forEach(function (o, i) {
    let cls = "", tail = "";
    if (t.correct && i === TASK3.correct) { cls = "ok"; tail = '<span class="tail t-ok">' + ICO.check(13) + 'Ваш ответ</span>'; }
    else if (t.revealed && i === TASK3.correct) { cls = "ok"; tail = '<span class="tail t-ok">Правильный ответ</span>'; }
    else if (t.answered && !t.correct && i === t.sel) { cls = "no"; tail = '<span class="tail t-no">' + ICO.cross(12) + 'Ваш ответ</span>'; }
    else if (!t.answered && i === t.sel) cls = "sel";
    optsHtml += '<div class="opt ' + cls + (locked ? " dis" : "") + '" data-opt="' + i + '"><span class="radio"></span>' + o + tail + '</div>';
  });

  let verdict = "", action = "";
  if (t.correct) {
    verdict = vdBlk("ok", "Верно!", TASK3.verdictBody, ["+10 баллов", "Попытка " + (4 - t.attempts) + " из 3", "Проверено мгновенно"]);
  } else if (t.revealed) {
    verdict = vdBlk("no", "Попытки закончились", "Правильный ответ — 3: range(2, 10, 3) даёт 2, 5 и 8.", ["0 баллов"]);
  } else if (t.answered) {
    verdict = vdBlk("no", "Неверно", "Вспомните: третий аргумент range() — шаг. Посчитайте, какие числа попадут в последовательность.", ["Осталось попыток: " + t.attempts]);
    action = '<span class="b" id="taskRetry">Попробовать ещё раз</span>';
  } else {
    action = '<span class="b b-pri' + (t.sel == null ? " b-dis" : "") + '" id="taskSubmit">Ответить</span>' +
      '<span class="wnote">Проверка мгновенная · попыток: ' + t.attempts + '</span>';
  }

  return '<div class="card16">' +
    '<div class="kick">' + tBadge("task") + '<span>· 10 баллов · мгновенная проверка</span></div>' +
    '<h2>' + TASK3.q + '</h2>' +
    '<div class="gap16">' +
      codeBlk(SN.taskChoice) +
      '<div>' + optsHtml + '</div>' +
      (action ? '<div style="display:flex;gap:12px;align-items:center">' + action + '</div>' : "") +
      verdict +
    '</div></div>' + navRow({ next: t.correct ? "Следующий шаг" : "Далее" });
}
function bindTask() {
  const t = S.task;
  document.querySelectorAll("[data-opt]").forEach(function (el) {
    el.addEventListener("click", function () {
      if (t.correct || t.revealed) return;
      t.sel = +el.dataset.opt;
      t.answered = false;
      save(); renderStep();
    });
  });
  const sub = document.getElementById("taskSubmit");
  if (sub) sub.addEventListener("click", function () {
    if (t.sel == null) return;
    t.answered = true;
    if (t.sel === TASK3.correct) { t.correct = true; markDone(2); toast("Верно! +10 баллов"); }
    else { t.attempts--; if (t.attempts <= 0) t.revealed = true; }
    save(); renderStep();
  });
  const re = document.getElementById("taskRetry");
  if (re) re.addEventListener("click", function () {
    t.answered = false; t.sel = null;
    save(); renderStep();
  });
}

// ── Работа (проверка после завершения) ─────────────────────────────────
function answeredCount() {
  const w = S.work;
  return (w.a1 != null ? 1 : 0) + (w.a2.trim() ? 1 : 0) + (w.a3 != null ? 1 : 0) + (w.a4.trim() ? 1 : 0);
}
function chipHtml(has) {
  return has
    ? '<span class="stc stc-saved">' + ICO.check(11) + 'Ответ сохранён</span>'
    : '<span class="stc stc-none">Нет ответа</span>';
}
function wOpts(key, list, sel, verdict) {
  let h = "";
  list.forEach(function (o, i) {
    let cls = "", tail = "";
    if (verdict) {
      if (i === verdict.correct) { cls = "ok"; tail = '<span class="tail t-ok">' + (i === sel ? ICO.check(13) + "Ваш ответ" : "Правильный ответ") + '</span>'; }
      else if (i === sel) { cls = "no"; tail = '<span class="tail t-no">' + ICO.cross(12) + 'Ваш ответ</span>'; }
      cls += " dis";
    } else if (i === sel) cls = "sel";
    h += '<div class="opt ' + cls + '" data-wopt="' + key + ":" + i + '"><span class="radio"></span>' + o + tail + '</div>';
  });
  return h;
}
function wTaskCard(t, chipKey, body) {
  return '<div class="a-task" id="wt' + t.n + '">' +
    '<div class="th"><span class="tkn">' + t.n + '</span><b>' + t.title + '</b>' +
    (chipKey !== null ? '<span id="wchip' + t.n + '">' + chipHtml(chipKey) + '</span>' : "") +
    '<span class="pts">' + t.pts + ' баллов</span></div>' +
    '<p class="q">' + t.q + '</p>' +
    '<div class="tgap">' + body + '</div></div>';
}

function stepWork() {
  const w = S.work;
  if (!w.startTs && !w.submitted) { w.startTs = Date.now(); save(); }
  return w.submitted ? workResults() : workProgress();
}

function workProgress() {
  const w = S.work, T = WORK.tasks, n = answeredCount();
  return '' +
  '<div class="a-workbar">' + tBadge("work") +
    '<div class="wb-t"><b>' + WORK.title + '</b><span>' + WORK.meta + ' · ответы можно менять до завершения</span></div>' +
    '<span class="wb-sp"></span>' +
    '<div class="a-prog"><span class="ap-txt" id="wprog">Отвечено ' + n + ' из 4</span><span class="ap-bar"><span id="wbar" style="width:' + n * 25 + '%"></span></span></div>' +
    '<span class="timer">' + ICO.clock(16) + '<b id="wtimer">—:—</b><span>осталось</span></span>' +
    '<span class="b b-pri" id="wfinish">' + ICO.flag(14) + 'Завершить работу</span>' +
  '</div>' +
  '<div class="wstack" style="margin-top:14px">' +
    wTaskCard(T[0], w.a1 != null, codeBlk(T[0].code) + '<div>' + wOpts("a1", T[0].opts, w.a1) + '</div>') +
    wTaskCard(T[1], !!w.a2.trim(),
      '<textarea class="ansbox" id="wtA2" rows="4" spellcheck="false" placeholder="# напишите код здесь">' + esc(w.a2) + '</textarea>' +
      '<span class="wnote">Ответ сохраняется автоматически · правильность станет видна после завершения</span>') +
    wTaskCard(T[2], w.a3 != null, codeBlk(T[2].code) + '<div>' + wOpts("a3", T[2].opts, w.a3) + '</div>') +
    wTaskCard(T[3], !!w.a4.trim(),
      '<textarea class="ansbox txt" id="wtA4" rows="4" placeholder="Ваш развёрнутый ответ… (проверяет преподаватель)">' + esc(w.a4) + '</textarea>' +
      '<span class="wnote">Развёрнутый ответ оценивает преподаватель после завершения работы</span>') +
  '</div>' +
  '<div class="cnav"><span class="b b-gh" id="navPrev">' + ICO.chevL(15) + 'Назад</span><span class="pos">Урок 12 · шаг 5 из 5 · Работа</span><span style="width:86px"></span></div>';
}

function updateWorkTimerUI(left) {
  const el = document.getElementById("wtimer");
  if (!el) return;
  el.textContent = fmt(left);
  el.style.color = left < 60 ? "var(--err)" : "";
}
function updateWorkProgressUI() {
  const n = answeredCount();
  const p = document.getElementById("wprog"), b = document.getElementById("wbar");
  if (p) p.textContent = "Отвечено " + n + " из 4";
  if (b) b.style.width = n * 25 + "%";
}
function bindWork() {
  const w = S.work;
  document.querySelectorAll("[data-wopt]").forEach(function (el) {
    el.addEventListener("click", function () {
      const kv = el.dataset.wopt.split(":");
      w[kv[0]] = +kv[1];
      save();
      const group = el.parentElement;
      group.querySelectorAll(".opt").forEach(function (o) { o.classList.toggle("sel", o === el); });
      const n = kv[0] === "a1" ? 1 : 3;
      document.getElementById("wchip" + n).innerHTML = chipHtml(true);
      updateWorkProgressUI();
    });
  });
  [["wtA2", "a2", 2], ["wtA4", "a4", 4]].forEach(function (cfg) {
    const ta = document.getElementById(cfg[0]);
    if (!ta) return;
    ta.addEventListener("input", function () {
      w[cfg[1]] = ta.value;
      save();
      document.getElementById("wchip" + cfg[2]).innerHTML = chipHtml(!!ta.value.trim());
      updateWorkProgressUI();
    });
  });
  const fin = document.getElementById("wfinish");
  if (fin) fin.addEventListener("click", openConfirm);
  const p = document.getElementById("navPrev");
  if (p) p.addEventListener("click", function () { gotoStep(S.step - 1); });
  const left = WORK_LIMIT - (Date.now() - w.startTs) / 1000;
  updateWorkTimerUI(left);
}

// подтверждение завершения
function openConfirm() {
  const n = answeredCount();
  const left = WORK_LIMIT - (Date.now() - S.work.startTs) / 1000;
  const dim = document.createElement("div");
  dim.className = "cp-dim";
  dim.innerHTML = '<div class="cp-modal">' +
    '<div class="mi">' + ICO.flag(20) + '</div>' +
    '<h3>Завершить работу?</h3>' +
    '<p>После завершения изменить ответы будет нельзя. Задачи с автопроверкой оценятся сразу, развёрнутый ответ проверит преподаватель.</p>' +
    '<div class="mm">' +
      '<span class="chip">' + ICO.check(12) + 'Отвечено ' + n + ' из 4</span>' +
      (n < 4 ? '<span class="chip warn">Без ответа: ' + (4 - n) + '</span>' : "") +
      '<span class="chip">' + ICO.clock(12) + 'Осталось ' + fmt(left) + '</span>' +
    '</div>' +
    '<div class="macts"><span class="b" id="mBack">Вернуться к работе</span><span class="b b-pri" id="mGo">' + ICO.flag(14) + 'Завершить</span></div>' +
  '</div>';
  document.body.appendChild(dim);
  dim.addEventListener("click", function (e) { if (e.target === dim) dim.remove(); });
  dim.querySelector("#mBack").addEventListener("click", function () { dim.remove(); });
  dim.querySelector("#mGo").addEventListener("click", function () { dim.remove(); submitWork(false); });
}

function submitWork(auto) {
  const w = S.work;
  if (w.submitted) return;
  w.submitted = true;
  w.submitTs = Date.now();
  save();
  markDone(4);
  if (S.step === 4) renderStep();
  toast(auto ? "Время вышло — работа завершена автоматически" : "Работа завершена. Автопроверка выполнена");
}

// результаты
function checkCode(src) {
  return /range\s*\(\s*2\s*,\s*21\s*,\s*2\s*\)/.test(src) && /print\s*\(/.test(src);
}
function workResults() {
  const w = S.work, T = WORK.tasks;
  const r1 = w.a1 === T[0].correct, r3 = w.a3 === T[2].correct;
  const r2 = checkCode(w.a2);
  const given4 = !!w.a4.trim();
  const score = (r1 ? 8 : 0) + (r2 ? 8 : 0) + (r3 ? 8 : 0);
  const okCount = (r1 ? 1 : 0) + (r2 ? 1 : 0) + (r3 ? 1 : 0);
  const spent = fmt(Math.min(WORK_LIMIT, (w.submitTs - w.startTs) / 1000));

  function vd1() {
    if (w.a1 == null) return vdBlk("no", "Ответ не был дан", "Правильный ответ: 15 — сумма 1+2+3+4+5.");
    return r1 ? vdBlk("ok", "Верно · +8 баллов", "Сумма 1+2+3+4+5 = 15.")
      : vdBlk("no", "Неверно · 0 баллов", "Правильный ответ: 15 — цикл суммирует числа от 1 до 5.");
  }
  function vd2() {
    if (!w.a2.trim()) return vdBlk("no", "Ответ не был дан", "Ожидался цикл: for i in range(2, 21, 2): print(i)");
    return r2 ? vdBlk("ok", "Верно · +8 баллов", "range(2, 21, 2) даёт все чётные от 2 до 20 включительно.")
      : vdBlk("no", "Неверно · 0 баллов", "Проверьте границы: правая граница range() не входит, поэтому нужно range(2, 21, 2).");
  }
  function vd3() {
    if (w.a3 == null) return vdBlk("no", "Ответ не был дан", "Правильный ответ: 4 (i: 10 → 7 → 4 → 1).");
    return r3 ? vdBlk("ok", "Верно · +8 баллов", "i уменьшается: 10 → 7 → 4 → 1 — четыре итерации.")
      : vdBlk("no", "Неверно · 0 баллов", "Правильный ответ: 4 (i: 10 → 7 → 4 → 1, затем i = −2 останавливает цикл).");
  }
  function vd4() {
    return given4 ? vdBlk("wait", "На проверке у преподавателя", "Оценка до 6 баллов появится в журнале — обычно в течение двух дней.")
      : vdBlk("no", "Ответ не был дан", "Развёрнутый ответ не отправлен — 0 баллов.");
  }

  return '' +
  '<div class="res">' +
    '<span class="ri">' + ICO.check(22) + '</span>' +
    '<span class="rt"><b>Работа завершена</b><span>' + okCount + ' из 3 автопроверяемых задач — верно' +
      (given4 ? " · развёрнутый ответ на проверке у преподавателя" : "") + '</span></span>' +
    '<span class="rstats">' +
      '<span class="rs"><b>' + score + ' / 24</b><span>баллы автопроверки</span></span>' +
      '<span class="rs"><b>' + spent + '</b><span>затрачено из 25:00</span></span>' +
      '<span class="rs"><b>' + (given4 ? "до 6" : "0") + '</b><span>за задачу 4</span></span>' +
    '</span>' +
  '</div>' +
  '<div class="wstack" style="margin-top:14px">' +
    wTaskCard(WORK.tasks[0], null, codeBlk(T[0].code) + '<div>' + wOpts("a1", T[0].opts, w.a1, { correct: T[0].correct }) + '</div>' + vd1()) +
    wTaskCard(WORK.tasks[1], null,
      (w.a2.trim() ? '<div class="ansbox lock"><pre style="font:inherit;white-space:pre-wrap">' + esc(w.a2) + '</pre></div>' : "") + vd2()) +
    wTaskCard(WORK.tasks[2], null, codeBlk(T[2].code) + '<div>' + wOpts("a3", T[2].opts, w.a3, { correct: T[2].correct }) + '</div>' + vd3()) +
    wTaskCard(WORK.tasks[3], null,
      (w.a4.trim() ? '<div class="ansbox txt lock">' + esc(w.a4) + '</div>' : "") + vd4()) +
  '</div>' +
  '<div class="cnav">' +
    '<span class="b b-gh" id="navPrev">' + ICO.chevL(15) + 'Назад</span>' +
    '<span class="pos" style="display:flex;gap:14px;align-items:center">Урок 12 завершён' +
      '<span class="b b-sm" id="wreset">Пройти заново</span></span>' +
    '<span class="b b-pri" id="navNext">К уроку 13' + ICO.chevR(15) + '</span>' +
  '</div>';
}
function bindResults() {
  const p = document.getElementById("navPrev");
  if (p) p.addEventListener("click", function () { gotoStep(S.step - 1); });
  const n = document.getElementById("navNext");
  if (n) n.addEventListener("click", function () { toast("Урок 13 откроется после проверки преподавателем"); });
  const r = document.getElementById("wreset");
  if (r) r.addEventListener("click", function () {
    S.work = { a1: null, a2: "", a3: null, a4: "", startTs: Date.now(), submitted: false, submitTs: null };
    S.done[4] = false;
    save(); renderStrip(); renderRail(); renderStep();
    toast("Попытка сброшена — таймер запущен заново");
  });
}

// ── Диспетчер шага ─────────────────────────────────────────────────────
function renderStep() {
  const root = document.getElementById("stepRoot");
  const st = FSTEPS[S.step];
  root.setAttribute("data-screen-label", "Шаг " + (S.step + 1) + " · " + TYPES[st.type].label + " · " + st.title);
  root.style.animation = "none";
  void root.offsetWidth;
  root.style.animation = "";

  if (S.step === 0) { root.innerHTML = stepText1(); bindNav(true); }
  else if (S.step === 1) { root.innerHTML = stepVideo(); bindVideo(); bindNav(true); }
  else if (S.step === 2) { root.innerHTML = stepTask(); bindTask(); bindNav(false); }
  else if (S.step === 3) { root.innerHTML = stepText2(); bindNav(true); }
  else { root.innerHTML = stepWork(); if (S.work.submitted) bindResults(); else bindWork(); }

  // счётчик пройденного в топбаре
  const dc = S.done.filter(Boolean).length;
  const t = document.getElementById("spTxt"), b = document.getElementById("spBar");
  if (t) t.textContent = "Урок · " + dc + " из 5";
  if (b) b.style.width = (dc / 5) * 100 + "%";
}

document.addEventListener("DOMContentLoaded", initPlayer);
