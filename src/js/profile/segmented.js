/* ══════════════════════════════════════════════════════════════════════
   Единый переключатель кабинета (.prof-seg): активная «таблетка» не
   перескакивает, а плавно переезжает на новую кнопку.

   Разметка экранов не меняется: контейнер .prof-seg, кнопки внутри, у активной
   класс `on` (или aria-selected="true"). Один MutationObserver на документ
   подхватывает и новые переключатели (экраны перерисовывают innerHTML), и
   смену активной кнопки. Прежнее положение таблетки помнится по ключу
   переключателя, поэтому анимация идёт и через перерисовку экрана.
   ══════════════════════════════════════════════════════════════════════ */

const ACTIVE = 'button.on, button[aria-selected="true"]';

/**
 * Положение таблетки по ключу переключателя: переживает перерисовку экрана.
 * `from` — откуда начался переезд, пока он идёт: экран, перерисованный дважды подряд,
 * должен стартовать с исходной точки, а не с уже назначенной цели.
 */
const lastPos = new Map();
const MOVE_MS = 300;
const thumbs  = new WeakMap();

/** Кривая cubic-bezier(0.4, 0, 0.2, 1) из стилей таблетки — прогресс по времени → доля пути. */
function easeOut(time) {
    const bez = (t, p1, p2) => 3 * (1 - t) ** 2 * t * p1 + 3 * (1 - t) * t ** 2 * p2 + t ** 3;
    let t = time;
    for (let i = 0; i < 8; i++) {
        const dx = 3 * (1 - t) ** 2 * 0.4 + 6 * (1 - t) * t * (0.2 - 0.4) + 3 * t ** 2 * (1 - 0.2);
        const err = bez(t, 0.4, 0.2) - time;
        if (Math.abs(err) < 1e-4 || 0 === dx) break;
        t -= err / dx;
    }
    return bez(t, 0, 1);
}

/**
 * Где таблетка видна сейчас. Переезд ещё идёт (экран перерисован повторно) — берём точку на
 * пути от исходной к цели (сглаженный прогресс), чтобы новая таблетка не откатывалась назад.
 */
function currentPos(entry) {
    if (!entry || !entry.from) return entry;
    const progress = (Date.now() - entry.t) / MOVE_MS;
    if (progress >= 1) return entry;
    const eased = easeOut( progress );
    return {
        x: entry.from.x + (entry.x - entry.from.x) * eased,
        w: entry.from.w + (entry.w - entry.from.w) * eased,
    };
}

/**
 * Стабильный между перерисовками ключ: экран (`.prof-screen`) + подпись + значения data-атрибутов
 * кнопок. Порядковый номер в документе не годится — «плывёт» по мере монтирования экранов.
 */
function keyOf(seg) {
    const screen = seg.closest('.prof-screen')?.dataset.screen ?? '';
    const own    = seg.id || seg.dataset.segKey || seg.getAttribute('aria-label') || '';
    const items  = [...seg.querySelectorAll('button')].map((btn) => Object.values(btn.dataset).join(':'));
    return `${screen}>${own}>${items.join(',')}`;
}

/** Ставит таблетку под активную кнопку; false — места ещё нет (экран скрыт). */
function place(seg, thumb, key) {
    const active = seg.querySelector(ACTIVE);
    seg.classList.toggle('has-thumb', !!active);
    if (!active || 0 === active.offsetWidth) return false;

    thumb.style.setProperty('--seg-x', `${active.offsetLeft}px`);
    thumb.style.setProperty('--seg-w', `${active.offsetWidth}px`);

    const entry = lastPos.get(key);
    const now   = Date.now();
    if (!entry || entry.x !== active.offsetLeft || entry.w !== active.offsetWidth) {
        const inFlight = entry && entry.from && now - entry.t < MOVE_MS;
        const from     = entry ? (inFlight ? entry.from : { x: entry.x, w: entry.w }) : null;
        lastPos.set(key, { x: active.offsetLeft, w: active.offsetWidth, from, t: now });
    }
    return true;
}

/** Подключает переключатель: таблетка + наблюдение за размером. Повторный вызов безвреден. */
export function enhanceSegmented(seg) {
    if (thumbs.has(seg)) return;

    const thumb = document.createElement('span');
    thumb.className = 'prof-seg__thumb';
    thumb.setAttribute('aria-hidden', 'true');
    seg.prepend(thumb);
    thumbs.set(seg, thumb);

    const key   = keyOf(seg);
    const saved = lastPos.get(key);
    const prev  = currentPos(saved);

    // Первая постановка без анимации; если переключатель уже был на экране до перерисовки —
    // стартуем с его прежнего места и переезжаем на новое.
    thumb.classList.add('is-instant');
    if (prev) {
        thumb.style.setProperty('--seg-x', `${prev.x}px`);
        thumb.style.setProperty('--seg-w', `${prev.w}px`);
        seg.classList.add('has-thumb');
    }
    void thumb.offsetWidth;
    if (!prev) {
        place(seg, thumb, key);
        void thumb.offsetWidth;
        thumb.classList.remove('is-instant');
    } else {
        thumb.classList.remove('is-instant');
        place(seg, thumb, key);
    }

    if ('undefined' !== typeof ResizeObserver) {
        // Ширина кнопок меняется со шрифтом/экраном; скрытый экран получает размер при показе.
        // Первый вызов ResizeObserver приходит сразу после observe() — он не изменение и
        // не должен перебивать плавный переезд, поэтому реагируем только на смену ширины.
        let seen = null;
        new ResizeObserver(() => {
            const width = seg.offsetWidth;
            if (null === seen || width === seen) {
                seen = width;
                return;
            }
            seen = width;
            thumb.classList.add('is-instant');
            place(seg, thumb, key);
            void thumb.offsetWidth;
            thumb.classList.remove('is-instant');
        }).observe(seg);
    }
}

/** Подхватывает все переключатели внутри root — сейчас и по мере появления. */
export function initSegmented(root = document.body) {
    const enhanceAll = (node) => {
        if (node.nodeType !== 1) return;
        if (node.matches('.prof-seg')) enhanceSegmented(node);
        node.querySelectorAll?.('.prof-seg').forEach(enhanceSegmented);
    };

    enhanceAll(root);

    new MutationObserver((mutations) => {
        const changed = new Set();
        mutations.forEach((m) => {
            if ('childList' === m.type) {
                m.addedNodes.forEach(enhanceAll);
                return;
            }
            const seg = m.target.closest?.('.prof-seg');
            if (seg) changed.add(seg);
        });
        changed.forEach((seg) => {
            const thumb = thumbs.get(seg);
            if (thumb) place(seg, thumb, keyOf(seg));
        });
    }).observe(root, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['class', 'aria-selected'],
    });
}
