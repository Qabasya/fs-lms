import { debounce } from '../../common/utils.js';

/**
 * Сворачивание длинных условий в карточках «Всех заданий».
 *
 * Условие длиннее CLAMP_AFTER_LINES строк обрезается до видимых строк
 * (высоту задаёт CSS, класс is-clamped), по клику разворачивается целиком.
 * CSS сам решить не может: длину текста видно только после раскладки.
 *
 * Считаем строки как «полная высота текста ÷ интерлиньяж», а не сравниваем
 * clientHeight со scrollHeight: у обрезки есть переход, и в момент замера
 * высота бокса ещё едет — из-за этого раскрытая карточка схлопывалась при
 * любом ресайзе (в том числе при открытии DevTools). scrollHeight от
 * анимации не зависит.
 *
 * Стили — только классами; в разметку уходит одна величина, которую CSS
 * не вычислит, — полная высота текста для анимации (--tcr-full).
 */

/** Порог: условие короче — не сворачиваем вовсе. */
const CLAMP_AFTER_LINES = 12;

const CLAMPED  = 'is-clamped';
const EXPANDED = 'is-expanded';

/** Глобальные слушатели вешаются один раз: модуль зовут после каждой дорисовки. */
let globalsBound = false;

/**
 * Навешивает сворачивание на карточки внутри контейнера.
 *
 * @param {Element} container - Корень списка карточек (.js-task-cards).
 *
 * @returns {void}
 */
export function initTaskConditions(container) {
    if (!container) return;

    container.querySelectorAll('.js-condition').forEach(body => {
        if (body._condBound) return;
        body._condBound = true;

        measure(body);
        bindImages(body);
        body.addEventListener('click', e => onBodyClick(body, e));
    });

    bindGlobals();
}

/** Пересчитывает все условия страницы (ресайз, догрузка шрифтов/картинок). */
function measureAll() {
    document.querySelectorAll('.js-condition').forEach(measure);
}

function bindGlobals() {
    if (globalsBound) return;
    globalsBound = true;

    // Число строк зависит от ширины: после ресайза условие может перестать
    // переполняться — или начать.
    window.addEventListener('resize', debounce(measureAll, 150));

    // Шрифты и отложенные картинки меняют высоту уже после первого замера.
    window.addEventListener('load', measureAll);
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(measureAll).catch(() => {});
    }
}

/**
 * Картинка внутри условия догружается после замера и меняет высоту —
 * пересчитываем это условие, когда она встанет на место.
 *
 * @param {Element} body - Блок условия (.js-condition).
 *
 * @returns {void}
 */
function bindImages(body) {
    body.querySelectorAll('img').forEach(img => {
        if (img.complete) return;

        const recheck = () => measure(body);
        img.addEventListener('load', recheck, { once: true });
        img.addEventListener('error', recheck, { once: true });
    });
}

/**
 * Клик по свёрнутому условию разворачивает его; свернуть обратно можно
 * только кнопкой — иначе карточка схлопывалась бы при выделении текста.
 *
 * @param {Element} body - Блок условия (.js-condition).
 * @param {MouseEvent} e - Событие клика.
 *
 * @returns {void}
 */
function onBodyClick(body, e) {
    const toggle = body.querySelector('.js-condition-toggle');
    if (!toggle || toggle.hidden) return;

    if (e.target.closest('.js-condition-toggle')) {
        setExpanded(body, body.classList.contains(CLAMPED));
        return;
    }

    // Ссылка внутри условия ведёт по адресу, а не разворачивает карточку.
    if (e.target.closest('a')) return;

    if (!body.classList.contains(CLAMPED)) return;
    if (String(window.getSelection())) return;

    setExpanded(body, true);
}

/**
 * Меряет условие и приводит блок в согласованное состояние: короткое —
 * без обрезки и кнопки, длинное — свёрнуто (или остаётся раскрытым, если
 * пользователь уже его раскрыл).
 *
 * @param {Element} body - Блок условия (.js-condition).
 *
 * @returns {void}
 */
function measure(body) {
    const text   = body.querySelector('.tcr-condition');
    const toggle = body.querySelector('.js-condition-toggle');
    if (!text || !toggle) return;

    // scrollHeight — высота содержимого целиком, независимо от текущей обрезки
    // и от идущего перехода.
    const full  = text.scrollHeight;
    const style = getComputedStyle(text);
    const lineHeight = parseFloat(style.lineHeight)
        || parseFloat(style.fontSize) * 1.5
        || 0;

    if (!full || !lineHeight) return;

    body.style.setProperty('--tcr-full', `${full}px`);

    // Полстроки допуска: дробная высота от картинок и подстрочных индексов
    // не должна включать сворачивание у ровно-пороговых условий.
    const clampable = full / lineHeight > CLAMP_AFTER_LINES + 0.5;

    toggle.hidden = !clampable;

    if (!clampable) {
        body.classList.remove(CLAMPED, EXPANDED);
        return;
    }

    // Уже раскрытое пользователем условие ресайз не сворачивает.
    if (body.classList.contains(EXPANDED)) return;

    setExpanded(body, false);
}

/**
 * Переключает состояние блока и подпись кнопки.
 *
 * @param {Element} body   - Блок условия (.js-condition).
 * @param {boolean} expand - Развернуть (true) или свернуть (false).
 *
 * @returns {void}
 */
function setExpanded(body, expand) {
    const toggle = body.querySelector('.js-condition-toggle');

    body.classList.toggle(CLAMPED, !expand);
    body.classList.toggle(EXPANDED, expand);

    if (!toggle) return;

    const label = toggle.querySelector('.js-condition-toggle-label');
    if (label) label.textContent = expand ? 'Свернуть' : 'Показать полностью';

    toggle.setAttribute('aria-expanded', String(expand));
}
