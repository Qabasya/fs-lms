/**
 * @fileoverview Модуль управления таксономиями (Taxonomies) для плагина FS-LMS.
 * @description Обеспечивает полный цикл управления таксономиями: создание, редактирование,
 *              удаление через модальное окно с AJAX-запросами и перезагрузкой таблицы.
 * @requires jQuery - глобальная зависимость WordPress.
 * @requires ../_types.js - глобальные типы данных.
 * @requires ../components/taxonomy-modal.js - Модальное окно управления таксономиями.
 */

import '../_types.js';
import { TaxonomyModal } from '../components/taxonomy-modal.js';
import { ConfirmModal } from '../components/confirm-modal.js';

const $ = jQuery;

/**
 * Показывает уведомление WordPress-стиля.
 */
function showNotice(message, type, $container) {
    $container.find('.notice').remove();

    const $notice = $(`
        <div class="notice notice-${type} is-dismissible" style="margin: 10px 0;">
            <p><strong>${type === 'success' ? 'Готово!' : 'Ошибка:'}</strong> ${message}</p>
            <button type="button" class="notice-dismiss">
                <span class="screen-reader-text">Закрыть</span>
            </button>
        </div>
    `);

    $notice.on('click', '.notice-dismiss', function() {
        $notice.fadeTo(100, 0, function() {
            $notice.slideUp(100, function() { $(this).remove(); });
        });
    });

    $container.prepend($notice);

    if (type === 'success') {
        setTimeout(() => $notice.find('.notice-dismiss').trigger('click'), 5000);
    }
}

/**
 * Объект для управления таксономиями.
 * @namespace Taxonomies
 * @typedef {Object} Taxonomies
 */
export const Taxonomies = {
    /**
     * Инициализирует модуль управления таксономиями.
     * Проверяет наличие таблицы таксономий, настраивает колбэк сохранения и навешивает обработчики событий.
     * @memberof Taxonomies
     * @instance
     * @returns {void}
     * @example
     * // Инициализация после загрузки DOM
     * jQuery(document).ready(() => {
     *     Taxonomies.init();
     * });
     */
    init() {
        /**
         * Проверяем наличие таблицы таксономий на странице.
         * Если таблица отсутствует — прекращаем инициализацию.
         */
        if (!$('.js-taxonomy-table').length) return;

        /**
         * Устанавливаем колбэк для сохранения таксономии через модальное окно.
         * @param {TaxonomyFormData} data - Данные формы таксономии
         */
        TaxonomyModal.onSave((data) => this.save(data));

        /**
         * Навешиваем обработчики событий для кнопок добавления, редактирования и удаления.
         */
        this._bindEvents();
    },

    /**
     * Навешивает обработчики событий для управления таксономиями.
     * @memberof Taxonomies
     * @instance
     * @private
     * @listens click.js-add-taxonomy - Открытие модального окна для создания таксономии
     * @listens click.js-edit-tax - Открытие модального окна для редактирования таксономии
     * @listens click.js-delete-tax - Удаление таксономии после подтверждения
     * @returns {void}
     */
    _bindEvents() {
        /**
         * Обработчик клика по кнопке "Добавить таксономию".
         * @param {Event} e - Событие click
         */
        $('.js-add-taxonomy').on('click', (e) => {
            e.preventDefault(); // Отменяем стандартное поведение ссылки
            TaxonomyModal.open('store'); // Открываем модальное окно в режиме создания
        });

        /**
         * Обработчик клика по кнопке редактирования таксономии (делегирование на таблицу).
         * @param {Event} e - Событие click
         */
        $('.js-taxonomy-table').on('click', '.js-edit-tax', (e) => {
            e.preventDefault(); // Отменяем стандартное поведение ссылки

            /**
             * Строка таблицы, содержащая редактируемую таксономию.
             * @type {jQuery}
             */
            const $row = $(e.currentTarget).closest('tr');

            /**
             * Открываем модальное окно в режиме обновления с данными из строки таблицы.
             * @type {Object} data
             * @property {string} slug - Слаг таксономии (из data-атрибута)
             * @property {string} name - Название таксономии (из data-атрибута)
             * @property {string} display - Тип отображения (из data-атрибута)
             */
            TaxonomyModal.open('update', {
                slug: $row.data('slug'),
                name: $row.data('name'),
                display: $row.data('display'),
            });
        });

        /**
         * Обработчик клика по кнопке удаления таксономии (делегирование на таблицу).
         * @param {Event} e - Событие click
         */
        $('.js-taxonomy-table').on('click', '.js-delete-tax', (e) => {
            e.preventDefault();
            const $row = $(e.currentTarget).closest('tr');
            const slug = $row.data('slug');
            const subject_key = $('#tax-subject-key').val();
            const taxName = $row.data('name');

            ConfirmModal.confirm({
                title: 'Удаление таксономии',
                message: `Удалить таксономию «${taxName}»?\nВсе связанные термины будут безвозвратно стёрты.`,
                confirmText: 'Удалить',
                cancelText: 'Отмена',
            }).then(() => {
                // 🔥 Успешное подтверждение — запускаем удаление
                this._ajaxDelete(slug, subject_key, $row);
            }).catch(() => {
                // Отмена — ничего не делаем
            });
        });
    },

    /**
     * Сохраняет таксономию (создаёт или обновляет) через AJAX-запрос.
     * @memberof Taxonomies
     * @instance
     * @param {TaxonomyFormData} data - Данные формы таксономии.
     * @param {string} data.tax_name - Название таксономии (обязательное поле).
     * @param {string} data.tax_slug - Слаг таксономии (обязателен при создании).
     * @param {string} data.action - Тип действия ('store' или 'update').
     * @param {string} data.subject_key - Ключ предмета.
     * @param {string} data.display_type - Тип отображения ('select' или другой).
     * @returns {void}
     * @fires $.post - AJAX-запрос на сохранение таксономии
     */
    save(data) {
        /**
         * Валидация обязательных полей:
         * - tax_name: название таксономии всегда обязательно
         * - tax_slug: слаг обязателен только при создании новой таксономии
         */
        if (!data.tax_name || (data.action === 'store' && !data.tax_slug)) {
            alert('Пожалуйста, заполните все поля');
            return;
        }

        // Блокируем кнопку сохранения в модальном окне
        TaxonomyModal.setSaveState(true);

        /**
         * Выполняем AJAX-запрос на сохранение таксономии.
         * Действие (storeTaxonomy или updateTaxonomy) выбирается в зависимости от data.action.
         * @param {string} url - URL обработчика AJAX WordPress
         * @param {Object} requestData - Данные для отправки
         * @param {string} requestData.action - AJAX-действие для сохранения таксономии
         * @param {string} requestData.security - Nonce для проверки безопасности
         * @param {string} requestData.subject_key - Ключ предмета
         * @param {string} requestData.tax_slug - Слаг таксономии
         * @param {string} requestData.tax_name - Название таксономии
         * @param {string} requestData.display_type - Тип отображения
         */
        $.post(fs_lms_vars.ajaxurl, {
            action:       data.action === 'store' ? fs_lms_vars.ajax_actions.storeTaxonomy : fs_lms_vars.ajax_actions.updateTaxonomy,
            security:     fs_lms_vars.subject_nonce,
            subject_key:  data.subject_key,
            tax_slug:     data.tax_slug,
            tax_name:     data.tax_name,
            display_type: data.display_type,
        })
            .done((res) => {
                /**
                 * Обработка успешного ответа сервера.
                 * @param {Object} res - Ответ сервера
                 * @param {boolean} res.success - Флаг успешности операции
                 * @param {string} res.data - Сообщение об ошибке (при неудаче)
                 */
                if (res.success) {
                    /**
                     * При успехе перезагружаем страницу для отображения обновлённого списка таксономий.
                     */
                    location.reload();
                } else {
                    /**
                     * При ошибке, возвращённой сервером, показываем сообщение и разблокируем кнопку.
                     */
                    alert('Ошибка: ' + res.data);
                    TaxonomyModal.setSaveState(false);
                }
            })
            .fail(() => {
                /**
                 * Обработка ошибки HTTP-запроса (сервер недоступен, таймаут и т.д.).
                 */
                alert('Системная ошибка сервера');
                TaxonomyModal.setSaveState(false);
            });
    },

    /**
     * Удаляет таксономию через AJAX-запрос.
     * @memberof Taxonomies
     * @instance
     * @private
     * @param {string} slug - Слаг таксономии для удаления.
     * @param {string} subject_key - Ключ предмета.
     * @returns {void}
     * @fires $.post - AJAX-запрос на удаление таксономии
     */
    _ajaxDelete(slug, subject_key, $row) {
        $.post(fs_lms_vars.ajaxurl, {
            action:      fs_lms_vars.ajax_actions.deleteTaxonomy,
            security:    fs_lms_vars.subject_nonce,
            subject_key: subject_key,
            tax_slug:    slug,
        })
            .done((res) => {
                if (res.success) {
                    if ($row?.length) {
                        $row
                            .css('background', '#ff8d8d')
                            .fadeOut(400, function () {
                                $(this).remove();
                                showNotice('Таксономия удалена', 'success', $('.js-taxonomy-table'));
                            });
                    } else {
                        location.reload();
                    }
                } else {
                    alert('Ошибка при удалении: ' + res.data);
                }
            })
            .fail(() => {
                alert('Системная ошибка при удалении');
            });
    },
};

/**
 * @typedef {Object} TaxonomyFormData
 * @property {string} action - Тип действия ('store' или 'update')
 * @property {string} subject_key - Ключ предмета
 * @property {string} tax_slug - Слаг таксономии
 * @property {string} tax_name - Название таксономии
 * @property {string} display_type - Тип отображения ('select' или другой)
 */

/**
 * @typedef {Object} SaveTaxonomyResponse
 * @property {boolean} success - Флаг успешности операции
 * @property {string} [data] - Сообщение об ошибке (при success = false)
 */

/**
 * @typedef {Object} DeleteTaxonomyResponse
 * @property {boolean} success - Флаг успешности удаления
 * @property {string} [data] - Сообщение об ошибке (при success = false)
 */

/**
 * @typedef {Object} TaxonomyRowData
 * @property {string} slug - Слаг таксономии
 * @property {string} name - Название таксономии
 * @property {string} display - Тип отображения
 * @property {number} [term_count] - Количество терминов в таксономии (опционально)
 */