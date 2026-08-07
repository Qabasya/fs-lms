/**
 * Навигация по серии статей: подгонка описания под высоту заголовка.
 *
 * Блок текста карточки перехода занимает ровно три строки. Сколько из них
 * заберёт заголовок, знает только раскладка, поэтому клэмп описания ставится
 * здесь: заголовок в две строки — описание в одну, иначе в две. Без этого
 * лишняя строка описания просто срезалась бы границей блока, без многоточия.
 *
 * UI-only, без AJAX.
 */

import { debounce } from '../../common/utils.js';

/** Класс карточки, у которой заголовок занял обе строки. */
const TIGHT_CLASS = 'fs-article-nav__row--tight';

/** Задержка пересчёта при изменении ширины окна, мс. */
const RESIZE_DELAY = 150;

/** Со скольких строк заголовок считается двустрочным (высота / высота строки). */
const TWO_LINES_FACTOR = 1.5;

/**
 * @returns {void}
 */
export function initArticleNav() {
    const rows = document.querySelectorAll('.fs-article-nav__row');
    if (!rows.length) return;

    const sync = () => {
        rows.forEach((row) => {
            const title = row.querySelector('.fs-article-nav__title');
            if (!title) return;

            // Снимаем класс до замера: полоса могла стать шире, и заголовок,
            // который раньше переносился, теперь укладывается в строку.
            row.classList.remove(TIGHT_CLASS);

            const lineHeight = parseFloat(getComputedStyle(title).lineHeight);
            if (!lineHeight) return;

            if (title.getBoundingClientRect().height > lineHeight * TWO_LINES_FACTOR) {
                row.classList.add(TIGHT_CLASS);
            }
        });
    };

    sync();

    // Шрифт подгружается после разметки и меняет переносы — пересчитываем.
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(sync);
    }

    window.addEventListener('resize', debounce(sync, RESIZE_DELAY));
}