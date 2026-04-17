/**
 * @fileoverview Модальное окно для выбора предмета в плагине WordPress.
 * @description Обеспечивает функционал открытия/закрытия модального окна с анимацией.
 * @requires jQuery - глобальная зависимость WordPress (обычно доступна как $).
 */

// Импортируем jQuery из глобальной области WordPress
const $ = jQuery;

/**
 * Объект для управления модальным окном предметов.
 * @namespace SubjectModal
 * @typedef {Object} SubjectModal
 * @property {jQuery|null} $modal - jQuery-элемент модального окна.
 */
export const SubjectModal = {
    /**
     * Инициализирует модальное окно: проверяет наличие DOM-элемента,
     * навешивает обработчики событий на открытие/закрытие.
     * @memberof SubjectModal
     * @instance
     * @listens click#open-subject-modal - Открытие окна по кнопке
     * @listens click#fs-close - Закрытие окна по кнопке закрытия
     * @listens click#window - Закрытие окна по клику на фон (overlay)
     * @returns {void}
     * @example
     * // Инициализация после загрузки DOM
     * jQuery(document).ready(() => {
     *     SubjectModal.init();
     * });
     */
    init() {
        // Кэшируем jQuery-объект модального окна для повторного использования
        this.$modal = $('#fs-subject-modal');

        // Если модальное окно отсутствует в DOM — прекращаем инициализацию
        if (!this.$modal.length) {
            // В продакшен-версии можно добавить логирование ошибки
            // console.warn('[SubjectModal] Элемент #fs-subject-modal не найден в DOM.');
            return;
        }

        // Обработчик клика по элементу, открывающему модальное окно
        $('#open-subject-modal').on('click', () => this.open());

        // Обработчик клика по кнопке закрытия внутри модального окна
        this.$modal.on('click', '.fs-close', () => this.close());

        // Закрытие окна при клике на затемнённый фон (overlay)
        $(window).on('click', (e) => {
            // Проверяем, что клик был именно по фону модального окна, а не по его содержимому
            if ($(e.target).is(this.$modal)) {
                this.close();
            }
        });
    },

    /**
     * Открывает модальное окно с плавным появлением.
     * @memberof SubjectModal
     * @instance
     * @fires fadeIn - jQuery-анимация появления
     * @returns {void}
     * @throws {Error} Если this.$modal не инициализирован или не является jQuery-объектом.
     * @example
     * // Программное открытие окна
     * SubjectModal.open();
     */
    open() {
        // Проверяем, что объект модального окна существует
        if (!this.$modal || !this.$modal.jquery) {
            console.error('[SubjectModal] Ошибка: $modal не инициализирован.');
            return;
        }

        // Плавное появление окна за 200 мс
        this.$modal.fadeIn(200);
    },

    /**
     * Закрывает модальное окно с плавным исчезновением.
     * @memberof SubjectModal
     * @instance
     * @fires fadeOut - jQuery-анимация исчезновения
     * @returns {void}
     * @example
     * // Программное закрытие окна
     * SubjectModal.close();
     */
    close() {
        // Защита от вызова при отсутствии инициализации
        if (!this.$modal || !this.$modal.jquery) {
            console.error('[SubjectModal] Ошибка: $modal не инициализирован.');
            return;
        }

        // Плавное исчезновение окна за 200 мс
        this.$modal.fadeOut(200);
    },
};

// Дополнительно: пример использования в WordPress-стиле с экспортом инициализации
/**
 * Функция-обёртка для безопасной инициализации после загрузки DOM.
 * @function initSubjectModal
 * @description Рекомендуется вызывать внутри jQuery(document).ready()
 * @returns {void}
 */
export function initSubjectModal() {
    // Проверяем, что jQuery действительно загружена
    if (typeof jQuery === 'undefined') {
        console.error('[SubjectModal] jQuery не загружена. Плагин не будет работать.');
        return;
    }

    SubjectModal.init();
}