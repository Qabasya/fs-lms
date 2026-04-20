/**
 * @fileoverview Модуль управления задачами (TemplateManager) для плагина FS-LMS.
 * @description Обеспечивает функционал обновления шаблона (template) для конкретной задачи
 *              через AJAX с визуальной обратной связью (спиннер, иконка успеха).
 * @requires jQuery - глобальная зависимость WordPress.
 * @requires ../_types.js - глобальные типы данных.
 * @requires ../modules/utils.js - Утилиты (не используются напрямую, но импортированы для совместимости).
 */

import '../_types.js';
import { showNotice } from '../modules/utils.js';

const $ = jQuery;



/**
 * Объект для управления задачами.
 * @namespace TemplateManager
 * @typedef {Object} TemplateManager
 */
export const TemplateManager = {
    /**
     * Инициализирует модуль управления задачами.
     * Проверяет наличие таблицы задач на странице и запускает инициализацию менеджера шаблонов.
     * @memberof TemplateManager
     * @instance
     * @returns {void}
     * @example
     * // Инициализация после загрузки DOM
     * jQuery(document).ready(() => {
     *     TemplateManager.init();
     * });
     */
    init() {
        if ($('.js-task-manager-table').length) {
            this.initTemplateManager();
        }
    },

    /**
     * Инициализирует менеджер шаблонов для таблицы задач.
     * Навешивает обработчик изменения выпадающего списка шаблонов.
     * @memberof TemplateManager
     * @instance
     * @param {jQuery} $ - jQuery-объект для использования внутри метода.
     * @returns {void}
     * @listens change.js-task-manager-table .js-change-term-template
     */
    initTemplateManager() {
        $('.js-task-manager-table').on('change', '.js-change-term-template', function (e) {
            e.stopImmediatePropagation();

            const $select = $(this);
            const $row = $select.closest('tr');
            const $table = $select.closest('.js-task-manager-table');
            const $wrapper = $select.closest('.task-manager-wrapper'); // ← новая строка

            const requestData = {
                action:   fs_lms_vars.ajax_actions.updateTermTemplate,
                security: fs_lms_vars.subject_nonce,
                term_id:  $row.data('term-id'),
                template: $select.val(),
                key:      $table.data('subject'),
                name:     $row.data('task-name'),
            };

            $.ajax({
                url:  fs_lms_vars.ajaxurl,
                type: 'POST',
                data: requestData,
                success(response) {
                    if (response.success) {
                        showNotice('Шаблон задания успешно сохранён!', 'success', $wrapper); // ← исправлено
                    } else {
                        const msg = response.data || 'Неизвестная ошибка';
                        showNotice(msg, 'error', $wrapper); // ← исправлено
                    }
                },
                error() {
                    showNotice('Системная ошибка AJAX. Проверьте консоль.', 'error', $wrapper); // ← исправлено
                },
            });
        });
    }
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