/**
 * @module ConfirmModal
 * @description Универсальный UI-компонент модального окна подтверждения действий.
 *              Реализует промисифицированный API (возвращает Promise), что позволяет
 *              использовать его в конструкциях async/await для линейного и читаемого кода.
 *              Отвечает за:
 *              - Динамическую настройку заголовка, текста и кнопок
 *              - Адаптацию размера окна и стилизации кнопки подтверждения (обычная/опасная)
 *              - Обработку закрытия по клику на фон, крестик, кнопку отмены или клавишу ESC
 *              - Очистку обработчиков событий для предотвращения утечек памяти
 *
 * @requires jQuery
 * @requires openModal, closeModal, bindEsc, unbindEsc - базовые уutilities управления модальными окнами
 */

import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

/**
 * Утилита для управления модальным окном подтверждения действий.
 * @namespace ConfirmModal
 */
const ConfirmModal = {
    /** @type {jQuery} Кэшированная ссылка на элемент body (может использоваться для блокировки скролла) */
    $body: null,

    /** @type {jQuery} Кэшированная ссылка на основной контейнер модального окна */
    $modal: null,

    /**
     * Инициализация модуля.
     * Кэширует DOM-элементы для оптимизации производительности.
     * Должен вызываться один раз после отрисовки страницы (например, в главном файле инициализации).
     */
    init() {
        this.$body = $('body');
        this.$modal = $('#fs-lms-confirm-modal');
    },

    /**
     * Открывает модальное окно с заданными параметрами и возвращает Promise.
     *
     * @param {Object} [options] Параметры отображения модального окна.
     * @param {string} [options.title='Подтвердите действие'] Заголовок окна.
     * @param {string} [options.message=''] Основное текстовое сообщение.
     * @param {string} [options.confirmText='Подтвердить'] Текст на кнопке подтверждения.
     * @param {string} [options.cancelText='Отмена'] Текст на кнопке отмены.
     * @param {'sm'|'md'|'lg'|'xl'} [options.size='md'] Размер модального окна.
     * @param {boolean} [options.isDanger=true] Флаг, определяющий, является ли действие опасным (красная кнопка).
     * @returns {Promise<void>} Разрешается (resolve) при подтверждении, отклоняется (reject) при отмене или закрытии.
     */
    confirm({
                title = 'Подтвердите действие',
                message = '',
                confirmText = 'Подтвердить',
                cancelText = 'Отмена',
                size = 'md',
                isDanger = true
            } = {}) {

        const $content = this.$modal.find('.fs-lms-modal-content');

        // ДИНАМИЧЕСКАЯ СТИЛИЗАЦИЯ РАЗМЕРА:
        // Сначала удаляем все возможные классы размеров, затем добавляем нужный.
        // Это предотвращает накопление классов (например, "fs-modal-sm fs-modal-lg")
        // при повторных вызовах метода с разными параметрами размера.
        $content.removeClass('fs-modal-sm fs-modal-md fs-modal-lg fs-modal-xl')
            .addClass(`fs-modal-${size}`);

        const $confirmBtn = this.$modal.find('.fs-lms-modal-confirm');

        // ДИНАМИЧЕСКАЯ СТИЛИЗАЦИЯ КНОПКИ:
        // Если действие опасное (удаление, отчисление), кнопка получает класс 'button-link-delete' (обычно красный).
        // Если действие нейтральное, используется стандартный 'button-primary' (обычно синий).
        $confirmBtn.removeClass('button-link-delete button-primary');
        if (isDanger) {
            $confirmBtn.addClass('button-link-delete');
        } else {
            $confirmBtn.addClass('button-primary');
        }

        // Обновление текстового содержимого элементов модального окна
        this.$modal.find('.fs-lms-modal-title').text(title);
        this.$modal.find('.fs-lms-modal-message').text(message);
        $confirmBtn.text(confirmText);
        this.$modal.find('.fs-lms-modal-cancel').text(cancelText);

        // Базовая логика открытия через утилиту
        openModal(this.$modal);

        // ВОЗВРАТ PROMISE:
        // Это ключевая особенность модуля. Вместо передачи колбэков, мы возвращаем Promise.
        // Это позволяет вызывающему коду использовать синтаксис await:
        // await ConfirmModal.confirm({ title: 'Удалить?' });
        return new Promise((resolve, reject) => {

            // Обработчик подтверждения.
            // .off('click.confirm') удаляет предыдущие обработчики с этим неймспейсом,
            // предотвращая их дублирование при повторных открытиях модалки.
            $confirmBtn
                .off('click.confirm')
                .on('click.confirm', () => {
                    this._close();
                    resolve(); // Разрешаем промис, сигнализируя об успехе
                });

            // Обработчик кнопки "Отмена"
            this.$modal.find('.fs-lms-modal-cancel')
                .off('click.confirm')
                .on('click.confirm', () => {
                    this._close();
                    reject('cancel'); // Отклоняем промис с причиной 'cancel'
                });

            // Обработчики закрытия через крестик или клик по затемненному фону
            this.$modal.find('.fs-lms-modal-close, .fs-lms-modal-backdrop')
                .off('click.confirm')
                .on('click.confirm', () => {
                    this._close();
                    reject('close'); // Отклоняем промис с причиной 'close'
                });

            // Обработчик клавиши ESC
            bindEsc('confirm', () => {
                this._close();
                reject('esc'); // Отклоняем промис с причиной 'esc'
            });
        });
    },

    /**
     * Публичный метод для программного закрытия модального окна.
     * Может быть вызван извне, если необходимо закрыть окно по таймеру или другому событию.
     */
    close() {
        this._close();
    },

    /**
     * Внутренний метод закрытия модального окна.
     * Отвечает за полную очистку состояния: закрытие UI, отвязку глобальных слушателей ESC.
     * @private
     */
    _close() {
        closeModal(this.$modal);
        // Обязательно отвязываем обработчик ESC, чтобы он не перехватывал нажатия
        // клавиш, когда модальное окно уже закрыто.
        unbindEsc('confirm');
    },
};

export { ConfirmModal };