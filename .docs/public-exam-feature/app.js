/*
  Интерактивный макет «Экзамены».
  Разметка и классы — настоящие (из кабинета плагина и темы сайта, см. real.js и index.html).
  Всё, чего нет в продукте, помечено в style.css как «НОВОЕ».
*/
'use strict';

const R = window.REAL;
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

/* ── Иконки (в продукте — common/icons.js и enum Icon) ──────────────── */
const svg = (d, w = 18) => `<svg width="${w}" height="${w}" viewBox="0 0 20 20" fill="none" aria-hidden="true">${d}</svg>`;
const P = (d) => `<path d="${d}" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>`;
const ICON = {
  calendar: svg('<rect x="3" y="4" width="14" height="13" rx="2" stroke="currentColor" stroke-width="1.6"/>' + P('M3 8h14M7 2.5v3M13 2.5v3')),
  check: svg(P('M4 10.5l4 4 8-9')),
  alert: svg(P('M10 6v5M10 14h.01') + '<circle cx="10" cy="10" r="7.2" stroke="currentColor" stroke-width="1.6"/>'),
  exam: svg('<rect x="4" y="3" width="12" height="14" rx="2" stroke="currentColor" stroke-width="1.6"/>' + P('M7.5 8.5l1.5 1.5 3-3M7.5 13h5')),
  bell: svg(P('M10 3a4 4 0 0 0-4 4c0 4-1.5 5-1.5 5h11S14 11 14 7a4 4 0 0 0-4-4zM8.5 15a1.5 1.5 0 0 0 3 0'), 32),
  rub: svg(P('M7 16V4h4a3 3 0 0 1 0 6H7M5 13h7')),
  chevron: svg(P('M8 4.5L13.5 10 8 15.5'), 18),
};

/* ── Данные макета ──────────────────────────────────────────────────── */
const SLOTS = [
  { d: 'пн', full: '12 октября', dow: 'понедельник', t: '10:00', room: 'каб. 204', left: 6 },
  { d: 'пн', full: '12 октября', dow: 'понедельник', t: '15:00', room: 'каб. 205', left: 2 },
  { d: 'чт', full: '15 октября', dow: 'четверг', t: '10:00', room: 'каб. 204', left: 0 },
  { d: 'ср', full: '21 октября', dow: 'среда', t: '15:00', room: 'каб. 204', left: 8 },
  { d: 'пт', full: '23 октября', dow: 'пятница', t: '15:00', room: 'каб. 204', left: 5 },
];
const slotWord = (n) => (n === 0 ? 'мест нет' : `осталось ${n} ${n === 1 ? 'место' : n < 5 ? 'места' : 'мест'}`);

/* оплата / запись / допуск / попытка — отдельные состояния (SPEC §7) */
const PEOPLE = [
  { n: 'Орлова Алина', ini: 'ОА', src: 'Школа № 1', pay: ['оплачено', 'now'], reg: ['подтверждена', 'now'], adm: ['ссылка передана 09:48', 'now'], att: ['идёт, осталось 2:11', 'soon'] },
  { n: 'Волков Артём', ini: 'ВА', src: 'Школа № 1', pay: ['оплачено купоном', 'now'], reg: ['подтверждена', 'now'], adm: ['не выдан', 'done'], att: ['не начата', 'done'] },
  { n: 'Морозова Анна', ini: 'МА', src: 'Гимназия № 4', pay: ['ожидаем, бронь до 14:20', 'soon'], reg: ['бронь', 'soon'], adm: ['не выдан', 'done'], att: ['не начата', 'done'] },
  { n: 'Козлов Илья', ini: 'КИ', src: 'Гимназия № 4', pay: ['оплачено, требуется помощь', 'warn'], reg: ['не подтверждена', 'warn'], adm: ['не выдан', 'done'], att: ['не начата', 'done'] },
  { n: 'Мария Алексеева', ini: 'МА', src: 'Ученик центра', pay: ['—', 'done'], reg: ['подтверждена', 'now'], adm: ['открыт', 'now'], att: ['сдана', 'now'] },
];
const pill = (text, kind = 'done') => `<span class="prof-state-pill ${kind === 'warn' ? 'mk-warn' : kind === 'err' ? 'mk-err' : 'prof-state-' + kind}">${esc(text)}</span>`;
const verdict = (text, kind) => `<span class="sum-verdict sv-${kind}">${esc(text)}</span>`;
const btn = (text, action, cls = '', extra = '') => `<button type="button" class="prof-btn ${cls}" data-action="${action}" ${extra}>${text}</button>`;

/* ── Состояние ──────────────────────────────────────────────────────── */
const ROUTES = [
  { id: 'student', label: 'Ученик · Мои экзамены', frame: 'cab', role: 'student', demo: [['booking', 'Выбор даты'], ['booked', 'Запись подтверждена'], ['waiting', 'Сдано, ждёт утверждения'], ['result', 'Результат утверждён'], ['empty', 'Нет проведений']] },
  { id: 'parent', label: 'Родитель · Мои экзамены', frame: 'cab', role: 'parent', demo: [['booked', 'Запись подтверждена'], ['waiting', 'Сдано, ждёт утверждения'], ['result', 'Результат утверждён']] },
  { id: 'calendar', label: 'Преподаватель · Назначить экзамен', frame: 'cab', role: 'teacher', demo: [['empty', 'Нет проведений'], ['filled', 'Есть проведение']] },
  { id: 'session', label: 'Преподаватель · Проведение экзамена', frame: 'cab', role: 'teacher', demo: [['normal', 'Идёт сеанс'], ['approve', 'Выбор работ для утверждения']] },
  { id: 'guests', label: 'Преподаватель · Результаты', frame: 'cab', role: 'teacher' },
  { id: 'teacher-review', label: 'Преподаватель · Проверка работы', frame: 'cab', role: 'teacher' },
  { id: 'stats', label: 'Преподаватель · Статистика', frame: 'cab', role: 'teacher' },
  { id: 'payments', label: 'Администратор · Оплаты гостей', frame: 'cab', role: 'office', demo: [['help', 'Требуют помощи'], ['all', 'Все оплаты']] },
  { id: 'notifications', label: 'Тексты уведомлений', frame: 'cab', role: 'office' },
  { id: 'signup', label: 'Сайт · Форма записи гостя', frame: 'site', demo: [['form', 'Форма'], ['hold', 'Ожидаем оплату'], ['pending', 'Подтверждение не получено'], ['paid', 'Оплата получена']] },
  { id: 'guest-entry', label: 'Сайт · Вход гостя', frame: 'site' },
  { id: 'result', label: 'Сайт · Результат гостя', frame: 'site' },
  { id: 'report', label: 'Сайт · Отчёт школе', frame: 'site' },
];
const S = {
  route: (location.hash.slice(1) || 'student'),
  demo: { student: 'booking', parent: 'booked', calendar: 'empty', session: 'normal', signup: 'form', payments: 'help' },
  slot: 0,
  notifRole: 'student',
  hold: 19 * 60 + 42,
  reportPerson: null,
};
const routeOf = (id) => ROUTES.find((r) => r.id === id) || ROUTES[0];

/* ── Каркасы ────────────────────────────────────────────────────────── */
const navItem = (go, label, icon, active) =>
  `<div class="prof-nav-item${active ? ' active' : ''}" data-go="${go}"><span class="ni-ico">${icon}</span>${label}</div>`;

function sideFor(role, activeGo) {
  let s = R.side[role].replace(/prof-nav-item active/g, 'prof-nav-item');
  if (role === 'student' || role === 'parent') {
    // «Мои экзамены» — рядом с «Мои курсы» в основном меню
    s = s.replace(/(<div class="prof-nav-item[^"]*" data-go="learner-lessons">[\s\S]*?<\/div>)/, `$1${navItem('ex-student', 'Мои экзамены', ICON.exam, activeGo === 'ex-student')}`);
  } else {
    // для преподавателя и администратора — сворачиваемый раздел, как «Мои группы» / «Мои курсы»
    const items = [
      ['ex-calendar', 'Назначить экзамен', ICON.calendar],
      ['ex-session', 'Проведение экзамена', ICON.exam],
      ['ex-results', 'Результаты', ICON.check],
      ['ex-stats', 'Статистика', ICON.exam],
    ];
    if (role === 'office') items.push(['ex-payments', 'Оплаты гостей', ICON.rub]);
    const section = `<div class="prof-nav-label prof-nav-label--toggle" role="button" tabindex="0" aria-expanded="true">Мои экзамены<span class="pnl-caret"><svg width="10" height="10" viewBox="0 0 12 12"><path d="M3 4.5 6 8l3-3.5z" fill="currentColor"/></svg></span></div>`
      + `<div class="prof-fold is-open"><div class="prof-fold-inner">${items.map(([go, l, i]) => navItem(go, l, i, go === activeGo)).join('')}</div></div>`;
    s = s.replace(/(<\/div>\s*(?:<!--[^>]*-->\s*)?<div class="prof-side-user")/, `${section}$1`);
  }
  return s;
}

function topbarFor(crumb, title) {
  return R.topbar
    .replace(/(id="profTbCrumb">)[^<]*/, `$1${esc(crumb)}`)
    .replace(/(id="profTbTitle">)[^<]*/, `$1${esc(title)}`)
    .replace(/<span class="prof-bell-badge"[^>]*hidden=""><\/span>/, '<span class="prof-bell-badge" id="profBellBadge">2</span>');
}

function cabFrame({ role, go, crumb, title, body }) {
  return `<div class="prof-app">${sideFor(role, go)}<div class="prof-main">${topbarFor(crumb, title)}<div class="prof-stage"><section class="prof-screen active">${body}</section></div></div></div>
  <div class="prof-ctx-backdrop" id="profCtxBackdrop"></div><div class="prof-ctx-menu" id="profCtxMenu"></div><div class="prof-notif-pop" id="profNotifPop" hidden></div>`;
}

const crumbsRow = (items) => `<div class="crumbs-row"><div class="crumbs-row__inner"><nav class="fs-breadcrumbs" aria-label="Хлебные крошки">${items.map((t, i) => (i === items.length - 1 ? `<span class="fs-breadcrumbs__current" aria-current="page">${t}</span>` : `<a href="#">${t}</a><span class="fs-breadcrumbs__sep" aria-hidden="true">/</span>`)).join(' ')}</nav></div></div>`;
function siteFrame(body, { aside = true, crumbs = null } = {}) {
  const inner = aside
    ? `<div class="fs-page-wrapper fs-all-tasks-page">${crumbs ? crumbsRow(crumbs) : ''}<div class="shell"><div class="layout">${R.siteAside}<main class="main">${body}</main></div></div></div>`
    : `<div class="fs-page-wrapper">${body}</div>`;
  return `${R.siteHeader}${inner}${R.siteFooter}`;
}

const emptyState = (title, text, action) => `<div class="prof-dash"><div class="prof-ktp-empty"><div class="ke-ico">${ICON.calendar}</div><h3>${esc(title)}</h3><p>${esc(text)}</p>${action || ''}</div></div>`;
const dash = (title, sub, inner) => `<div class="prof-dash"><div class="prof-dash-hello"><h1>${esc(title)}</h1><p>${esc(sub)}</p></div>${inner}</div>`;

/* ── Кабинет: ученик и родитель («Мои экзамены») ───────────────────── */
/* Карусель выбора даты и времени — крупные карточки (общая для кабинета и сайта) */
function slotCarousel() {
  return `<div class="mk-slots-wrap"><button type="button" class="mk-slots-nav prev" data-action="slots-scroll" data-dir="-1" aria-label="Предыдущие даты" hidden>‹</button>
    <div class="mk-slots" id="mkSlots">${SLOTS.map((s, i) => `<button type="button" class="mk-slot${i === S.slot ? ' on' : ''}" data-action="pick-slot" data-i="${i}" ${s.left === 0 ? 'disabled' : ''} aria-pressed="${i === S.slot}"><strong>${s.full}</strong><small>${s.dow}</small><span class="mk-time">${s.t}</span><small>очно · ${s.room}</small><small>${slotWord(s.left)}</small></button>`).join('')}</div>
    <button type="button" class="mk-slots-nav next" data-action="slots-scroll" data-dir="1" aria-label="Следующие даты" hidden>›</button></div>`;
}

const examTabs = (done) => `<div class="sc-tabs-wrap" data-ready="1"><div class="sc-tabs">
  <button type="button" class="sc-tab${done ? '' : ' on'}"><span class="sc-chip chip-c5">ЕГЭ</span><span class="sc-tb"><span class="sc-tname">Октябрьский пробник</span><span class="sc-tsub">12–21 октября · Информатика</span></span></button>
  <button type="button" class="sc-tab${done ? ' on' : ''}"><span class="sc-chip chip-c3">ЕГЭ</span><span class="sc-tb"><span class="sc-tname">Сентябрьский пробник</span><span class="sc-tsub">завершён · 72 из 100</span></span></button></div></div>`;

const TASKS = [['1', 'Информационные модели', 'ok'], ['2', 'Таблицы истинности', 'bad'], ['3', 'Поиск информации в базе данных', 'wait'], ['26', 'Обработка данных (2 балла)', 'part'], ['27', 'Программирование (2 балла)', 'bad']];
const taskRows = () => TASKS.map(([n, t, v]) => `<div class="sc-row open click"><span class="sc-num">${n}</span><span class="sc-lb"><span class="sc-ltitle">${t}</span><span class="sc-lsub">${v === 'ok' ? '1 из 1' : v === 'part' ? '1 из 2' : v === 'wait' ? 'не решено' : '0 из ' + (n === '27' ? '2' : '1')}</span></span><span class="sc-go">Разбор →</span><span class="sc-pill ${{ ok: 'done', bad: 'mk-err', part: 'mk-warn', wait: 'lock' }[v]}">${v === 'ok' ? 'верно' : v === 'part' ? 'частично' : v === 'wait' ? 'не решено' : 'неверно'}</span></div>`).join('');

function studentBody(isParent) {
  const st = S.demo[isParent ? 'parent' : 'student'];
  const child = isParent ? `<div class="prof-child-bar"><span class="prof-chip">Только просмотр</span><label class="prof-child-pick">Ученик: <select><option>Мария Алексеева</option><option>Алексей Алексеев</option></select></label></div>` : '';
  if (st === 'empty') {
    return `<div class="prof-dash">${child}<div class="prof-dash-hello"><h1>Мои экзамены</h1><p>Запись, предстоящие экзамены и результаты</p></div></div>`
      + emptyState('Пока нет экзаменов', 'Когда преподаватель опубликует экзамен по вашему направлению, он появится здесь. Вопросы — к преподавателю.');
  }
  const done = st === 'result';
  const slot = SLOTS[S.slot];
  let hero; let below = '';
  if (st === 'booking') {
    hero = `<div class="sc-hact">${isParent ? '' : btn('Записаться', 'scroll-pick', 'prof-btn-primary sc-hbtn')}<div class="sc-hint">${isParent ? 'Записывается ученик в своём кабинете' : 'выберите сеанс ниже'}</div></div>`;
    below = `<div class="prof-card" id="pickCard"><div class="prof-card-head"><div><h3>Выберите дату и время</h3><span class="ch-sub">Все сеансы — один пробник. Смена и отмена записи — до начала выбранного сеанса. Время центра.</span></div></div>
      <div style="padding:0 1.125rem 1.125rem">${slotCarousel()}<div class="mk-row-actions" style="justify-content:space-between"><span class="ch-sub">Место закрепляется после подтверждения записи</span>${btn('Подтвердить запись', 'confirm-slot', 'prof-btn-primary')}</div></div></div>`;
  } else if (st === 'booked') {
    hero = `<div class="sc-hact">${isParent ? '' : '<button class="prof-btn sc-hbtn" disabled>Приступить</button>'}<div class="sc-hint">${isParent ? 'Экзамен начинает только ученик' : 'откроется ' + slot.full + ' в ' + slot.t}</div></div>`;
    below = `<div class="prof-card"><div class="prof-card-head"><div><h3>Вы записаны</h3><span class="ch-sub">${slot.full}, ${slot.t} · очно · ${slot.room}. Экзамен добавлен в расписание.</span></div>${isParent ? '' : `<div class="mk-row-actions">${btn('Изменить запись', 'change-slot', 'prof-btn-sm')}${btn('Отменить запись', 'cancel-slot', 'prof-btn-sm prof-btn-danger')}</div>`}</div>
      <div class="sc-notice">Начать можно с ${slot.t} до 13:55; после старта — 3 часа 55 минут. Если заболели — свяжитесь с преподавателем.</div></div>`;
  } else if (st === 'waiting') {
    hero = `<div class="sc-hact"><span class="sc-pill open">ожидает утверждения</span><div class="sc-hint">работа сохранена 12 окт., 13:41</div></div>`;
    below = `<div class="prof-card"><div class="sc-notice">Работа сдана. Результат появится после утверждения преподавателем.</div></div>`;
  } else {
    hero = `<div class="sc-hact">${btn('Результаты', 'open-result', 'prof-btn-primary sc-hbtn')}<div class="sc-hint">72 из 100 · 18 из 29 первичных</div></div>`;
    below = `<div class="prof-card"><div class="prof-card-head"><div><h3>Задания</h3><span class="ch-sub">Показаны первые 5 из 27</span></div></div>${taskRows()}</div>`;
  }
  return `<div class="prof-dash">${child}<div class="prof-dash-hello"><h1>Мои экзамены</h1><p>Запись, предстоящие экзамены и результаты</p></div>
    ${examTabs(done)}
    <div class="prof-card sc-hero"><div class="sc-hero-top"><div class="sc-hinfo"><span class="sc-code chip-soft-c5">ЕГЭ · Информатика</span><div class="sc-htitle">${done ? 'Сентябрьский' : 'Октябрьский'} пробный экзамен</div>
      <div class="sc-hmeta">${done ? '24 сентября · очно · каб. 204' : 'Период 12–21 октября · 27 заданий · 3 ч 55 мин · одна сдача'}</div></div>${hero}</div></div>${below}</div>`;
}

/* ── Кабинет: преподаватель ────────────────────────────────────────── */
function calendarBody() {
  const filled = S.demo.calendar === 'filled';
  if (!filled) {
    return emptyState('Пока нет проведений', 'Создайте проведение: период, даты сеансов и экзаменационная работа. Ученики группы предмета увидят его после публикации.',
      `<div style="margin-top:1rem">${btn('Добавить проведение', 'event-settings', 'prof-btn-primary')}</div>`);
  }
  const cells = [];
  for (let i = 0; i < 3; i++) cells.push('<div class="kal-cell empty"></div>');
  const ev = { 12: [['10:00 · 14/20', 'каб. 204'], ['15:00 · 18/20', 'каб. 205']], 15: [['10:00 · 20/20', 'каб. 204']], 21: [['15:00 · 12/20', 'каб. 204']], 23: [['15:00 · 3/20', 'доп. сдача']] };
  for (let d = 1; d <= 31; d++) {
    const inPeriod = d >= 12 && d <= 23;
    cells.push(`<div class="kal-cell${inPeriod ? '' : ' no-lesson'}" data-day="${d}"><div class="kal-date"><span class="kd-num">${d}</span></div>${(ev[d] || []).map(([t, m]) => `<div class="placed-theme" draggable="true" data-action="open-session"><span class="pt-title">${t}</span><span class="pt-meta">Пробник № 2</span><span class="pt-meta">${m}</span></div>`).join('')}</div>`);
  }
  return `<div class="prof-ktp"><div class="prof-ktp-head"><div class="prof-ktp-pickers">
      <div class="prof-ktp-pick"><span class="kp-label">Предмет</span><button type="button" class="kp-btn"><span class="kp-chip chip-c5">ЕГЭ</span><span class="kp-txt">Информатика</span></button></div>
      <div class="prof-ktp-pick"><span class="kp-label">Проведение</span><button type="button" class="kp-btn"><span class="kp-chip chip-c5">ИНФ</span><span class="kp-txt">Октябрьский пробник · 12–23 октября</span></button></div></div>
    <span class="prof-spacer"></span>
    <div class="prof-ktp-legend"><span class="kl"><span class="prof-dot prof-dot-good"></span>Сеанс экзамена</span></div>
    ${btn('Настройки проведения', 'event-settings', 'prof-btn-sm')}${btn('Опубликовать', 'publish', 'prof-btn-sm prof-btn-primary')}</div>
    <div class="prof-ktp-grid"><div class="prof-theme-bank"><div class="tb-head"><h3>Экзаменационные работы</h3><span class="tbh-count">перетащите на дату</span></div>
      <div class="prof-theme-list"><div class="prof-theme-card" draggable="true"><span class="tc-grip">⋮⋮</span><span class="tc-num">1</span><div class="tc-body"><div class="tc-title">Пробник № 2</div><div class="tc-meta"><span>27 единиц</span><span>29 баллов</span><span>235 мин</span></div></div></div>
      <div class="prof-theme-card" draggable="true"><span class="tc-grip">⋮⋮</span><span class="tc-num">2</span><div class="tc-body"><div class="tc-title">Другой вариант</div><div class="tc-meta"><span>для отдельного сеанса</span></div></div></div></div></div>
    <div class="prof-kal"><div class="kal-head"><button class="prof-icon-ghost" disabled>${svg(P('M12 4.5 6.5 10l5.5 5.5'), 16)}</button><div class="kal-month">Октябрь 2026</div><button class="prof-icon-ghost">${svg(P('M8 4.5 13.5 10 8 15.5'), 16)}</button><span class="prof-spacer"></span>${btn('+ Сеанс', 'new-session', 'prof-btn-sm')}</div>
      <div class="kal-grid-wrap"><div class="kal-dow"><span>Пн</span><span>Вт</span><span>Ср</span><span>Чт</span><span>Пт</span><span>Сб</span><span>Вс</span></div><div class="kal-grid">${cells.join('')}</div></div></div></div></div>`;
}

function personRow(p, i, approve) {
  const ready = i === 4;
  return `<div class="pr-row${approve ? '' : ' pr-row--link'}">${approve ? `<input type="checkbox" ${ready ? 'checked' : 'disabled'} aria-label="Выбрать ${esc(p.n)}">` : ''}
    <span class="pr-ava" style="background:linear-gradient(135deg,var(--ava-from),var(--ava-to))">${p.ini}</span>
    <div class="pr-info"><div class="pr-name">${esc(p.n)}</div><div class="pr-sub">${esc(p.src)}${i < 4 ? ' · гость' : ''}</div>
      <div class="mk-pills">${i < 4 ? pill('Оплата: ' + p.pay[0], p.pay[2] || p.pay[1]) : ''}${pill('Запись: ' + p.reg[0], p.reg[1])}${pill('Допуск: ' + p.adm[0], p.adm[1])}${pill('Попытка: ' + p.att[0], p.att[1])}</div></div>
    ${approve ? (ready ? verdict('готова к утверждению', 'pending') : '') : `<button type="button" class="prof-btn prof-btn-sm prof-btn-ghost" data-action="row-menu" data-i="${i}">Действия ⋮</button>`}</div>`;
}

function sessionBody() {
  const approve = S.demo.session === 'approve';
  const tabs = `<div class="sc-tabs-wrap" data-ready="1"><div class="sc-tabs">
    <button class="sc-tab on"><span class="sc-chip chip-c5">пн</span><span class="sc-tb"><span class="sc-tname">12 октября · 10:00</span><span class="sc-tsub">каб. 204 · записано 14 из 20</span></span></button>
    <button class="sc-tab"><span class="sc-chip chip-c5">пн</span><span class="sc-tb"><span class="sc-tname">12 октября · 15:00</span><span class="sc-tsub">каб. 205 · записано 18 из 20</span></span></button>
    <button class="sc-tab"><span class="sc-chip chip-c3">чт</span><span class="sc-tb"><span class="sc-tname">15 октября · 10:00</span><span class="sc-tsub">каб. 204 · записано 20 из 20</span></span></button></div></div>`;
  const tiles = `<div class="prof-stat-tiles">${[['Записано', '14', 'из 20 мест'], ['Пришли', '11', 'начали сдачу'], ['Сдали', '1', 'ждёт утверждения 1'], ['Требуют помощи', '1', 'оплата без места']].map(([t, v, d]) => `<div class="prof-stat-tile"><div class="st-top">${t}</div><div class="st-val">${v}</div><div class="st-delta">${d}</div></div>`).join('')}</div>`;
  const head = `<div class="prof-card-head"><div><h3>Участники</h3><span class="ch-sub">Вход 10:00–13:55 · после старта 3 ч 55 мин</span></div><div class="mk-row-actions ch-act">
    ${approve ? `${btn('Отмена', 'approve-off', 'prof-btn-sm')}${btn('Утвердить все', 'approve-do', 'prof-btn-sm prof-btn-primary')}` : `${btn('Добавить гостя', 'add-guest', 'prof-btn-sm')}${btn('Утвердить работы', 'approve-on', 'prof-btn-sm prof-btn-primary')}`}</div></div>`;
  return dash('Проведение экзамена', 'Октябрьский пробник · 12 октября', `${tabs}${tiles}<div class="prof-card">${head}<div class="pr-list" style="padding:.75rem 1.125rem 1.125rem">${PEOPLE.map((p, i) => personRow(p, i, approve)).join('')}</div></div>`);
}

function guestsBody() {
  const rows = PEOPLE.map((p, i) => ({ p, i })).filter(({ i }) => i !== 2 && i !== 3);
  const scores = ['72 / 100', '80 / 100', '', '', 'ждёт утверждения'];
  return dash('Результаты', 'Октябрьский пробник · 12–23 октября', `<div class="prof-card"><div class="prof-card-head"><div><h3>Участники</h3><span class="ch-sub">Гости и ученики центра</span></div>
    <div class="prof-seg ch-act"><button class="on">Все · 5</button><button>Гости · 4</button><button>Ученики · 1</button></div></div>
    <div class="pr-list" style="padding:.75rem 1.125rem 1.125rem">${rows.map(({ p, i }) => `<div class="pr-row pr-row--link"><span class="pr-ava" style="background:linear-gradient(135deg,var(--ava-from),var(--ava-to))">${p.ini}</span><div class="pr-info"><div class="pr-name">${esc(p.n)}</div><div class="pr-sub">${esc(p.src)} · ${scores[i]}</div></div>${btn('Работа', 'open-review', 'prof-btn-sm prof-btn-ghost')}${i < 4 ? btn('Личная ссылка результата', 'issue-result', 'prof-btn-sm') : ''}</div>`).join('')}</div></div>
    <div class="prof-card"><div class="prof-card-head"><div><h3>Отчёты школам</h3><span class="ch-sub">Получатель видит только выбранных участников</span></div>${btn('Создать отчёт', 'make-report', 'prof-btn-sm prof-btn-primary ch-act')}</div>
      <div class="prof-work-item is-clickable" data-action="open-report"><div class="prof-work-ico rev">${ICON.check}</div><div class="prof-work-main"><div class="prof-work-title">Школа № 1 · выбранные участники</div><div class="prof-work-sub">3 участника · доступ до 30 ноября</div></div>${btn('Отозвать', 'revoke-report', 'prof-btn-sm')}</div></div>`);
}

function reviewBody() {
  const t = (n, cond, ans, cor, v, lab, score) => `<article class="sum-task"><div class="sum-task-head"><span class="st-n">Задача ${n}</span>${verdict(lab, v)}<span class="st-score">${score}</span></div><div class="sum-task-cond">${cond}</div>
    <div class="sum-task-ans"><span class="sta-label">Ответ участника:</span> <span class="sta-val">${ans}</span></div>${v === 'correct' ? '' : `<div class="sum-task-ans sum-task-correct"><span class="sta-label">Правильный ответ:</span> <span class="sta-val">${cor}</span></div>`}</article>`;
  return `<div class="prof-dash"><div class="prof-dash-hello"><h1>Мария Алексеева · Октябрьский пробник</h1><p>Сдано 12 окт., 13:41 · 235 мин отведено · ожидает утверждения</p></div>
    <div class="prof-card"><div class="prof-card-head"><div><h3>Разбор по заданиям</h3><span class="ch-sub">Тот же экран, что у «Работ»</span></div><div class="mk-row-actions">${btn('Лист результатов', 'noop', 'prof-btn-sm')}${btn('Утвердить работу', 'approve-one', 'prof-btn-sm prof-btn-primary')}</div></div>
    <div style="padding:.75rem 1.125rem 1.125rem">${t(1, 'Для кодирования 256 символов используются коды одинаковой длины. Сколько бит нужно на один код?', '8', '8', 'correct', 'Верно', '1/1')}
    ${t(2, 'Сколько наборов (x, y, z) обращают F = (x ∧ ¬y) ∨ (y ∧ z) в истину?', '3', '4', 'incorrect', 'Неверно', '0/1')}
    ${t(26, 'Табличный ответ: количество элементов и значение последнего.', '12 | 40', '12 | 45', 'pending', 'Частично верно', '1/2')}</div></div></div>`;
}

function statsBody() {
  const rows = [['1', 'Информационные модели', 92, 0, 8], ['2', 'Таблицы истинности', 61, 0, 39], ['26', 'Обработка данных', 30, 40, 30], ['27', 'Программирование', 12, 18, 70]];
  return dash('Статистика экзаменов', 'Октябрьский пробник · выборка 18 работ', `<div class="prof-stat-tiles">${[['Записано', '52', 'из 60 мест'], ['Сдали', '18', 'утверждено 15'], ['Не явились', '2', '4%'], ['Средний балл', '68', 'из 100 · по 15 работам']].map(([t, v, d]) => `<div class="prof-stat-tile"><div class="st-top">${t}</div><div class="st-val">${v}</div><div class="st-delta">${d}</div></div>`).join('')}</div>
    <div class="prof-card"><div class="prof-card-head"><div><h3>По заданиям</h3><span class="ch-sub">Полностью / частично / ошибки · №26 и №27 по 2 балла</span></div></div>
    ${rows.map(([n, t, full, part, bad]) => `<div class="sc-row"><span class="sc-num">${n}</span><span class="sc-lb"><span class="sc-ltitle">${t}</span><span class="sc-lsub">${full}% полностью · ${part}% частично · ${bad}% ошибок</span></span><span class="sc-pill ${full > 50 ? 'done' : 'open'}">${full}%</span></div>`).join('')}</div>`);
}

/* ── Кабинет: администратор — оплаты гостей ────────────────────────── */
function paymentsBody() {
  const help = S.demo.payments === 'help';
  const items = help
    ? [['Козлов Илья · заказ #1248', 'Оплата получена 12:41 · сеанс 12 окт. 10:00 заполнен · место не удержано', 'att'], ['Лебедева Ольга · заказ #1252', 'Оплата получена 13:05 · бронь истекла, сеанс 15 окт. закрыт', 'att']]
    : [['Орлова Алина · заказ #1240', 'Оплачено · запись подтверждена · 12 окт. 10:00', 'rev'], ['Волков Артём · заказ #1241', 'Оплачено купоном · запись подтверждена · 12 окт. 15:00', 'rev'], ['Морозова Анна · заказ #1250', 'Ожидаем оплату · бронь до 14:20', 'grade'], ['Козлов Илья · заказ #1248', 'Оплачено, требуется помощь', 'att']];
  return dash('Оплаты гостей', 'Октябрьский пробник · оплата, запись, допуск и попытка — отдельные состояния', `<div class="prof-card"><div class="prof-card-head"><div><h3>${help ? 'Оплачено, требуется помощь' : 'Все оплаты'}</h3><span class="ch-sub">${help ? 'Уведомление о каждой такой заявке получают администраторы платформы' : 'Данные из заказов WooCommerce'}</span></div>
    <div class="prof-seg ch-act"><button data-action="demo" data-v="help" class="${help ? 'on' : ''}">Требуют помощи · 2</button><button data-action="demo" data-v="all" class="${help ? '' : 'on'}">Все · 14</button></div></div>
    ${items.map(([t, s, ico]) => `<div class="prof-work-item"><div class="prof-work-ico ${ico}">${ICON.rub}</div><div class="prof-work-main"><div class="prof-work-title">${t}</div><div class="prof-work-sub">${s}</div></div>
      ${help ? `<div class="mk-row-actions">${btn('Выбрать сеанс', 'resolve-pick', 'prof-btn-sm prof-btn-primary')}${btn('Отметить возврат вне Woo', 'resolve-refund', 'prof-btn-sm')}</div>` : ''}</div>`).join('')}</div>
    <p class="mk-note">Возврат денег выполняет сотрудник вне WooCommerce; здесь только отметка — LMS не вызывает возврат и не меняет заказ.</p>`);
}

/* ── Уведомления: тексты (экран для текста, стиль — как у колокольчика) ── */
const NOTIF = {
  student: [
    ['calendar', 'Открыта запись на экзамен', 'Октябрьский пробник · 12–21 октября. Выберите сеанс', 'сегодня', 'info', 1],
    ['check', 'Вы записаны на экзамен', '12 октября · 10:00 · каб. 204', 'сегодня', 'ok', 1],
    ['calendar', 'Завтра экзамен', '12 октября · 10:00 · каб. 204', '1 дн', 'info', 0],
    ['calendar', 'Экзамен начнётся через час', '10:00 · каб. 204', '1 дн', 'info', 0],
    ['check', 'Можно приступить к экзамену', 'Начало до 13:55 · на работу 3 ч 55 мин', '1 дн', 'ok', 0],
    ['calendar', 'Запись перенесена', 'Новый сеанс: 15 октября · 10:00', '2 дн', 'warn', 0],
    ['alert', 'Запись отменена преподавателем', 'Причина: болезнь. Можно выбрать другой сеанс', '2 дн', 'warn', 0],
    ['alert', 'Экзамен пропущен', 'Запись аннулирована. Можно записаться на другой сеанс', '3 дн', 'err', 0],
    ['check', 'Работа сдана', 'Результат появится после утверждения преподавателем', '3 дн', 'info', 0],
    ['check', 'Результат экзамена готов', '72 из 100 баллов', '4 дн', 'ok', 0],
    ['alert', 'Результат исправлен', 'Причина: пересчитано задание 26', '4 дн', 'warn', 0],
    ['calendar', 'Время экзамена продлено', 'Новый срок окончания: 15:10', '5 дн', 'info', 0],
  ],
  teacher: [
    ['check', 'Экзамен сдан: Мария Алексеева', 'Октябрьский пробник · ждёт утверждения', 'сегодня', 'info', 1],
    ['check', 'Экзамен сдан: Алина Орлова (гость)', 'Октябрьский пробник · часть заданий ждёт проверки', 'сегодня', 'info', 1],
    ['alert', 'Много заявок по ссылке школы № 1', '60 активных броней · проверьте ссылку', '1 дн', 'warn', 0],
  ],
  office: [
    ['alert', 'Оплата без места: Козлов Илья', 'Заказ #1248 · 12 октября 10:00 · мест нет', 'сегодня', 'err', 1],
    ['alert', 'Оплата без места: Лебедева Ольга', 'Заказ #1252 · бронь истекла, сеанс закрыт', 'сегодня', 'err', 1],
    ['alert', 'Не удалось сверить оплату', 'Заказ #1251 · проверьте статус в WooCommerce', '1 дн', 'warn', 0],
    ['alert', 'Много заявок по ссылке школы № 1', '60 активных броней · проверьте ссылку', '1 дн', 'warn', 0],
  ],
};
const notifItem = ([ico, title, sub, time, tone, unread]) => `<a class="prof-notif-item" href="#notifications" data-tone="${tone}"><span class="ni-ico">${ICON[ico]}</span><span class="ni-body"><span class="ni-title">${unread ? '<span class="ni-dot"></span>' : ''}${esc(title)}</span><span class="ni-sub">${esc(sub)}</span></span><span class="ni-time">${time}</span></a>`;
function notificationsBody() {
  const role = S.notifRole;
  const label = { student: 'Ученик и родитель', teacher: 'Преподаватель', office: 'Администратор платформы' };
  return dash('Уведомления по экзаменам', 'Тексты для разработки. Отображение — как у колокольчика: каждое уведомление целиком является ссылкой, кнопок внутри нет.', `<div class="mk-demo-bar"><div class="prof-seg">${Object.keys(label).map((k) => `<button class="${k === role ? 'on' : ''}" data-action="notif-role" data-v="${k}">${label[k]}</button>`).join('')}</div></div>
    <div class="prof-card"><div class="prof-notif-list" style="max-height:none">${NOTIF[role].map(notifItem).join('')}</div></div>
    <p class="mk-note">Родителю приходят те же тексты с именем ребёнка («Запись Марии подтверждена»). Гостям уведомлений нет — письма WooCommerce не меняются.</p>`);
}

/* ── Публичный сайт ─────────────────────────────────────────────────── */
const fld = (label, icon, input, req = true) => `<div class="fs-join-card__field-group fs-form-group"><label>${label}${req ? ' <span aria-hidden="true">*</span>' : ''}</label><div class="fs-field-control"><span class="dashicons dashicons-${icon}" aria-hidden="true"></span>${input}</div></div>`;

function summaryBlock() {
  const s = SLOTS[S.slot];
  return `<div class="mk-summary"><h3>Ваша запись</h3><dl><dt>Участник</dt><dd>Орлова Алина Сергеевна</dd><dt>Направление</dt><dd>ЕГЭ по информатике</dd><dt>Дата и время</dt><dd>${s.full} 2026, ${s.dow}, ${s.t} (время центра)</dd><dt>Место</dt><dd>г. Калининград, ул. Черняховского, 6, ${s.room}</dd><dt>Стоимость</dt><dd class="mk-price">1 500 ₽</dd></dl></div>`;
}

function signupBody() {
  const st = S.demo.signup;
  if (st === 'paid') {
    return `<main class="fs-lms-join-page"><div class="fs-join-card"><div class="fs-apply-card__success" role="status"><span class="dashicons dashicons-yes-alt fs-apply-card__success-icon" aria-hidden="true"></span>
      <p class="fs-apply-card__success-title">Оплата получена! Администратор скоро свяжется с вами</p></div></div></main>`;
  }
  if (st === 'hold' || st === 'pending') {
    const m = Math.floor(S.hold / 60); const sec = String(S.hold % 60).padStart(2, '0');
    return `<main class="fs-lms-join-page"><div class="fs-join-card"><h2 class="fs-join-card__title">${st === 'hold' ? 'Место удерживается' : 'Оплата ещё не подтверждена'}</h2>
      ${summaryBlock()}
      <div class="fs-apply-card__status"><span class="fs-apply-card__spinner" aria-hidden="true"></span><div>
        ${st === 'hold'
    ? `<p class="mk-hold">Ожидаем оплату. Место удерживается до 14:20 — осталось <b id="holdLeft">${m}:${sec}</b></p><p class="fs-apply-card__success-notice">Завершите оплату в корзине. Если закрыли страницу — вернитесь по ссылке из корзины.</p>`
    : `<p class="mk-hold">Подтверждение оплаты ещё не получено</p><p class="fs-apply-card__success-notice">Не оплачивайте повторно. Если деньги списаны, запись подтвердится автоматически; администратор свяжется с вами.</p>`}</div></div>
      <button type="button" class="fs-join-card__submit" data-action="${st === 'hold' ? 'go-cart' : 'check-status'}">${st === 'hold' ? 'Перейти в корзину' : 'Проверить статус'}</button></div></main>`;
  }
  return `<main class="fs-lms-join-page"><div class="fs-join-card"><h2 class="fs-join-card__title">Запись на пробный экзамен</h2>
    <p class="fs-join-card__subtitle">ЕГЭ по информатике · 12–21 октября 2026. Сразу после сдачи вы увидите свою работу и разбор заданий.</p>
    <form id="guestForm" novalidate>
      <fieldset class="fs-join-card__section"><legend class="fs-join-card__section-title">Школа и класс</legend>
        <p class="fs-join-card__locked-notice"><span class="dashicons dashicons-lock" aria-hidden="true"></span>Подставлены из вашей ссылки и не меняются.</p>
        ${fld('Школа', 'building', '<input type="text" value="Школа № 1" readonly>', false)}
        ${fld('Класс', 'list-view', '<input type="text" value="11 класс" readonly>', false)}
      </fieldset>
      <fieldset class="fs-join-card__section"><legend class="fs-join-card__section-title">Данные участника</legend>
        ${fld('Фамилия', 'admin-users', '<input type="text" placeholder="Орлова" required>')}
        ${fld('Имя', 'admin-users', '<input type="text" placeholder="Алина" required>')}
        ${fld('Отчество', 'admin-users', '<input type="text" placeholder="Сергеевна">', false)}
        ${fld('Номер телефона', 'phone', '<input type="tel" placeholder="+7 (999) 000-00-00" required>')}
        ${fld('Связь через мессенджер', 'format-chat', '<input type="text" placeholder="@имя или телефон">', false)}
      </fieldset>
      <fieldset class="fs-join-card__section"><legend class="fs-join-card__section-title">Дата и время</legend>
        ${slotCarousel()}
        <p class="mk-hint">Место удерживается после перехода к оплате (20 минут).</p>
      </fieldset>
      <fieldset class="fs-join-card__section fs-join-card__section--consents"><div class="fs-join-card__consent"><label><input type="checkbox" required><span>Я даю согласие на обработку персональных данных. <a class="button-link" href="#">Прочитать</a></span></label></div></fieldset>
      ${summaryBlock()}
      <button type="submit" class="fs-join-card__submit">Перейти к оплате</button>
    </form></div></main>`;
}

function entryBody() {
  return `<main class="fs-lms-join-page"><div class="fs-join-card"><h2 class="fs-join-card__title">Вход на экзамен</h2>
    <p class="fs-join-card__subtitle"><strong>Орлова Алина Сергеевна</strong><br>Октябрьский пробник · 12 октября · каб. 204</p>
    <p class="mk-hint" style="font-size:.9rem">Начать можно с 10:00 до 13:55. После начала у вас будет 3 часа 55 минут. Ссылка персональная — не передавайте её другим.</p>
    <button type="button" class="fs-join-card__submit" data-action="start-exam">Приступить</button></div></main>`;
}

const VERDICT_CLS = { 'Верно': 'tcr-tag--c3', 'Неверно': 'mk-err', 'Не решено': 'tcr-tag--c2' };
/* Карточка задания — шаблон Тренажёра (task-card-row). Ответы показаны сразу, решение раскрывается снизу, как на странице задания. */
const tcr = (n, cond, ans, cor, v, solution) => `<article class="task-card-row" id="t-${n}"><h2 class="tcr-title">Задание № ${n}</h2>
  <header class="tcr-header"><div class="tcr-header-inner"><div class="tcr-meta"><span class="tcr-tag tcr-tag--c1">Задание №${n}</span><span class="tcr-tag ${VERDICT_CLS[v] || 'tcr-tag--c1'}">${v}</span></div></div></header>
  <div class="tcr-body"><div class="tcr-condition">${cond}</div></div>
  <div class="fs-answer"><div class="fs-answer-label">Ваш ответ:</div><div class="fs-answer-value">${ans}</div></div>
  ${v === 'Верно' ? '' : `<div class="fs-answer"><div class="fs-answer-label">Правильный ответ:</div><div class="fs-answer-value">${cor}</div></div>`}
  <footer class="tcr-foot"><button type="button" class="fs-answer-toggle" data-action="toggle-solution" aria-expanded="false">Показать решение</button></footer>
  <div class="fs-answer mk-solution" hidden><div class="fs-answer-label">Решение:</div><div class="fs-answer-note">${solution}</div></div></article>`;

const EX_TASKS = {
  1: ['<p>Для кодирования 256 символов используются двоичные коды одинаковой длины. Сколько бит нужно на один код?</p>', '8', '8', 'Верно', '2⁸ = 256, поэтому достаточно 8 бит.'],
  2: ['<p>Логическая функция F = (x ∧ ¬y) ∨ (y ∧ z). Сколько наборов (x, y, z) обращают F в истину?</p>', '3', '4', 'Неверно', 'При y = 0 подходят два набора с x = 1, при y = 1 — ещё два с z = 1. Итого 4.'],
  3: ['<p>В таблице результаты трёх участников: 52, 71 и 85 баллов. Сколько записей удовлетворяют условию «балл ≥ 70»?</p>', '—', '2', 'Не решено', 'Подходят результаты 71 и 85 — две записи.'],
  26: ['<p>Табличный ответ: количество выбранных элементов и значение последнего.</p>', '12 | 40', '12 | 45', 'Неверно', 'Первый компонент совпал с эталоном, второй — нет. За задание 2 первичных балла; получен 1.'],
  27: ['<p>Укажите два результата расчёта для файлов A и B.</p>', '100 | 200', '120 | 240', 'Неверно', 'Оба компонента не совпали с эталоном. Максимум задания — 2 первичных балла.'],
};
const exTasks = (ids) => `<div class="task-cards">${ids.map((n) => tcr(n, ...EX_TASKS[n])).join('')}</div>`;
/* квадратики отметок заданий (из прошлого макета): зелёный — верно, красный — неверно, жёлтый — не решено / частично */
const tasknav = () => `<div class="mk-tasknav">${Array.from({ length: 27 }, (_, i) => `<button type="button" data-action="jump" data-n="${i + 1}" class="${i === 1 || i === 26 ? 'bad' : i === 2 || i >= 19 ? 'wait' : 'ok'}">${i + 1}</button>`).join('')}</div><p class="mk-hint">Зелёный — верно · красный — неверно · жёлтый — не решено или частично верно</p>`;

function resultBody() {
  return `<div class="mk-eyeline">Алина Орлова · Октябрьский пробник</div><h1 class="mk-h1">Ваш результат по информатике</h1>
    <section class="side-card mk-hero"><div class="mk-hero-main"><span class="tcr-tag tcr-tag--c3">Результат доступен</span><h2 class="mk-h2">ЕГЭ по информатике</h2><p class="mk-lead" style="margin:.25rem 0 0">12 октября, 10:00 · очно · пробник № 2</p>
      <div class="mk-score"><div class="mk-score-row"><strong>72 из 100 баллов</strong><small>18 / 29 первичных</small></div><div class="mk-progress"><span style="width:72%"></span></div></div></div>
      <div class="mk-pillstats"><span>Ваша работа сохранена</span><span>Доступен разбор заданий</span><span>Часть заданий ждёт ручной проверки — итог предварительный</span></div></section>
    <div class="mk-section-head"><h2 class="mk-h2">Разбор заданий</h2><small>27 заданий</small></div>${tasknav()}
    ${exTasks([1, 2, 3, 26, 27])}
    <p class="mk-note">В макете пять заданий, включая №26 и №27; в продукте — все задания работы.</p>
    <p style="margin:1.25rem 0"><button type="button" class="fs-join-card__submit" style="max-width:16rem" data-action="end-session">Завершить сеанс</button></p>`;
}

function reportBody() {
  const rows = [['Орлова Алина', 'Школа № 1', '12 окт., 10:00', '18', '72', true], ['Волков Артём', 'Школа № 1', '12 окт., 15:00', '21', '80', true], ['Морозова Анна', 'Школа № 1', '15 окт., 10:00', '14', '62', true], ['Соколова Дарья', 'Самостоятельная запись', '21 окт., 15:00', '—', '—', false]];
  const open = S.reportPerson !== null ? rows[S.reportPerson] : null;
  return `<div class="mk-report-head"><span class="tcr-tag mk-gray">Доступ по ссылке</span><h1 class="mk-h1" style="margin-top:.5rem">Результаты учеников школы № 1</h1><p class="mk-lead" style="margin:0">Октябрьский пробник · ЕГЭ по информатике · 12–21 октября 2026 · отчёт только для просмотра</p></div>
    <div class="mk-metrics">${[['3', 'участника<br>в отчёте'], ['3', 'результата<br>опубликовано'], ['71', 'средний<br>балл из 100']].map(([b, t]) => `<section class="side-card"><b>${b}</b><span>${t}</span></section>`).join('')}</div>
    <section class="side-card" style="padding:0;margin-bottom:1.25rem"><div class="mk-card-head"><div><h3>Ученики и работы</h3></div></div>
    <div class="mk-tablewrap"><table class="mk-table"><thead><tr><th>Участник</th><th>Дата сдачи</th><th>Первичные</th><th>Из 100</th><th>Статус</th><th></th></tr></thead>
    <tbody>${rows.map((r, i) => `<tr><td class="mk-td-name"><strong>${r[0]}</strong><small>${r[1]}</small></td><td data-label="Дата сдачи">${r[2]}</td><td data-label="Первичные">${r[3]}</td><td data-label="Из 100"><strong>${r[4]}</strong></td><td data-label="Статус"><span class="tcr-tag ${r[5] ? 'tcr-tag--c3' : 'mk-gray'}">${r[5] ? 'Опубликован' : 'Ожидает сдачи'}</span></td><td class="mk-td-act"><button type="button" class="mk-link" data-action="report-open" data-i="${i}" ${r[5] ? '' : 'disabled'}>Результат и работа</button></td></tr>`).join('')}</tbody></table></div></section>
    ${open ? `<section id="report-work"><div class="mk-section-head"><div><h2 class="mk-h2">${open[0]}</h2><small>Октябрьский пробник · ${open[4]} из 100 · ${open[3]} / 29 первичных</small></div><span class="tcr-tag mk-gray">Только просмотр</span></div><h3 class="mk-h3">Разбор заданий</h3>${tasknav()}${exTasks([1, 2, 3, 26, 27])}<p class="mk-note">Пять демонстрационных заданий; в продукте здесь вся выбранная работа.</p></section>` : ''}
    <p class="mk-note">Доступ до 30 ноября 2026. Контакты участников и их личные ссылки в отчёте не показываются.</p>`;
}

/* ── Поповеры и диалоги ─────────────────────────────────────────────── */
const overlays = () => $('#overlays');
function closePops() { overlays().innerHTML = ''; }
function openPop(html) {
  overlays().innerHTML = `<div class="prof-ctx-backdrop open" data-action="close" style="display:block;background:rgba(20,24,33,.35)"></div><div class="prof-grade-pop open mk-center"><div class="gp-form mk-form">${html}</div></div>`;
}
function openConfirm({ title, lines, ok, onOk }) {
  overlays().innerHTML = `<div class="fs-confirm-overlay"><div class="fs-confirm-dialog" role="alertdialog"><div class="fs-confirm-message"><strong>${esc(title)}</strong><ul style="margin:.5rem 0 0 1rem;padding:0;text-align:left">${lines.map((l) => `<li>${esc(l)}</li>`).join('')}</ul></div>
    <div class="fs-confirm-actions"><button type="button" class="prof-btn" data-action="close">Отмена</button><button type="button" class="prof-btn prof-btn-primary" data-action="confirm-ok">${esc(ok)}</button></div></div></div>`;
  overlays()._onOk = onOk;
}

function eventSettings(isNew) {
  openPop(`<div class="gp-title">${isNew ? 'Новое проведение' : 'Настройки проведения'}</div>
    <label>Название<input value="${isNew ? '' : 'Октябрьский пробник'}" placeholder="Например, Октябрьский пробник"></label>
    <div class="mk-two"><label>Предмет<input value="Информатика (ЕГЭ)" disabled></label><label>Основной вариант<select><option>Пробник № 2 · 27 единиц</option><option>Другой вариант</option></select></label></div>
    <div class="mk-two"><label>Период: с<input type="date" value="2026-10-12"></label><label>по<input type="date" value="2026-10-23"></label></div>
    <div class="mk-two"><label>Запись открывается<input type="date" value="2026-10-01"></label><label>Запись закрывается<input type="date" value="2026-10-11"></label></div>
    <div class="gp-title" style="margin-top:.75rem">Ссылки для преподавателей</div>
    <p class="mk-hint">Каждая ссылка подставляет школу и класс в гостевую форму. Доступ к результатам она не даёт.</p>
    <div id="srcRows">${[['Школа № 1', '11', 'Елена Петрова'], ['Гимназия № 4', '11', 'Ольга Соколова']].map(([s, c, t], i) => srcRow(s, c, t, i)).join('')}</div>
    <div class="gp-row" style="margin:.25rem 0 .75rem">${btn('+ Добавить ссылку', 'add-src', 'prof-btn-sm')}</div>
    <div class="gp-row" style="justify-content:flex-end">${btn('Отмена', 'close', 'prof-btn-sm')}${btn(isNew ? 'Создать' : 'Сохранить', 'save-event', 'prof-btn-sm prof-btn-primary')}</div>`);
}
const srcRow = (s, c, t, i) => `<div class="mk-src-row"><label>Школа<input value="${s}"></label><label>Класс<select><option ${c === '9' ? 'selected' : ''}>9</option><option ${c === '11' ? 'selected' : ''}>11</option></select></label><label>ФИО учителя<input value="${t}"></label>
  <div class="mk-row-actions">${btn('Копировать', 'copy-link', 'prof-btn-sm')}${btn('Перевыпустить', 'reissue', 'prof-btn-sm prof-btn-ghost', `data-school="${esc(s)}"`)}</div></div>`;

/* ── Меню «Действия» строки участника ─────────────────────────────── */
function rowMenu(anchor, i) {
  const menu = $('#profCtxMenu'); const back = $('#profCtxBackdrop');
  const guest = i < 4;
  const items = [['Отметить приход', 'arrived'], ['Выдать ссылку на вход', 'issue-entry'], ...(guest ? [['Отметить «Ссылка передана»', 'handed'], ['Перенести в другой сеанс', 'transfer'], ['Отметить возврат вне Woo', 'resolve-refund']] : []), ['Продлить попытку', 'extend'], ['Отменить запись', 'cancel-reg', 'danger'], ['Открыть работу', 'open-review']];
  menu.innerHTML = `<div class="ctx-title">${esc(PEOPLE[i].n)}</div>${items.map(([l, a, c]) => `<div class="ctx-item ${c || ''}" data-action="${a}" data-i="${i}"><span class="ctx-lbl">${l}</span></div>`).join('')}`;
  const r = anchor.getBoundingClientRect();
  menu.classList.add('open'); menu.style.left = Math.max(10, r.left - 190) + 'px'; menu.style.top = r.bottom + 6 + 'px'; back.classList.add('open'); back.style.display = 'block';
}
function closeMenu() { const m = $('#profCtxMenu'); const b = $('#profCtxBackdrop'); if (m) { m.classList.remove('open'); } if (b) { b.classList.remove('open'); b.style.display = ''; } }

/* ── Отрисовка ──────────────────────────────────────────────────────── */
function render() {
  const route = routeOf(S.route); S.route = route.id;
  $$('link[data-set]').forEach((l) => { l.disabled = l.dataset.set !== route.frame; });
  document.body.className = route.frame === 'cab' ? 'fs-profile-page mk-cab' : 'mk-site';
  // на телефоне меню кабинета по умолчанию свёрнуто (как в app.js кабинета)
  if (route.frame === 'cab' && matchMedia('(max-width:720px)').matches) document.body.classList.add('prof-menu-off');
  let html;
  switch (route.id) {
    case 'student': html = cabFrame({ role: 'student', go: 'ex-student', crumb: 'Обучение', title: 'Мои экзамены', body: studentBody(false) }); break;
    case 'parent': html = cabFrame({ role: 'parent', go: 'ex-student', crumb: 'Обучение', title: 'Мои экзамены', body: studentBody(true) }); break;
    case 'calendar': html = cabFrame({ role: 'teacher', go: 'ex-calendar', crumb: 'Мои экзамены', title: 'Назначить экзамен', body: calendarBody() }); break;
    case 'session': html = cabFrame({ role: 'teacher', go: 'ex-session', crumb: 'Мои экзамены', title: 'Проведение экзамена', body: sessionBody() }); break;
    case 'guests': html = cabFrame({ role: 'teacher', go: 'ex-results', crumb: 'Мои экзамены', title: 'Результаты', body: guestsBody() }); break;
    case 'teacher-review': html = cabFrame({ role: 'teacher', go: 'ex-results', crumb: 'Проверка работ', title: 'Мария Алексеева', body: reviewBody() }); break;
    case 'stats': html = cabFrame({ role: 'teacher', go: 'ex-stats', crumb: 'Мои экзамены', title: 'Статистика', body: statsBody() }); break;
    case 'payments': html = cabFrame({ role: 'office', go: 'ex-payments', crumb: 'Мои экзамены', title: 'Оплаты гостей', body: paymentsBody() }); break;
    case 'notifications': html = cabFrame({ role: 'office', go: '', crumb: 'Личный кабинет', title: 'Уведомления', body: notificationsBody() }); break;
    case 'signup': html = siteFrame(signupBody()); break;
    case 'guest-entry': html = siteFrame(entryBody(), { aside: false }); break;
    case 'result': html = siteFrame(resultBody(), { crumbs: ['Главная', 'Пробные экзамены', 'Мой результат'] }); break;
    case 'report': html = siteFrame(reportBody(), { crumbs: ['Пробные экзамены', 'Отчёт для преподавателя'] }); break;
    default: html = '';
  }
  $$('body > [data-frame]').forEach((n) => n.remove());
  const tpl = document.createElement('template'); tpl.innerHTML = html;
  [...tpl.content.children].forEach((n) => { n.dataset.frame = '1'; });
  document.body.prepend(tpl.content);
  closePops(); renderPanel(); initCarousels();
  if (route.id === 'signup' && S.demo.signup === 'hold') startHold();
  window.scrollTo(0, 0);
}

/* Кнопки карусели: скрыты, если карточки помещаются; у краёв — неактивны */
function initCarousels() {
  $$('.mk-slots-wrap').forEach((wrap) => {
    const track = $('.mk-slots', wrap); const [prev, next] = $$('.mk-slots-nav', wrap);
    const update = () => {
      prev.hidden = true; next.hidden = true;
      const overflow = track.scrollWidth > track.clientWidth + 1;
      prev.hidden = !overflow; next.hidden = !overflow;
      if (overflow) { prev.disabled = track.scrollLeft <= 1; next.disabled = track.scrollLeft + track.clientWidth >= track.scrollWidth - 1; }
    };
    track.addEventListener('scroll', () => { if (!prev.hidden) { prev.disabled = track.scrollLeft <= 1; next.disabled = track.scrollLeft + track.clientWidth >= track.scrollWidth - 1; } });
    window.addEventListener('resize', update);
    update();
  });
}

function renderPanel() {
  const cur = routeOf(S.route);
  const groups = [['Кабинет', (r) => r.frame === 'cab'], ['Публичный сайт', (r) => r.frame === 'site']];
  $('#mkBody').innerHTML = groups.map(([g, f]) => `<h4>${g}</h4>${ROUTES.filter(f).map((r) => `<button class="${r.id === cur.id ? 'on' : ''}" data-route="${r.id}">${r.label}</button>`).join('')}`).join('')
    + (cur.demo ? `<h4>Состояние экрана</h4>${cur.demo.map(([v, l]) => `<button class="${S.demo[cur.id] === v ? 'on' : ''}" data-action="demo" data-v="${v}">${l}</button>`).join('')}` : '');
}

let holdTimer;
function startHold() {
  clearInterval(holdTimer);
  holdTimer = setInterval(() => {
    S.hold = Math.max(0, S.hold - 1);
    const el = $('#holdLeft'); if (!el) { clearInterval(holdTimer); return; }
    el.textContent = `${Math.floor(S.hold / 60)}:${String(S.hold % 60).padStart(2, '0')}`;
  }, 1000);
}

function toast(text) { const el = $('#mkToast'); el.textContent = text; el.classList.add('show'); clearTimeout(toast.t); toast.t = setTimeout(() => el.classList.remove('show'), 3200); }

/* ── События ────────────────────────────────────────────────────────── */
const NAV_ROUTE = { 'ex-student': 'student', 'ex-calendar': 'calendar', 'ex-session': 'session', 'ex-results': 'guests', 'ex-stats': 'stats', 'ex-payments': 'payments' };
const ACTIONS = {
  'pick-slot': (el) => { S.slot = +el.dataset.i; render(); },
  'scroll-pick': () => $('#pickCard')?.scrollIntoView({ behavior: 'smooth', block: 'center' }),
  'confirm-slot': () => { S.demo[S.route] = 'booked'; render(); toast('Вы записаны. Подтверждение — на экране; уведомление — в колокольчике.'); },
  'change-slot': () => { S.demo.student = 'booking'; render(); toast('Текущее место сохраняется, пока вы не подтвердите новое.'); },
  'cancel-slot': () => openConfirm({ title: 'Отменить запись?', lines: [`${SLOTS[S.slot].full}, ${SLOTS[S.slot].t} · ${SLOTS[S.slot].room}`, 'Место освободится. Записаться заново можно, пока есть места.'], ok: 'Отменить запись', onOk: () => { S.demo.student = 'booking'; render(); } }),
  'open-result': () => toast('Откроется экран «Результаты» — тот же, что в «Работах».'),
  'tabs-scroll': (el) => $('#slotTabs')?.scrollBy({ left: 300 * +el.dataset.dir, behavior: 'smooth' }),
  'slots-scroll': (el) => $('#mkSlots')?.scrollBy({ left: 240 * +el.dataset.dir, behavior: 'smooth' }),
  demo: (el) => { S.demo[S.route] = el.dataset.v; if (S.route === 'signup') S.hold = 19 * 60 + 42; render(); },
  'notif-role': (el) => { S.notifRole = el.dataset.v; render(); },
  'event-settings': () => eventSettings(S.demo.calendar === 'empty'),
  'save-event': () => { S.demo.calendar = 'filled'; render(); toast('Проведение сохранено. Добавьте сеансы и опубликуйте.'); },
  'new-session': () => toast('Сеанс добавляется перетаскиванием работы на дату или кнопкой — одна форма: время, кабинет, места.'),
  publish: () => toast('Публикация: ученики групп предмета получат уведомление.'),
  'open-session': () => { S.route = 'session'; location.hash = 'session'; },
  'add-src': () => { $('#srcRows').insertAdjacentHTML('beforeend', srcRow('', '9', '', 9)); },
  'copy-link': () => toast('Ссылка скопирована'),
  reissue: (el) => openConfirm({ title: `Перевыпустить ссылку для «${el.dataset.school || 'школы'}»?`, lines: ['Старая ссылка перестанет работать сразу, открытые по ней формы потеряют доступ.', 'Оплаченные записи и действующие брони сохранятся.', 'Новую ссылку нужно отправить школе заново.'], ok: 'Перевыпустить', onOk: () => { eventSettings(false); toast('Ссылка перевыпущена и скопирована'); } }),
  'row-menu': (el) => rowMenu(el, +el.dataset.i),
  arrived: () => { closeMenu(); toast('Приход отмечен (на неявку не влияет).'); },
  'issue-entry': () => { closeMenu(); toast('Ссылка на вход скопирована. Передайте участнику лично.'); },
  handed: () => { closeMenu(); toast('Отмечено: ссылка передана, 09:48.'); },
  transfer: () => { closeMenu(); resolvePick(); },
  extend: () => { closeMenu(); toast('Продление в минутах — с причиной и автором.'); },
  'cancel-reg': () => { closeMenu(); openConfirm({ title: 'Отменить запись участника?', lines: ['Участник освободит место.', 'Оплата гостя не меняется — вопрос возврата решает сотрудник вне WooCommerce.'], ok: 'Отменить запись', onOk: () => toast('Запись отменена') }); },
  'open-review': () => { closeMenu(); S.route = 'teacher-review'; location.hash = 'teacher-review'; },
  'add-guest': () => toast('Гость на месте проходит тот же платёжный поток: форма → бронь → оплата в WooCommerce.'),
  'approve-on': () => { S.demo.session = 'approve'; render(); },
  'approve-off': () => { S.demo.session = 'normal'; render(); },
  'approve-do': () => { S.demo.session = 'normal'; render(); toast('Утверждено: 1. Ученику уйдёт одно уведомление.'); },
  'approve-one': () => toast('Работа утверждена. Результат открыт ученику.'),
  'issue-result': () => toast('Личная ссылка результата скопирована. Передайте лично.'),
  'make-report': () => toast('Отчёт: выбор участников → срок → ссылка.'),
  'open-report': () => { S.route = 'report'; location.hash = 'report'; },
  'revoke-report': () => openConfirm({ title: 'Отозвать отчёт?', lines: ['Ссылка перестанет работать сразу.'], ok: 'Отозвать', onOk: () => toast('Отчёт отозван') }),
  'resolve-pick': () => resolvePick(),
  'resolve-refund': () => { closeMenu(); openPop(`<div class="gp-title">Отметка вне WooCommerce</div><label>Что сделано<select><option>Возврат выполнен вне WooCommerce</option><option>Перенесён на другой сеанс</option><option>Другое решение</option></select></label>
    <div class="mk-two"><label>Дата<input type="date" value="2026-10-13"></label><label>Сумма, ₽<input value="1500"></label></div><label>Комментарий<input placeholder="Например, возврат на карту"></label>
    <p class="mk-hint">Это только отметка. LMS не возвращает деньги и не меняет заказ WooCommerce.</p><div class="gp-row" style="justify-content:flex-end">${btn('Отмена', 'close', 'prof-btn-sm')}${btn('Сохранить отметку', 'close', 'prof-btn-sm prof-btn-primary')}</div>`); },
  'start-exam': () => toast('Откроется существующая станция КЕГЭ; таймер стартует только сейчас.'),
  'end-session': () => { toast('Сеанс завершён: доступ закрыт, кнопка «Назад» разбор не покажет.'); S.route = 'guest-entry'; location.hash = 'guest-entry'; },
  'report-open': (el) => { S.reportPerson = +el.dataset.i; render(); },
  'go-cart': () => toast('Дальше — существующая корзина WooCommerce (в макете не рисуется).'),
  'check-status': () => toast('Оплата пока не подтверждена. Не оплачивайте повторно.'),
  close: () => { closePops(); closeMenu(); },
  'confirm-ok': () => { const f = overlays()._onOk; closePops(); if (f) f(); },
  'toggle-solution': (el) => { const art = el.closest('.task-card-row'); const p = art.querySelector('.mk-solution'); p.hidden = !p.hidden; el.setAttribute('aria-expanded', String(!p.hidden)); el.textContent = p.hidden ? 'Показать решение' : 'Скрыть решение'; },
  jump: (el) => { const t = document.getElementById('t-' + el.dataset.n); if (t) t.scrollIntoView({ behavior: 'smooth', block: 'center' }); else toast('В макете показаны задания 1, 2, 3, 26 и 27.'); },
  noop: () => toast('Откроется существующий экран.'),
};
function resolvePick() {
  openPop(`<div class="gp-title">Выбрать сеанс для оплаченного гостя</div><p class="mk-hint">Оплата сохраняется, нового чекаута нет. Показаны только сеансы со свободными местами.</p>
    ${SLOTS.filter((s) => s.left > 0).map((s, i) => `<label style="flex-direction:row;align-items:center;gap:.5rem;color:var(--ink)"><input type="radio" name="slot" ${i === 0 ? 'checked' : ''}><span><b>${s.full}, ${s.t}</b> · ${s.room} · ${slotWord(s.left)}</span></label>`).join('')}
    <div class="gp-row" style="justify-content:flex-end">${btn('Отмена', 'close', 'prof-btn-sm')}${btn('Перенести', 'close', 'prof-btn-sm prof-btn-primary')}</div>`);
}

document.addEventListener('click', (e) => {
  const nav = e.target.closest('[data-go]');
  if (nav) { const r = NAV_ROUTE[nav.dataset.go]; if (r) { S.route = r; location.hash = r; } else toast('Остальные разделы кабинета в этом макете не рисуются.'); return; }
  const rt = e.target.closest('[data-route]');
  if (rt) { S.route = rt.dataset.route; location.hash = rt.dataset.route; $('#mkBody').hidden = true; return; }
  if (e.target.closest('#mkToggle')) { const b = $('#mkBody'); b.hidden = !b.hidden; return; }
  if (e.target.closest('#profBell') && S.route !== 'notifications') {
    const pop = $('#profNotifPop'); const role = routeOf(S.route).role === 'office' ? 'office' : routeOf(S.route).role === 'teacher' ? 'teacher' : 'student';
    pop.innerHTML = `<div class="prof-notif-head"><span>Уведомления</span><button type="button" class="prof-notif-readall">Прочитать все</button></div><div class="prof-notif-list">${NOTIF[role].slice(0, 5).map(notifItem).join('')}</div>`;
    pop.hidden = !pop.hidden; return;
  }
  const act = e.target.closest('[data-action]');
  if (act && ACTIONS[act.dataset.action]) { e.preventDefault(); ACTIONS[act.dataset.action](act); }
  const pop = $('#profNotifPop'); if (pop && !pop.hidden && !e.target.closest('#profNotifPop')) pop.hidden = true;
  if (e.target.closest('.prof-notif-item')) { e.preventDefault(); toast('Уведомление целиком — ссылка на нужный экран.'); }
});
document.addEventListener('submit', (e) => {
  if (e.target.id === 'guestForm') { e.preventDefault(); S.demo.signup = 'hold'; S.hold = 19 * 60 + 42; render(); toast('Место закреплено на 20 минут. Дальше — корзина WooCommerce.'); }
});
window.addEventListener('hashchange', () => { S.route = location.hash.slice(1) || 'student'; render(); });
render();
