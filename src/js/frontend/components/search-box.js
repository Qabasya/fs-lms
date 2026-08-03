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
    if (!toggle || !input) return;

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

    input.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;

        // Событие input — чтобы AllTasksPage сбросил поисковый фильтр.
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.blur();
        close();
    });

    document.addEventListener('click', e => {
        if (!box.contains(e.target)) close();
    });
}