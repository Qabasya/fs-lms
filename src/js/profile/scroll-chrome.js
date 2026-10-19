/* ══════════════════════════════════════════════════════════════════════
   Липкая шапка и «Наверх» в SPA кабинета.

   Прокручивается не окно, а активный `.prof-screen`: прокрутка вложенных
   элементов не всплывает, поэтому слушаем `#profStage` в фазе перехвата.
   Состояние — классами на экране (JS стили не задаёт): `is-scrolled` (шапка
   «приподнята» тенью) и `is-chrome-tucked` (шапка уехала при прокрутке вниз).
   Кнопка «Наверх» — одна на всю сцену, вид — миксин shared/to-top-button.
   Экраны с собственной внутренней прокруткой (журнал, КТП) не затрагиваем.
   ══════════════════════════════════════════════════════════════════════ */

import { onScrollFrame } from '../common/scroll-frame.js';
import { scrollToTop } from '../common/scroll-to-top.js';
import { icoChevronDown } from '../common/icons.js';
import { nextChrome } from './scroll-chrome-model.js';

let stage = null;
let btn   = null;
let state = { top: 0, tucked: false };

function activeScreen() {
    return stage ? stage.querySelector('.prof-screen.active') : null;
}

/** Применяет состояние к активному экрану и кнопке. */
function apply(screen, next) {
    state = { top: next.top, tucked: next.tucked };
    screen.classList.toggle('is-scrolled', next.scrolled);
    screen.classList.toggle('is-chrome-tucked', next.tucked);
    btn.hidden = !next.showTop;
}

function sync() {
    const screen = activeScreen();
    if (!screen || !btn) { return; }

    apply(screen, nextChrome(state, screen.scrollTop, screen.clientHeight));
}

/**
 * Вызывается при смене экрана (`go()` в app.js): у нового экрана прокрутка начинается с нуля.
 *
 * @returns {void}
 */
export function resetScrollChrome() {
    state = { top: 0, tucked: false };
    stage?.querySelectorAll('.prof-screen.is-scrolled, .prof-screen.is-chrome-tucked')
        .forEach(s => s.classList.remove('is-scrolled', 'is-chrome-tucked'));
    if (btn) { btn.hidden = true; }
    sync();
}

/**
 * Монтирует кнопку «Наверх» и подписку на прокрутку экранов. Вызывается один раз после `buildStage()`.
 *
 * @returns {void}
 */
export function initScrollChrome() {
    stage = document.getElementById('profStage');
    if (!stage || btn) { return; }

    btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'prof-to-top';
    btn.hidden = true;
    btn.setAttribute('aria-label', 'Наверх');
    btn.innerHTML = icoChevronDown(18);
    btn.addEventListener('click', () => {
        const screen = activeScreen();
        if (screen) { scrollToTop(screen); }
    });
    stage.appendChild(btn);

    onScrollFrame(sync, stage, true);
}
