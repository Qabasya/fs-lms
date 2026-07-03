// ══════════════════════════════════════════════════════════════════════
// cp-mount.js — сборка холста: интро, секции, фреймы, подписи
// ══════════════════════════════════════════════════════════════════════

(function () {
  const FW = 1440, FH = 900, GX = 80, PITCH = FW + GX;
  const rowY = { intro: 0, a: 320, b: 1560, c: 2800 };
  const LX = 0;

  function stage(cls, html) { return { cls: cls, html: html }; }

  const SECTIONS = [
    {
      id: "A", y: rowY.a,
      name: "Вариант A — Лента шагов",
      desc: "Stepik-паттерн: горизонтальная лента шагов с типами и статусами над контентом, одна колонка чтения ~900px, навигация «Назад / Далее» под карточкой. В работе — закреплённая панель с прогрессом, таймером и кнопкой завершения.",
      frames: [
        { t: "Шаг · Теория",                st: stage("a-stage", renderA_theory()) },
        { t: "Шаг · Видео",                 st: stage("a-stage", renderA_video()) },
        { t: "Задача · мгновенная проверка", st: stage("a-stage", renderA_task()) },
        { t: "Работа · в процессе",          st: stage("a-stage", renderA_work()) },
        { t: "Работа · результаты",          st: stage("a-stage", renderA_results()) },
      ],
    },
    {
      id: "B", y: rowY.b,
      name: "Вариант B — Дерево курса",
      desc: "LMS-паттерн: слева всегда структура «модули → уроки → шаги» с прогрессом. Контент собран из карточек «Условие / Ваш ответ». В режиме работы дерево сворачивается в узкую полосу, справа — карта задач, таймер и кнопка «Завершить работу».",
      frames: [
        { t: "Шаг · Теория",                 st: stage("b-stage", renderB_theory()) },
        { t: "Шаг · Видео",                  st: stage("b-stage", renderB_video()) },
        { t: "Задача · мгновенная проверка",  st: stage("b-stage", renderB_task()) },
        { t: "Работа · в процессе",           st: stage("b-stage work", renderB_work(false)) },
        { t: "Работа · подтверждение",        st: stage("b-stage work", renderB_work(true)) },
        { t: "Работа · результаты",           st: stage("b-stage work", renderB_results()) },
      ],
    },
    {
      id: "C", y: rowY.c,
      name: "Вариант C — Фокус-режим",
      desc: "Режим погружения: тонкая полоса прогресса в шапке, крупная типографика на белом листе, плавающий док навигации. Работа — по одной задаче на экран с точками-статусами; итоги — экран-разбор со счётом.",
      frames: [
        { t: "Шаг · Теория",                 st: stage("c-stage", renderC_theory()) },
        { t: "Шаг · Видео",                  st: stage("c-stage", renderC_video()) },
        { t: "Задача · мгновенная проверка",  st: stage("c-stage", renderC_task()) },
        { t: "Работа · задача 2 из 4",        st: stage("c-stage", renderC_work()) },
        { t: "Работа · результаты",           st: stage("c-stage", renderC_results()) },
      ],
    },
  ];

  const body = document.body;

  function add(cls, x, y, html, w) {
    const el = document.createElement("div");
    el.className = "cv-item " + cls;
    el.style.left = x + "px";
    el.style.top = y + "px";
    if (w) el.style.width = w + "px";
    el.innerHTML = html;
    body.appendChild(el);
    return el;
  }

  // интро
  add("cv-intro", LX, rowY.intro,
    '<h1>Плеер курса для ученика — 3 варианта</h1>' +
    '<p>Общий стиль кабинета «Шаг в будущее»: Golos Text, индиго-акцент, мягкие карточки. ' +
    'Шаги четырёх типов: теория, видео, задача с мгновенной проверкой и «Работа» — несколько задач, ' +
    'которые проверяются только после завершения. Каждый вариант показан в 5–6 состояниях.</p>' +
    '<div class="cv-legend">' +
      '<div class="cv-leg"><b><i>A</i>Лента шагов</b><p>Горизонтальная полоса шагов как в Stepik, контент одной колонкой, навигация под карточкой.</p></div>' +
      '<div class="cv-leg"><b><i>B</i>Дерево курса</b><p>Структура модулей всегда слева; в работе — карта задач и таймер в правой колонке.</p></div>' +
      '<div class="cv-leg"><b><i>C</i>Фокус-режим</b><p>Минимум интерфейса, крупный текст, плавающий док. Работа — по одной задаче на экран.</p></div>' +
    '</div>');

  SECTIONS.forEach(function (sec) {
    add("cv-sec", LX, sec.y - 128, "<h2>" + sec.name + "</h2><p>" + sec.desc + "</p>");
    sec.frames.forEach(function (f, i) {
      const x = LX + i * PITCH;
      add("cv-flabel", x, sec.y - 34, "<i>" + sec.id + (i + 1) + "</i>" + f.t);
      const frame = document.createElement("div");
      frame.className = "cpf";
      frame.style.left = x + "px";
      frame.style.top = sec.y + "px";
      frame.setAttribute("data-screen-label", sec.id + (i + 1) + " · " + sec.name.split(" — ")[1] + " · " + f.t);
      frame.innerHTML = frameHtml({
        crumb: CRUMB_LESSON,
        title: COURSE.lesson,
        stageClass: f.st.cls,
        stageHtml: f.st.html,
      });
      body.appendChild(frame);
    });
  });
})();
