/**
 * @fileoverview Модуль управления задачами (Tasks) для плагина FS-LMS.
 * @description Обеспечивает функционал обновления шаблона (template) для конкретной задачи
 *              через AJAX с визуальной обратной связью (спиннер, иконка успеха).
 * @requires jQuery - глобальная зависимость WordPress.
 * @requires ../_types.js - глобальные типы данных.
 * @requires ../modules/utils.js - Утилиты (не используются напрямую, но импортированы для совместимости).
 */

import '../_types.js';
import {Utils} from '../modules/utils.js';

/**
 * Объект для управления задачами.
 * @namespace Tasks
 * @typedef {Object} Tasks
 */
export const Tasks = {
    /**
     * Инициализирует модуль управления задачами.
     * Проверяет наличие таблицы задач на странице и запускает инициализацию менеджера шаблонов.
     * @memberof Tasks
     * @instance
     * @returns {void}
     * @example
     * // Инициализация после загрузки DOM
     * jQuery(document).ready(() => {
     *     Tasks.init();
     * });
     */
    init() {
        const $ = jQuery;

        /**
         * Проверяем наличие таблицы управления задачами на странице.
         * Если таблица отсутствует — пропускаем инициализацию.
         */
        if ($('.js-task-manager-table').length) {
            this.initTemplateManager($);
        }
    },

    /**
     * Инициализирует менеджер шаблонов для таблицы задач.
     * Навешивает обработчик изменения выпадающего списка шаблонов.
     * @memberof Tasks
     * @instance
     * @param {jQuery} $ - jQuery-объект для использования внутри метода.
     * @returns {void}
     * @listens change.js-task-manager-table .js-change-term-template
     */
    initTemplateManager($) {
        /**
         * Обработчик изменения выбранного шаблона для задачи.
         * @param {Event} e - Событие change
         */
        $('.js-task-manager-table').on('change', '.js-change-term-template', function (e) {
            // Останавливаем всплытие события, чтобы избежать конфликтов с другими обработчиками
            e.stopImmediatePropagation();

            /**
             * Выпадающий список с выбором шаблона.
             * @type {jQuery}
             */
            const $select = $(this);

            /**
             * Строка таблицы, содержащая изменяемую задачу.
             * @type {jQuery}
             */
            const $row = $select.closest('tr');

            /**
             * Таблица задач, в которой происходит изменение.
             * @type {jQuery}
             */
            const $table = $select.closest('.js-task-manager-table');

            /**
             * Данные для отправки на сервер.
             * @type {Object}
             * @property {string} action - AJAX-действие для обновления шаблона термина
             * @property {string} security - Nonce для проверки безопасности
             * @property {string} term_id - ID термина (задачи)
             * @property {string} template - Выбранный шаблон (значение из выпадающего списка)
             * @property {string} key - Ключ предмета (из data-атрибута таблицы)
             * @property {string} name - Название задачи (из data-атрибута строки)
             */
            const requestData = {
                action:   fs_lms_vars.ajax_actions.updateTermTemplate,
                security: fs_lms_vars.subject_nonce,
                term_id:  $row.data('term-id'),
                template: $select.val(),
                key:      $table.data('subject'),
                name:     $row.data('task-name'),
            };

            /**
             * Элемент спиннера (индикатор загрузки) в текущей строке.
             * @type {jQuery}
             */
            const $spinner = $row.find('.spinner');

            /**
             * Иконка успеха в текущей строке.
             * @type {jQuery}
             */
            const $success = $row.find('.js-success-icon');

            /**
             * Показываем спиннер и скрываем иконку успеха.
             * Добавляем класс 'is-active' для правильной анимации спиннера WordPress.
             */
            $spinner.addClass('is-active').show();
            $success.hide();

            /**
             * Выполняем AJAX-запрос на обновление шаблона термина.
             * @param {string} url - URL обработчика AJAX WordPress
             * @param {string} type - HTTP метод запроса
             * @param {Object} data - Данные для отправки
             */
            $.ajax({
                url:  fs_lms_vars.ajaxurl,
                type: 'POST',
                data: requestData,
                /**
                 * Обработчик успешного ответа сервера.
                 * @param {Object} response - Ответ сервера
                 * @param {boolean} response.success - Флаг успешности операции
                 * @param {string} response.data - Сообщение об ошибке (при неудаче)
                 */
                success(response) {
                    // Скрываем спиннер
                    $spinner.removeClass('is-active').hide();

                    /**
                     * Если операция успешна — показываем иконку успеха с анимацией.
                     * Иконка появляется, затем через секунду исчезает.
                     */
                    if (response.success) {
                        $success.fadeIn().delay(1000).fadeOut();
                    } else {
                        /**
                         * При ошибке, возвращённой сервером, показываем сообщение.
                         */
                        alert('Ошибка: ' + response.data);
                    }
                },
                /**
                 * Обработчик ошибки HTTP-запроса (сервер недоступен, таймаут и т.д.).
                 */
                error() {
                    // Скрываем спиннер при ошибке
                    $spinner.removeClass('is-active').hide();
                    alert('Системная ошибка AJAX. Проверьте консоль.');
                },
            });
        });
    },
};

/**
 * @typedef {Object} UpdateTermTemplateRequest
 * @property {string} action - AJAX-действие для обновления шаблона термина
 * @property {string} security - Nonce для проверки безопасности
 * @property {string} term_id - ID термина (задачи)
 * @property {string} template - Выбранный шаблон
 * @property {string} key - Ключ предмета
 * @property {string} name - Название задачи
 */

/**
 * @typedef {Object} UpdateTermTemplateResponse
 * @property {boolean} success - Флаг успешности операции
 * @property {string} [data] - Сообщение об ошибке (при success = false)
 */

/**
 * @typedef {Object} TaskRowData
 * @property {string} termId - ID термина (задачи)
 * @property {string} taskName - Название задачи
 * @property {string} currentTemplate - Текущий выбранный шаблон
 */