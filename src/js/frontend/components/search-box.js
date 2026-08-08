/**
 * Свёрнутый поиск в строке крошек на «Всех заданиях».
 *
 * UI-only, без AJAX: сам запрос отправляет AllTasksPage по событию input того
 * же поля. Здесь только состояния — покой (лупа), ховер (подпись), раскрытое
 * поле по клику.
 *
 * @returns {void}
 */
export function initSearchBox() {
    const box = document.querySelector('.js-search');
    if (!box) return;

    const toggle = box.querySelector('.js-search-toggle');
    const input  = box.querySelector('.js-search-input');
    const clear  = box.querySelector('.js-search-clear');
    if (!toggle || !input) return;

    // Крестик очистки — свой, родной у input[type=search] скрыт стилями.
    const syncClear = () => {
        if (clear) clear.hidden = input.value === '';
    };

    const reset = () => {
        input.value = '';
        // Событие input — чтобы AllTasksPage сбросил поисковый фильтр.
        input.dispatchEvent(new Event('input', { bubbles: true }));
        syncClear();
    };

    const open = () => {
        box.classList.add('is-open');
        toggle.setAttribute('aria-expanded', 'true');
        input.focus();
    };

    // С непустым запросом поле остаётся раскрытым: иначе набранный текст
    // пропадал бы из виду, а список оставался отфильтрованным.
    const close = () => {
        if (input.value.trim()) return;

        box.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
    };

    toggle.addEventListener('click', () => {
        if (box.classList.contains('is-open')) {
            close();
        } else {
            open();
        }
    });

    input.addEventListener('input', syncClear);

    input.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;

        reset();
        input.blur();
        close();
    });

    if (clear) {
        clear.addEventListener('click', () => {
            reset();
            input.focus();
        });
    }

    syncClear();

    document.addEventListener('click', e => {
        if (!box.contains(e.target)) close();
    });
}