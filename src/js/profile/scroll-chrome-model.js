/* ══════════════════════════════════════════════════════════════════════
   Липкая шапка и «Наверх» экранов кабинета: чистые вычисления состояния
   (без DOM — проверяются в tests/js). Саму подписку и классы ведёт
   scroll-chrome.js.
   ══════════════════════════════════════════════════════════════════════ */

import { shouldShowToTop } from '../common/scroll-to-top.js';

/** Ближе этого к началу экрана шапка всегда на месте, px. */
export const TUCK_AFTER = 80;

/** Минимальный сдвиг прокрутки, который считается направлением (дрожание колеса не в счёт), px. */
export const DIRECTION_DELTA = 6;

/**
 * Следующее состояние «хрома» экрана по новой позиции прокрутки.
 *
 * Шапка уезжает вверх при прокрутке вниз и возвращается при прокрутке вверх —
 * как липкая шапка сайта.
 *
 * @param {{top:number, tucked:boolean}} prev     Прошлая позиция и состояние шапки.
 * @param {number}                       top      Текущая прокрутка экрана, px.
 * @param {number}                       viewport Высота экрана, px.
 * @returns {{top:number, tucked:boolean, scrolled:boolean, showTop:boolean}}
 */
export function nextChrome(prev, top, viewport) {
    let tucked = prev.tucked;

    if (top <= TUCK_AFTER) {
        tucked = false;
    } else if (top - prev.top > DIRECTION_DELTA) {
        tucked = true;
    } else if (prev.top - top > DIRECTION_DELTA) {
        tucked = false;
    }

    return {
        top: Math.max(0, top),
        tucked,
        scrolled: top > 0,
        showTop: shouldShowToTop(top, viewport),
    };
}
