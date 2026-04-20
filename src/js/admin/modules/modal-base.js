const $ = jQuery;

/**
 * Открывает модальное окно с анимацией.
 * Фиксирует скролл страницы и управляет CSS-переменной для компенсации ширины скроллбара.
 * @param {JQuery} $modal - jQuery-объект модального окна
 */
export function openModal($modal) {
    // Компенсируем исчезновение скроллбара, чтобы контент не "прыгал"
    const scrollBarWidth = window.innerWidth - document.documentElement.clientWidth;
    document.documentElement.style.setProperty('--scrollbar-width', scrollBarWidth + 'px');

    $('html').addClass('modal-open');
    $modal.removeClass('hidden');

    // Force reflow: гарантируем, что браузер применит стили перед запуском транзишна
    void $modal[0].offsetHeight;

    $modal.addClass('active');
}

/**
 * Закрывает модальное окно с анимацией.
 * @param {JQuery} $modal - jQuery-объект модального окна
 * @param {Function} [callback] - Функция, вызываемая после завершения анимации
 */
export function closeModal($modal, callback) {
    $modal.removeClass('active');

    // Таймаут должен совпадать с длительностью CSS transition (transition-duration)
    setTimeout(() => {
        $modal.addClass('hidden');
        $('html').removeClass('modal-open');
        document.documentElement.style.removeProperty('--scrollbar-width');

        if (typeof callback === 'function') callback();
    }, 200);
}

/**
 * Вешает обработчик нажатия клавиши Escape с изоляцией через неймспейс.
 * @param {string} ns - Уникальный неймспейс события (например, 'confirm', 'task_creation')
 * @param {Function} fn - Функция, вызываемая при нажатии Escape
 */
export function bindEsc(ns, fn) {
    $(document).on('keydown.modal_' + ns, (e) => {
        if (e.key === 'Escape') fn();
    });
}

/**
 * Отвязывает обработчик Escape по указанному неймспейсу.
 * @param {string} ns - Неймспейс, переданный в bindEsc
 */
export function unbindEsc(ns) {
    $(document).off('keydown.modal_' + ns);
}