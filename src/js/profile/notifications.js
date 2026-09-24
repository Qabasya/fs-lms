/* ══════════════════════════════════════════════════════════════════════
   Колокольчик уведомлений кабинета — поповер с плитками, badge-поллинг,
   браузерные уведомления: новая плитка во время поллинга — тост, если
   вкладка на виду, иначе системное уведомление (Notification API, по
   разрешению пользователя из поповера).
   Источник: window.fsProfile.notifications:{nonce,actions}. Своя dropdown-
   панель (#profNotifPop), НЕ переиспользует общий prof-ctx-menu (там
   выпадашки select-подобные, тут — отдельная лента с собственной шириной
   и скроллом). Тексты плиток (title/body) уже собраны сервером
   (NotificationService::toClientArray) — клиент выбирает только иконку
   по `type` и цвет по `tone`.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast } from './utils.js';
import { icoBell, icoCamera, icoClock, icoAlert, icoCheck, icoReplace, icoDocCheck, icoSwap, icoEye, icoMapPin, icoCalendarBoard, icoUsers, icoJournal } from '../common/icons.js';
import { createApi } from './api.js';

const POLL_MS = 60000;

/** type → фабрика иконки плитки (только существующие фабрики common/icons.js). */
const TYPE_ICON = {
    video_uploaded:      icoCamera,
    deadline_soon:       icoClock,
    deadline_missed:     icoAlert,
    lesson_soon:         icoClock,
    work_graded:         icoCheck,
    work_returned:       icoReplace,
    attempt_graded:      icoDocCheck,
    review_needed:       icoDocCheck,
    substitute_assigned: icoSwap,
    attendance_missed:   icoAlert,
    lesson_opened:       icoEye,
    substitute_assigned_student: icoSwap,
    room_changed:        icoMapPin,
    attempt_reset:       icoReplace,
    homework_submitted:  icoDocCheck,
    program_updated:     icoCalendarBoard,
    student_joined:      icoUsers,
    journal_not_filled:  icoJournal,
    absence_streak:      icoAlert,
};

/* Последнее уведомление, уже показанное в браузере. Общее для всех вкладок
   (localStorage): новую плитку показывает та вкладка, что опросила сервер
   первой, — без дублей по числу открытых вкладок. */
const LAST_ID_KEY = 'fsLmsNotifLastId';

function readLastId() {
    try { return Number(window.localStorage.getItem(LAST_ID_KEY)) || 0; } catch { return 0; }
}

function writeLastId(id) {
    try { window.localStorage.setItem(LAST_ID_KEY, String(id)); } catch { /* приватный режим — без памяти */ }
}

function browserNotificationsSupported() {
    return 'Notification' in window;
}

let api = null;
let isOpen = false;

export function initNotifications() {
    const bell = document.getElementById('profBell');
    const cfg = window.fsProfile && window.fsProfile.notifications;
    if (!bell || !cfg) { return; }

    api = createApi(cfg);

    refreshCount();
    // Опрос идёт и в фоновой вкладке: иначе системное уведомление не придёт,
    // пока пользователь на другой вкладке (браузер сам разрежает фоновые таймеры).
    setInterval(refreshCount, POLL_MS);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) { refreshCount(); }
    });

    bell.addEventListener('click', (e) => {
        e.stopPropagation();
        isOpen ? closePop() : openPop();
    });

    document.addEventListener('click', (e) => {
        if (!isOpen) { return; }
        const pop = document.getElementById('profNotifPop');
        if (pop && !pop.contains(e.target) && !bell.contains(e.target)) { closePop(); }
    });
    document.addEventListener('keydown', (e) => {
        if (isOpen && 'Escape' === e.key) { closePop(); }
    });
}

/* ── Badge ─────────────────────────────────────────────────────────────── */

function setBadge(count) {
    const badge = document.getElementById('profBellBadge');
    if (!badge) { return; }
    if (count > 0) {
        badge.textContent = count > 9 ? '9+' : String(count);
        badge.hidden = false;
    } else {
        badge.hidden = true;
    }
}

async function refreshCount() {
    try {
        const lastId = readLastId();
        const data = await api('count', lastId ? { after: lastId } : {});
        setBadge(Number(data?.unseen) || 0);

        const fresh = Array.isArray(data?.fresh) ? data.fresh : [];
        const latestId = Number(data?.latest_id) || 0;
        // Другая вкладка могла успеть показать эти же плитки, пока шёл запрос.
        if (readLastId() !== lastId) { return; }
        writeLastId(Math.max(lastId, latestId));
        if (lastId) { fresh.forEach(announce); }
    } catch {
        /* фоновый поллинг — тихо, тост не нужен */
    }
}

/** Новая плитка: вкладка на виду — тост, в фоне — системное уведомление браузера. */
function announce(n) {
    const text = n.body ? `${n.title}: ${n.body}` : n.title;
    if (!document.hidden) {
        toast(text, 'error' === n.tone ? 'error' : 'ok');
        return;
    }
    if (!browserNotificationsSupported() || 'granted' !== Notification.permission) { return; }

    const note = new Notification(n.title || 'Уведомление', { body: n.body || '', tag: `fs-lms-${n.id}` });
    note.onclick = () => {
        window.focus();
        api('markRead', { id: n.id }).catch(() => {});
        if (n.url) { window.location.href = n.url; }
        note.close();
    };
}

/* ── Поповер ───────────────────────────────────────────────────────────── */

function closePop() {
    isOpen = false;
    const pop = document.getElementById('profNotifPop');
    if (pop) { pop.hidden = true; }
}

async function openPop() {
    const pop = document.getElementById('profNotifPop');
    if (!pop) { return; }

    isOpen = true;
    pop.hidden = false;
    pop.innerHTML = '<div class="prof-notif-loading">Загрузка…</div>';

    try {
        const data = await api('list');
        // Открытие само гасит seen на сервере (ajaxGetNotifications) — гасим badge
        // здесь же, не дожидаясь следующего поллинга.
        setBadge(0);
        renderList(pop, Array.isArray(data?.items) ? data.items : []);
    } catch (e) {
        pop.innerHTML = `<div class="prof-notif-loading">${esc(e.message)}</div>`;
    }
}

/* Разрешение на системные уведомления спрашиваем только по клику — браузеры
   блокируют запрос без жеста пользователя. Решение принято (granted/denied) —
   плашки нет. */
function permissionPromptHtml() {
    if (!browserNotificationsSupported() || 'default' !== Notification.permission) { return ''; }
    return '<div class="prof-notif-permission">'
        + '<span>Показывать уведомления, даже когда кабинет в другой вкладке?</span>'
        + '<button type="button" class="prof-notif-allow">Включить</button></div>';
}

function wirePermissionPrompt(pop) {
    const btn = pop.querySelector('.prof-notif-allow');
    if (!btn) { return; }
    btn.addEventListener('click', async () => {
        const result = await Notification.requestPermission();
        pop.querySelector('.prof-notif-permission')?.remove();
        if ('granted' === result) { toast('Уведомления в браузере включены'); }
    });
}

function renderList(pop, items) {
    if (!items.length) {
        pop.innerHTML = `${permissionPromptHtml()}<div class="prof-notif-empty">${icoBell(32)}<p>Пока нет уведомлений</p></div>`;
        wirePermissionPrompt(pop);
        return;
    }

    let html = '<div class="prof-notif-head"><span>Уведомления</span>'
        + '<button type="button" class="prof-notif-readall">Прочитать все</button></div>';
    html += permissionPromptHtml();
    html += '<div class="prof-notif-list">';
    for (const [label, group] of groupByDay(items)) {
        html += `<div class="prof-notif-section">${esc(label)}</div>${group.map(tileHtml).join('')}`;
    }
    html += '</div>';
    pop.innerHTML = html;

    wirePermissionPrompt(pop);

    const readAllBtn = pop.querySelector('.prof-notif-readall');
    if (readAllBtn) { readAllBtn.addEventListener('click', () => markAllRead(pop)); }

    pop.querySelectorAll('.prof-notif-item').forEach((el) => {
        el.addEventListener('click', () => markOneRead(Number(el.dataset.id), el));
    });
}

function tileHtml(n) {
    const icoFn = TYPE_ICON[n.type] || icoBell;
    return `<a class="prof-notif-item" href="${esc(n.url || '#')}" data-id="${Number(n.id)}" data-tone="${esc(n.tone || 'info')}">
        <span class="ni-ico">${icoFn(18)}</span>
        <span class="ni-body">
            <span class="ni-title">${n.unread ? '<span class="ni-dot"></span>' : ''}${esc(n.title || '')}</span>
            ${n.body ? `<span class="ni-sub">${esc(n.body)}</span>` : ''}
        </span>
        <span class="ni-time">${esc(timeAgo(n.time))}</span>
    </a>`;
}

/** Группировка «Сегодня / Вчера / Ранее» по локальному дню (n.time — 'Y-m-d H:i:s' сайта). */
function groupByDay(items) {
    const dayStart = (d) => { const c = new Date(d); c.setHours(0, 0, 0, 0); return c.getTime(); };
    const today = dayStart(new Date());
    const yesterday = today - 86400000;

    const buckets = new Map([['Сегодня', []], ['Вчера', []], ['Ранее', []]]);
    items.forEach((n) => {
        const t = dayStart(new Date(String(n.time).replace(' ', 'T')));
        const key = t === today ? 'Сегодня' : (t === yesterday ? 'Вчера' : 'Ранее');
        buckets.get(key).push(n);
    });

    return [...buckets].filter(([, group]) => group.length);
}

/** Относительное время: 'сейчас' / '{n} мин' / '{n} ч' / '{n} дн'. */
function timeAgo(mysql) {
    const then = new Date(String(mysql).replace(' ', 'T')).getTime();
    const minutes = Math.max(0, Math.round((Date.now() - then) / 60000));
    if (minutes < 1) { return 'сейчас'; }
    if (minutes < 60) { return `${minutes} мин`; }
    const hours = Math.round(minutes / 60);
    if (hours < 24) { return `${hours} ч`; }
    return `${Math.round(hours / 24)} дн`;
}

/* ── Действия ──────────────────────────────────────────────────────────── */

function markOneRead(id, el) {
    const dot = el.querySelector('.ni-dot');
    if (dot) { dot.remove(); }
    // Клик по плитке — обычная ссылка (переход по n.url), запрос — fire-and-forget.
    api('markRead', { id }).catch(() => {});
}

async function markAllRead(pop) {
    pop.querySelectorAll('.ni-dot').forEach((dot) => dot.remove());
    try {
        await api('markAllRead');
    } catch (e) {
        toast(e.message, 'error');
    }
}
