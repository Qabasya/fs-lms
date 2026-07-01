/* ══════════════════════════════════════════════════════════════
   Course Builder — data + interactivity (vanilla JS)
   ══════════════════════════════════════════════════════════════ */

// ── SVG icons ────────────────────────────────────────────────
const ICON = {
  lecture: '<svg viewBox="0 0 24 24" width="22" height="22"><path d="M6 3h9l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1zm8 1.5V8h3.5L14 4.5zM8 12h8v1.6H8V12zm0 3.4h8V17H8v-1.6zM8 8.6h4v1.6H8V8.6z"/></svg>',
  video:   '<svg viewBox="0 0 24 24" width="22" height="22"><path d="M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm6 3.2v7.6l6-3.8-6-3.8z"/></svg>',
  practice:'<svg viewBox="0 0 24 24" width="22" height="22"><path d="M9.4 16.6 4.8 12l4.6-4.6L8 6l-6 6 6 6 1.4-1.4zm5.2 0L19.2 12l-4.6-4.6L16 6l6 6-6 6-1.4-1.4z"/></svg>',
  quiz:    '<svg viewBox="0 0 24 24" width="22" height="22"><path d="M4 5h7v2H4V5zm0 6h7v2H4v-2zm0 6h7v2H4v-2zm14.3-9.3 1.4 1.4-5 5-3-3 1.4-1.4 1.6 1.6 3.6-3.6zm0 6 1.4 1.4-5 5-3-3 1.4-1.4 1.6 1.6 3.6-3.6z"/></svg>',
  file:    '<svg viewBox="0 0 24 24" width="22" height="22"><path d="M16.5 6.5 9.6 13.4a2 2 0 1 0 2.8 2.8l6.9-6.9a4 4 0 1 0-5.6-5.6L6.7 10.6a6 6 0 0 0 8.5 8.5L21 13.3l-1.4-1.4-5.8 5.8a4 4 0 0 1-5.7-5.7l7-7a2 2 0 0 1 2.8 2.8l-6.9 6.9-.7-.7 6.9-6.9-1.6-1.6z"/></svg>',
};

const STEP_TYPES = {
  lecture:  { name: 'Лекция',   desc: 'Текст, формулы, картинки', color: 'var(--t-lecture)' },
  video:    { name: 'Видео',    desc: 'YouTube, Vimeo, файл',     color: 'var(--t-video)' },
  practice: { name: 'Практика', desc: 'Задача с решением',         color: 'var(--t-practice)' },
  quiz:     { name: 'Тест',     desc: 'Вопрос с вариантами',       color: 'var(--t-quiz)' },
  file:     { name: 'Файл',     desc: 'Материалы для скачивания',  color: 'var(--t-file)' },
};

// ── Course data ──────────────────────────────────────────────
let course = {
  title: 'Основы языка Python [Python для ЕГЭ/ОГЭ]',
  modules: [
    {
      id: 'm1', title: 'Общая информация о курсе', collapsed: false,
      lessons: [
        { id: 'l1', title: 'О курсе', published: true, steps: [
          { id: 's1', type: 'lecture', title: 'Приветствие' },
          { id: 's2', type: 'video', title: 'Вводное видео' },
        ]},
        { id: 'l2', title: 'Контакт с нами', published: true, steps: [
          { id: 's3', type: 'lecture', title: 'Как с нами связаться' },
        ]},
      ]
    },
    {
      id: 'm2', title: 'Знакомство с языком Python', collapsed: false,
      lessons: [
        { id: 'l3', title: 'История создания Python', published: true, steps: [
          { id: 's4', type: 'lecture', title: 'История' },
          { id: 's5', type: 'video', title: 'Гвидо ван Россум' },
        ]},
        { id: 'l4', title: 'Введение в программирование', published: true, steps: [
          { id: 's6', type: 'lecture', title: 'Что такое программа' },
        ]},
        { id: 'l5', title: 'Классификация языков программирования', published: false, steps: [
          { id: 's7', type: 'lecture',  title: 'Текст' },
          { id: 's8', type: 'video',    title: 'Видеоразбор' },
          { id: 's9', type: 'practice', title: 'Практическое задание' },
          { id: 's10', type: 'quiz',    title: 'Проверь себя' },
          { id: 's11', type: 'file',    title: 'Материалы' },
        ]},
        { id: 'l6', title: 'Парадигмы программирования', published: false, steps: [
          { id: 's12', type: 'lecture', title: 'Парадигмы' },
        ]},
        { id: 'l7', title: 'Применение языка Python', published: false, steps: [
          { id: 's13', type: 'lecture', title: 'Сферы применения' },
        ]},
        { id: 'l8', title: 'Среды разработки', published: false, steps: [
          { id: 's14', type: 'video', title: 'Установка PyCharm' },
        ]},
      ]
    },
    {
      id: 'm3', title: 'Основы языка Python', collapsed: true,
      lessons: [
        { id: 'l9',  title: 'Оформление кода', published: false, steps: [{ id: 's15', type: 'lecture', title: 'PEP 8' }] },
        { id: 'l10', title: 'Переменные в Python', published: false, steps: [{ id: 's16', type: 'lecture', title: 'Переменные' }] },
        { id: 'l11', title: 'Типы данных', published: false, steps: [{ id: 's17', type: 'lecture', title: 'Типы' }, { id: 's18', type: 'quiz', title: 'Тест' }] },
        { id: 'l12', title: 'Преобразование типов', published: false, steps: [{ id: 's19', type: 'practice', title: 'Задача' }] },
      ]
    },
    {
      id: 'm4', title: 'Конструкция ветвления', collapsed: true,
      lessons: [
        { id: 'l13', title: 'Условный оператор if', published: false, steps: [{ id: 's20', type: 'lecture', title: 'if' }] },
        { id: 'l14', title: 'Оператор else', published: false, steps: [{ id: 's21', type: 'lecture', title: 'else' }] },
      ]
    },
  ]
};

// sample rich content for the showcase lesson's lecture step
const SAMPLE_LECTURE = `
  <h2>Классификация языков программирования</h2>
  <h3>Что такое язык программирования?</h3>
  <p>Язык программирования — это формальный язык, созданный для написания программ, которые могут быть выполнены компьютером. Языки программирования используются для определения последовательности операций, которые должны быть выполнены для решения задач и управления поведением компьютеров и других устройств.</p>
  <p>Основные компоненты языков программирования:</p>
  <ol>
    <li><strong>Синтаксис</strong>: правила, определяющие корректную структуру и последовательность символов в программе.</li>
    <li><strong>Семантика</strong>: значение конструкций языка и то, как они интерпретируются для выполнения операций.</li>
    <li><strong>Типы данных</strong>: определяют, какие данные могут быть обработаны и как они хранятся.</li>
    <li><strong>Операторы и выражения</strong>: операции, выполняемые над данными.</li>
    <li><strong>Контрольные структуры</strong>: инструкции, управляющие потоком выполнения программы.</li>
  </ol>
  <p>Далее разберём классификацию языков программирования по определённым критериям.</p>
`;

let activeLessonId = 'l5';
let activeStepId = 's7';
let stepContent = {}; // ephemeral per-step edits keyed by step id

// ── Helpers ──────────────────────────────────────────────────
function findLesson(id) {
  for (const m of course.modules) {
    const l = m.lessons.find(x => x.id === id);
    if (l) return { lesson: l, module: m };
  }
  return null;
}
function totalLessons() { return course.modules.reduce((n, m) => n + m.lessons.length, 0); }
let _idc = 1000;
const uid = (p) => p + (++_idc);

// ══════════════════════════════════════════════════════════════
//  RENDER: tree
// ══════════════════════════════════════════════════════════════
function renderTree() {
  const root = document.getElementById('tree');
  root.innerHTML = '';
  let lessonCounter = 0;

  course.modules.forEach((mod, mi) => {
    const modEl = document.createElement('div');
    modEl.className = 'module' + (mod.collapsed ? ' collapsed' : '');
    modEl.dataset.moduleId = mod.id;

    const head = document.createElement('div');
    head.className = 'module-head';
    head.innerHTML = `
      <span class="mod-caret"><svg width="12" height="12" viewBox="0 0 12 12"><path fill="currentColor" d="M3 4.5 6 8l3-3.5z"/></svg></span>
      <span class="mod-num">${mi + 1}</span>
      <span class="mod-title">${mod.title}</span>
      <span class="mod-grip" title="Перетащить модуль"><svg width="12" height="12" viewBox="0 0 12 12"><path fill="currentColor" d="M4 2.5h1v1H4zm3 0h1v1H7zM4 5.5h1v1H4zm3 0h1v1H7zM4 8.5h1v1H4zm3 0h1v1H7z"/></svg></span>
    `;
    head.querySelector('.mod-caret').addEventListener('click', (e) => {
      e.stopPropagation(); mod.collapsed = !mod.collapsed; renderTree();
    });
    head.addEventListener('click', () => { mod.collapsed = !mod.collapsed; renderTree(); });
    modEl.appendChild(head);

    const lessonsWrap = document.createElement('div');
    lessonsWrap.className = 'module-lessons';
    lessonsWrap.dataset.moduleId = mod.id;

    mod.lessons.forEach((les) => {
      lessonCounter++;
      const el = document.createElement('div');
      el.className = 'lesson' + (les.id === activeLessonId ? ' active' : '');
      el.dataset.lessonId = les.id;
      el.draggable = true;
      el.innerHTML = `
        <span class="les-grip"><svg width="12" height="12" viewBox="0 0 12 12"><path fill="currentColor" d="M4 2.5h1v1H4zm3 0h1v1H7zM4 5.5h1v1H4zm3 0h1v1H7zM4 8.5h1v1H4zm3 0h1v1H7z"/></svg></span>
        <span class="les-num">${mi + 1}.${mod.lessons.indexOf(les) + 1}</span>
        <span class="les-title">${les.title}</span>
        <span class="les-steps">${les.steps.length}</span>
      `;
      el.addEventListener('click', () => selectLesson(les.id));
      attachLessonDrag(el, les, mod);
      lessonsWrap.appendChild(el);
    });

    modEl.appendChild(lessonsWrap);
    root.appendChild(modEl);
  });

  document.getElementById('treeCount').textContent =
    `${course.modules.length} модуля · ${totalLessons()} уроков`;
}

// ── Drag & drop for lessons ──────────────────────────────────
let dragLessonId = null;
function attachLessonDrag(el, les, mod) {
  el.addEventListener('dragstart', (e) => {
    dragLessonId = les.id;
    el.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', les.id);
  });
  el.addEventListener('dragend', () => {
    dragLessonId = null;
    el.classList.remove('dragging');
    document.querySelectorAll('.drop-before,.drop-after').forEach(n => n.classList.remove('drop-before','drop-after'));
  });
  el.addEventListener('dragover', (e) => {
    e.preventDefault();
    if (dragLessonId === les.id) return;
    const r = el.getBoundingClientRect();
    const after = (e.clientY - r.top) > r.height / 2;
    el.classList.toggle('drop-after', after);
    el.classList.toggle('drop-before', !after);
  });
  el.addEventListener('dragleave', () => el.classList.remove('drop-before','drop-after'));
  el.addEventListener('drop', (e) => {
    e.preventDefault();
    const r = el.getBoundingClientRect();
    const after = (e.clientY - r.top) > r.height / 2;
    el.classList.remove('drop-before','drop-after');
    moveLesson(dragLessonId, mod.id, les.id, after);
  });
}

function moveLesson(lessonId, targetModuleId, targetLessonId, after) {
  if (!lessonId) return;
  let moved = null;
  for (const m of course.modules) {
    const i = m.lessons.findIndex(l => l.id === lessonId);
    if (i > -1) { moved = m.lessons.splice(i, 1)[0]; break; }
  }
  if (!moved) return;
  const tm = course.modules.find(m => m.id === targetModuleId);
  let ti = tm.lessons.findIndex(l => l.id === targetLessonId);
  if (ti < 0) ti = tm.lessons.length - 1;
  tm.lessons.splice(after ? ti + 1 : ti, 0, moved);
  renderTree();
  toast('Урок перемещён');
}

// ══════════════════════════════════════════════════════════════
//  SELECT lesson / step
// ══════════════════════════════════════════════════════════════
function selectLesson(id) {
  activeLessonId = id;
  const found = findLesson(id);
  activeStepId = found.lesson.steps[0]?.id || null;
  renderTree();
  renderEditor();
}
function selectStep(id) { activeStepId = id; renderEditor(); }

// ══════════════════════════════════════════════════════════════
//  RENDER: editor
// ══════════════════════════════════════════════════════════════
function renderEditor() {
  const pane = document.getElementById('editorPane');
  const found = findLesson(activeLessonId);
  if (!found) { pane.innerHTML = emptyState(); return; }
  const { lesson, module } = found;
  const mi = course.modules.indexOf(module) + 1;
  const li = module.lessons.indexOf(lesson) + 1;
  const step = lesson.steps.find(s => s.id === activeStepId) || lesson.steps[0];

  pane.innerHTML = `
    <div class="editor-top">
      <div class="editor-breadcrumb">
        <span>${course.title}</span>
        <span>›</span>
        <span>Модуль ${mi}: ${module.title}</span>
        <span>›</span>
        <b>Урок ${mi}.${li}</b>
      </div>
      <div class="lesson-title-row">
        <input class="lesson-title-input" id="lessonTitle" value="${esc(lesson.title)}" placeholder="Название урока">
        <span class="lesson-flag ${lesson.published ? 'published' : ''}">
          ${lesson.published
            ? '<svg width="11" height="11" viewBox="0 0 12 12"><path fill="currentColor" d="M5 8.2 2.8 6l-.9.9L5 10l5-5-.9-.9z"/></svg> Опубликован'
            : '<svg width="11" height="11" viewBox="0 0 12 12"><circle cx="6" cy="6" r="3" fill="currentColor"/></svg> Черновик'}
        </span>
      </div>
      <div class="steps-label">Шаги урока</div>
      <div class="steps-row" id="stepsRow"></div>
    </div>
    <div class="editor-body" id="editorBody"></div>
    <div class="editor-footer">
      <span class="ef-status"><span class="saved-dot"></span> Все изменения сохранены</span>
      <span class="ef-spacer"></span>
      <button class="button" onclick="toast('Предпросмотр урока')">
        <svg width="15" height="15" viewBox="0 0 20 20"><path d="M10 4C5 4 1.7 7.1.5 10 1.7 12.9 5 16 10 16s8.3-3.1 9.5-6C18.3 7.1 15 4 10 4zm0 10a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm0-6a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/></svg>
        Просмотр
      </button>
      <button class="button button-green" onclick="toast('Урок сохранён')">
        <svg width="15" height="15" viewBox="0 0 20 20"><path d="M14 3H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V6l-3-3zM7 4h5v3H7V4zm5 11H8v-4h4v4z"/></svg>
        Сохранить
      </button>
    </div>
  `;

  // title binding
  pane.querySelector('#lessonTitle').addEventListener('input', (e) => {
    lesson.title = e.target.value;
    renderTree();
  });

  renderStepsRow(lesson, step);
  renderStepBody(lesson, step);
}

function renderStepsRow(lesson, activeStep) {
  const row = document.getElementById('stepsRow');
  row.innerHTML = '';
  lesson.steps.forEach((s, i) => {
    const chip = document.createElement('div');
    chip.className = 'step-chip' + (s.id === activeStep?.id ? ' active' : '');
    chip.dataset.type = s.type;
    chip.dataset.stepId = s.id;
    chip.draggable = true;
    chip.innerHTML = `
      <div class="step-chip-box"><span class="sc-num">${i + 1}</span>${ICON[s.type]}</div>
      <span class="sc-type">${STEP_TYPES[s.type].name}</span>
    `;
    chip.addEventListener('click', () => selectStep(s.id));
    attachStepDrag(chip, lesson, s);
    row.appendChild(chip);
  });

  // add button
  const add = document.createElement('div');
  add.className = 'step-chip step-add';
  add.innerHTML = `
    <div class="step-chip-box"><svg width="22" height="22" viewBox="0 0 24 24"><path fill="currentColor" d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6z"/></svg></div>
    <span class="sc-type">Добавить</span>
  `;
  add.addEventListener('click', (e) => openStepPopover(e, lesson));
  row.appendChild(add);
}

// drag steps
let dragStepId = null;
function attachStepDrag(chip, lesson, step) {
  chip.addEventListener('dragstart', (e) => {
    dragStepId = step.id; chip.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
  });
  chip.addEventListener('dragend', () => { dragStepId = null; chip.classList.remove('dragging'); });
  chip.addEventListener('dragover', (e) => { e.preventDefault(); });
  chip.addEventListener('drop', (e) => {
    e.preventDefault();
    if (!dragStepId || dragStepId === step.id) return;
    const from = lesson.steps.findIndex(s => s.id === dragStepId);
    const to = lesson.steps.findIndex(s => s.id === step.id);
    const [m] = lesson.steps.splice(from, 1);
    lesson.steps.splice(to, 0, m);
    renderStepsRow(lesson, lesson.steps.find(s => s.id === activeStepId));
    toast('Шаг перемещён');
  });
}

// ══════════════════════════════════════════════════════════════
//  RENDER: step body by type
// ══════════════════════════════════════════════════════════════
function renderStepBody(lesson, step) {
  const body = document.getElementById('editorBody');
  if (!step) { body.innerHTML = `<div style="color:var(--wp-muted-lt);padding:40px;text-align:center">В этом уроке пока нет шагов. Нажмите «Добавить».</div>`; return; }
  const t = STEP_TYPES[step.type];
  const stepIndex = lesson.steps.indexOf(step) + 1;

  const head = `
    <div class="step-head">
      <span class="sh-badge" style="background:${t.color}">${ICON[step.type].replace('width="22" height="22"','width="14" height="14"')} Шаг ${stepIndex}: ${t.name}</span>
      <input class="field-input" style="max-width:280px" value="${esc(step.title)}" placeholder="Название шага"
        oninput="updateStepTitle('${step.id}', this.value)">
      <div class="sh-controls">
        <button class="icon-btn" title="Дублировать" onclick="dupStep('${step.id}')"><svg width="15" height="15" viewBox="0 0 20 20"><path d="M7 3h9a1 1 0 0 1 1 1v9h-2V5H7V3zM4 6h9a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1zm1 2v7h7V8H5z"/></svg></button>
        <button class="icon-btn danger" title="Удалить шаг" onclick="delStep('${step.id}')"><svg width="15" height="15" viewBox="0 0 20 20"><path d="M7 2h6l1 2h3v2H3V4h3l1-2zM5 7h10l-1 11H6L5 7z"/></svg></button>
      </div>
    </div>
  `;

  let content = '';
  switch (step.type) {
    case 'lecture':  content = lectureEditor(step); break;
    case 'video':    content = videoEditor(step); break;
    case 'practice': content = practiceEditor(step); break;
    case 'quiz':     content = quizEditor(step); break;
    case 'file':     content = fileEditor(step); break;
  }
  body.innerHTML = head + content;
}

// ── Lecture (rich text) ──────────────────────────────────────
function lectureEditor(step) {
  const html = stepContent[step.id] ?? (step.id === 's7' ? SAMPLE_LECTURE : '<h2>'+esc(step.title)+'</h2><p>Начните писать содержание лекции…</p>');
  const rb = (svg, t) => `<span class="rte-btn" title="${t}">${svg}</span>`;
  return `
    <div class="rte">
      <div class="rte-toolbar">
        <select class="rte-select"><option>Абзац</option><option>Заголовок 1</option><option>Заголовок 2</option><option>Цитата</option></select>
        <span class="rte-sep"></span>
        ${rb('<svg width="14" height="14" viewBox="0 0 16 16"><path fill="currentColor" d="M5 3h4a2.5 2.5 0 0 1 1.6 4.4A2.7 2.7 0 0 1 9.3 13H5V3zm2 1.7v2.1h2a1 1 0 0 0 0-2.1H7zm0 3.8v2.5h2.3a1.25 1.25 0 0 0 0-2.5H7z"/></svg>','Жирный')}
        ${rb('<svg width="14" height="14" viewBox="0 0 16 16"><path fill="currentColor" d="M6 3h6v1.6h-2L8.4 11.4h1.8V13H4v-1.6h2L7.6 4.6H6V3z"/></svg>','Курсив')}
        ${rb('<svg width="14" height="14" viewBox="0 0 16 16"><path fill="currentColor" d="M4 3h2v5a2 2 0 0 0 4 0V3h2v5a4 4 0 0 1-8 0V3zm-1 11h10v1.5H3V14z"/></svg>','Подчёркнутый')}
        <span class="rte-sep"></span>
        ${rb('<svg width="14" height="14" viewBox="0 0 16 16"><path fill="currentColor" d="M2 3h2v2H2V3zm4 .5h8v1.5H6V3.5zM2 7h2v2H2V7zm4 .5h8v1.5H6V7.5zM2 11h2v2H2v-2zm4 .5h8V13H6v-1.5z"/></svg>','Маркированный список')}
        ${rb('<svg width="14" height="14" viewBox="0 0 16 16"><path fill="currentColor" d="M2 3h1.5v1H3v.5h1V5H2V3.5zm0 4h2v.7l-1 1.3h1V10H2v-.7l1-1.3H2V7zm0 4h1.5v.5H3v.5h1V13H2v-.5h.5V12H2v-1zm4-7.5h8V5H6V3.5zm0 4h8V9H6V7.5zm0 4h8V13H6v-1.5z"/></svg>','Нумерованный список')}
        ${rb('<svg width="14" height="14" viewBox="0 0 16 16"><path fill="currentColor" d="M5 4h8v1.5H5V4zm0 3.3h8v1.5H5V7.3zm0 3.2h5V12H5v-1.5zM2 4h1.5v8H2V4z"/></svg>','Цитата')}
        <span class="rte-sep"></span>
        ${rb('<svg width="14" height="14" viewBox="0 0 16 16"><path fill="currentColor" d="M6.5 9.5a2.5 2.5 0 0 0 3.5 0l2-2a2.5 2.5 0 0 0-3.5-3.5l-1 1 1 1 1-1a1 1 0 1 1 1.4 1.4l-2 2a1 1 0 0 1-1.4 0l-1 1zm3-3a2.5 2.5 0 0 0-3.5 0l-2 2a2.5 2.5 0 0 0 3.5 3.5l1-1-1-1-1 1a1 1 0 1 1-1.4-1.4l2-2a1 1 0 0 1 1.4 0l1-1z"/></svg>','Ссылка')}
        ${rb('<svg width="14" height="14" viewBox="0 0 16 16"><path fill="currentColor" d="M2 3h12v10H2V3zm1.5 1.5v5.8l3-2.3 2 1.5 2.5-3 1.5 1.8V4.5h-9zM5 6a1 1 0 1 0 0-.01z"/></svg>','Изображение')}
        ${rb('<svg width="14" height="14" viewBox="0 0 16 16"><path fill="currentColor" d="M3 3h10v1.5l-4 3.5 4 3.5V13H3v-1.5l4-3.5-4-3.5V3zm2.2 1.5L8 7l2.8-2.5H5.2z"/></svg>','Формула (Σ)')}
        ${rb('<svg width="14" height="14" viewBox="0 0 16 16"><path fill="currentColor" d="M2 3h12v10H2V3zm1.5 1.5v2h3v-2h-3zm4.5 0v2h4.5v-2H8zM3.5 8v3.5h3V8h-3zm4.5 0v3.5h4.5V8H8z"/></svg>','Таблица')}
        <span class="rte-sep"></span>
        ${rb('<svg width="14" height="14" viewBox="0 0 16 16"><path fill="currentColor" d="m6 4-4 4 4 4 1-1-3-3 3-3-1-1zm4 0-1 1 3 3-3 3 1 1 4-4-4-4z"/></svg>','Исходный код')}
      </div>
      <div class="rte-area" contenteditable="true" oninput="stepContent['${step.id}']=this.innerHTML">${html}</div>
    </div>
  `;
}

// ── Video ────────────────────────────────────────────────────
function videoEditor(step) {
  return `
    <div class="video-embed">
      <div class="ve-icon"><svg width="30" height="30" viewBox="0 0 24 24"><path d="M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm6 3.2v7.6l6-3.8-6-3.8z"/></svg></div>
      <p><strong>Вставьте видео урока</strong></p>
      <p class="ve-hint">Поддерживаются YouTube, Vimeo, Rutube или загрузка файла (mp4)</p>
    </div>
    <div class="field-row">
      <label>Ссылка на видео</label>
      <input class="field-input" placeholder="https://youtube.com/watch?v=..." value="">
    </div>
    <div class="field-row">
      <label>Описание под видео</label>
      <textarea class="field-input" placeholder="Краткое описание содержания видео…"></textarea>
    </div>
    <button class="button"><svg width="14" height="14" viewBox="0 0 20 20"><path d="M10 2v8h8v2h-8v6H8v-6H0v-2h8V2z"/></svg> Загрузить файл</button>
  `;
}

// ── Practice ─────────────────────────────────────────────────
function practiceEditor(step) {
  return `
    <div class="section-block">
      <div class="sb-label">Условие задачи</div>
      <textarea class="field-input" style="min-height:120px" placeholder="Опишите условие практического задания…">Напишите программу, которая считывает целое число n и выводит сумму всех чисел от 1 до n включительно.</textarea>
    </div>
    <div class="section-block">
      <div class="sb-label">Эталонное решение</div>
      <textarea class="field-input" style="min-height:120px;font-family:'IBM Plex Mono',monospace;font-size:13px" placeholder="# код решения">n = int(input())
print(sum(range(1, n + 1)))</textarea>
    </div>
    <div class="section-block">
      <div class="sb-label">Проверка</div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <button class="button"><svg width="14" height="14" viewBox="0 0 20 20"><path d="M10 2v8h8v2h-8v6H8v-6H0v-2h8V2z"/></svg> Добавить тест</button>
        <button class="button">Автопроверка по выводу</button>
      </div>
    </div>
  `;
}

// ── Quiz ─────────────────────────────────────────────────────
function quizEditor(step) {
  const opt = (text, correct) => `
    <div class="quiz-option ${correct ? 'correct' : ''}">
      <span class="qo-grip"><svg width="12" height="12" viewBox="0 0 12 12"><path fill="currentColor" d="M4 2.5h1v1H4zm3 0h1v1H7zM4 5.5h1v1H4zm3 0h1v1H7zM4 8.5h1v1H4zm3 0h1v1H7z"/></svg></span>
      <span class="qo-radio"></span>
      <span class="qo-text">${text}</span>
      ${correct ? '<span class="qo-tag">верный</span>' : ''}
    </div>`;
  return `
    <div class="section-block">
      <div class="sb-label">Вопрос</div>
      <textarea class="field-input" placeholder="Текст вопроса…">Какой тип данных в Python используется для хранения целых чисел?</textarea>
    </div>
    <div class="section-block">
      <div class="sb-label">Варианты ответа</div>
      ${opt('int', true)}
      ${opt('float', false)}
      ${opt('str', false)}
      ${opt('bool', false)}
      <button class="button button-sm" style="margin-top:4px"><svg width="13" height="13" viewBox="0 0 20 20"><path d="M10 2v8h8v2h-8v6H8v-6H0v-2h8V2z"/></svg> Добавить вариант</button>
    </div>
  `;
}

// ── File ─────────────────────────────────────────────────────
function fileEditor(step) {
  const file = (name, size, kind, cls) => `
    <div class="file-item">
      <div class="fi-ico ${cls}">${kind}</div>
      <div class="fi-main"><div class="fi-name">${name}</div><div class="fi-size">${size}</div></div>
      <button class="icon-btn danger" title="Удалить"><svg width="14" height="14" viewBox="0 0 20 20"><path d="M7 2h6l1 2h3v2H3V4h3l1-2zM5 7h10l-1 11H6L5 7z"/></svg></button>
    </div>`;
  return `
    <div class="file-drop">
      <svg width="34" height="34" viewBox="0 0 24 24" fill="#a7aaad"><path d="M12 3 7 8h3v6h4V8h3l-5-5zM5 18h14v2H5z"/></svg>
      <p style="margin-top:8px;font-size:13px">Перетащите файлы сюда или</p>
      <button class="button">Выбрать файлы</button>
    </div>
    <div class="file-list">
      ${file('Конспект урока.pdf', '1,2 МБ', 'PDF', 'pdf')}
      ${file('Примеры кода.py', '4 КБ', 'PY', '')}
      ${file('Презентация.docx', '820 КБ', 'DOC', 'doc')}
    </div>
  `;
}

// ══════════════════════════════════════════════════════════════
//  Step actions
// ══════════════════════════════════════════════════════════════
function updateStepTitle(id, val) {
  const f = findLesson(activeLessonId); if (!f) return;
  const s = f.lesson.steps.find(x => x.id === id); if (s) { s.title = val; renderStepsRow(f.lesson, s); }
}
function dupStep(id) {
  const f = findLesson(activeLessonId); const i = f.lesson.steps.findIndex(s => s.id === id);
  const orig = f.lesson.steps[i];
  const copy = { id: uid('s'), type: orig.type, title: orig.title + ' (копия)' };
  f.lesson.steps.splice(i + 1, 0, copy);
  activeStepId = copy.id; renderTree(); renderEditor(); toast('Шаг дублирован');
}
function delStep(id) {
  const f = findLesson(activeLessonId);
  if (f.lesson.steps.length <= 1) { toast('Нельзя удалить единственный шаг'); return; }
  const i = f.lesson.steps.findIndex(s => s.id === id);
  f.lesson.steps.splice(i, 1);
  activeStepId = f.lesson.steps[Math.max(0, i - 1)].id;
  renderTree(); renderEditor(); toast('Шаг удалён');
}

// ── add-step popover ─────────────────────────────────────────
let popoverLesson = null;
function openStepPopover(e, lesson) {
  e.stopPropagation();
  popoverLesson = lesson;
  const pop = document.getElementById('stepPopover');
  const bd = document.getElementById('popoverBackdrop');
  const r = e.currentTarget.getBoundingClientRect();
  pop.style.top = (r.bottom + 6) + 'px';
  pop.style.left = Math.min(r.left, window.innerWidth - 240) + 'px';
  pop.classList.add('open'); bd.classList.add('open');
}
function closePopover() {
  document.getElementById('stepPopover').classList.remove('open');
  document.getElementById('popoverBackdrop').classList.remove('open');
}
function addStep(type) {
  if (!popoverLesson) return;
  const s = { id: uid('s'), type, title: STEP_TYPES[type].name };
  popoverLesson.steps.push(s);
  activeStepId = s.id;
  closePopover(); renderTree(); renderEditor(); toast(STEP_TYPES[type].name + ' добавлен');
}

// ── add lesson / module ──────────────────────────────────────
function addLesson() {
  const f = findLesson(activeLessonId);
  const mod = f ? f.module : course.modules[0];
  const les = { id: uid('l'), title: 'Новый урок', published: false, steps: [{ id: uid('s'), type: 'lecture', title: 'Лекция' }] };
  mod.lessons.push(les);
  mod.collapsed = false;
  selectLesson(les.id);
  toast('Урок добавлен');
}
function addModule() {
  const m = { id: uid('m'), title: 'Новый модуль', collapsed: false, lessons: [
    { id: uid('l'), title: 'Новый урок', published: false, steps: [{ id: uid('s'), type: 'lecture', title: 'Лекция' }] }
  ]};
  course.modules.push(m);
  selectLesson(m.lessons[0].id);
  toast('Модуль добавлен');
}

// ── utils ────────────────────────────────────────────────────
function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function emptyState() {
  return `<div class="editor-empty"><svg width="56" height="56" viewBox="0 0 24 24"><path d="M5 3h11l3 3v15H5V3zm10 1.5V7h2.5L15 4.5z"/></svg><div>Выберите урок слева, чтобы редактировать его шаги</div></div>`;
}
let toastTimer;
function toast(msg) {
  const t = document.getElementById('toast');
  t.querySelector('span').textContent = msg;
  t.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), 1800);
}

// build step popover options
function buildPopover() {
  const pop = document.getElementById('stepPopover');
  pop.innerHTML = '<div class="sp-title">Добавить шаг</div>' +
    Object.entries(STEP_TYPES).map(([k, v]) => `
      <div class="sp-option" onclick="addStep('${k}')">
        <span class="spo-ico" style="background:${v.color}">${ICON[k].replace('width="22" height="22"','width="16" height="16"')}</span>
        <div><div class="spo-name">${v.name}</div><div class="spo-desc">${v.desc}</div></div>
      </div>`).join('');
}

// ── init ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  buildPopover();
  document.getElementById('popoverBackdrop').addEventListener('click', closePopover);
  document.getElementById('addLessonBtn').addEventListener('click', addLesson);
  document.getElementById('addModuleBtn').addEventListener('click', addModule);
  renderTree();
  renderEditor();
});
