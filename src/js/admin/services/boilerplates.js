/**
 * @fileoverview Модуль управления шаблонами (boilerplates) для плагина FS-LMS.
 * @description Обеспечивает функционал сохранения и удаления шаблонов через AJAX-запросы,
 *              с интеграцией текстового редактора TinyMCE и обработкой ответов сервера.
 * @requires jQuery - глобальная зависимость WordPress.
 * @requires ../_types.js - глобальные типы данных.
 */

import '../_types.js';

const $ = jQuery;

/**
 * Объект для управления шаблонами (boilerplates).
 * @namespace Boilerplates
 * @typedef {Object} Boilerplates
 */
export const Boilerplates = {
    /**
     * Инициализирует модуль шаблонов.
     * @memberof Boilerplates
     * @instance
     * @returns {void}
     * @example
     * // Инициализация после загрузки DOM
     * jQuery(document).ready(() => {
     *     Boilerplates.init();
     * });
     */
    init() {
        this.bindEvents();
    },

    /**
     * Навешивает обработчики событий для формы сохранения и кнопок удаления шаблонов.
     * @memberof Boilerplates
     * @instance
     * @listens submit#fs-lms-boilerplate-form - Отправка формы сохранения шаблона
     * @listens click.delete-boilerplate-link - Клик по ссылке удаления шаблона
     * @returns {void}
     */
    bindEvents() {
        const $body = $('body');
        const $form = $('#fs-lms-boilerplate-form');

        /**
         * Обработчик отправки формы сохранения шаблона.
         * @param {Event} e - Событие submit
         */
        if ($form.length) {
            $form.on('submit', (e) => {
                e.preventDefault(); // Отменяем стандартную отправку формы
                this.save($form);
            });
        }

    },

    /**
     * Сохраняет шаблон через AJAX-запрос.
     * Обновляет состояние кнопки сохранения, синхронизирует TinyMCE, обрабатывает ответ сервера.
     * При создании нового шаблона выполняет перенаправление на страницу редактирования.
     * @memberof Boilerplates
     * @instance
     * @param {jQuery} $form - jQuery-объект формы сохранения шаблона.
     * @returns {void}
     * @fires tinyMCE.triggerSave - Сохранение контента из редактора TinyMCE в текстовое поле
     * @fires $.post - AJAX-запрос на сервер
     */
    save($form) {
        /**
         * Сохраняем контент из визуального редактора TinyMCE в скрытое текстовое поле,
         * если редактор активен на странице.
         */
        if (typeof tinyMCE !== 'undefined') {
            tinyMCE.triggerSave();
        }

        /**
         * Кнопка отправки формы.
         * @type {jQuery}
         */
        const $btn = $form.find('input[type="submit"]');

        /**
         * Оригинальный текст кнопки для последующего восстановления.
         * @type {string}
         */
        const originalText = $btn.val();

        /**
         * Сериализованные данные формы для отправки на сервер.
         * @type {string}
         */
        const data = $form.serialize();

        // Изменяем состояние кнопки на время выполнения запроса
        $btn.val('Сохранение...').prop('disabled', true);

        /**
         * Выполняем AJAX-запрос на сервер.
         * @param {string} fs_lms_vars.ajaxurl - URL обработчика AJAX-запросов WordPress
         * @param {string} data - Сериализованные данные формы
         */
        $.post(fs_lms_vars.ajaxurl, data)
            .done((response) => {
                /**
                 * Обработка успешного ответа от сервера.
                 * @param {Object} response - Ответ сервера
                 * @param {boolean} response.success - Флаг успешности операции
                 * @param {Object} response.data - Данные ответа
                 * @param {string} response.data.uid - UID созданного шаблона (при создании нового)
                 */
                if (response.success) {
                    // Уведомляем пользователя об успешном сохранении
                    alert('Успешно сохранено!');

                    /**
                     * Параметры текущего URL для проверки действия.
                     * @type {URLSearchParams}
                     */
                    const urlParams = new URLSearchParams(window.location.search);

                    /**
                     * Если мы находимся на странице создания нового шаблона (action=new)
                     * и сервер вернул UID созданного шаблона, перенаправляем на страницу редактирования.
                     */
                    if (urlParams.get('action') === 'new' && response.data.uid) {
                        urlParams.set('action', 'edit');
                        urlParams.set('uid', response.data.uid);
                        window.location.search = urlParams.toString();
                    }
                } else {
                    /**
                     * Обработка ошибки, возвращённой сервером.
                     * @type {string}
                     */
                    alert('Ошибка: ' + (response.data || 'Неизвестная ошибка'));
                }
            })
            .fail(() => {
                /**
                 * Обработка ошибки HTTP-запроса (сервер недоступен, таймаут и т.д.).
                 */
                alert('Ошибка сервера');
            })
            .always(() => {
                /**
                 * Восстанавливаем исходное состояние кнопки независимо от результата запроса.
                 */
                $btn.val(originalText).prop('disabled', false);
            });
    },

    /**
     * Удаляет шаблон через AJAX-запрос.
     * При успешном удалении анимированно скрывает строку таблицы с шаблоном.
     * @memberof Boilerplates
     * @instance
     * @param {jQuery} $el - jQuery-объект элемента, по которому был клик (ссылка удаления).
     * @returns {void}
     * @fires $.post - AJAX-запрос на сервер для удаления
     */
    delete($el) {
        /**
         * Данные для отправки на сервер.
         * @type {Object}
         * @property {string} action - AJAX-действие для удаления шаблона
         * @property {string} nonce - Nonce для проверки безопасности
         * @property {string} subject_key - Ключ предмета (из URL)
         * @property {string} term_slug - Слаг термина таксономии (из URL)
         * @property {string} uid - Уникальный идентификатор удаляемого шаблона
         */
        const data = {
            action: fs_lms_vars.ajax_actions.deleteBoilerplate,
            nonce: $('input[name="fs_lms_boilerplate_nonce"]').val(),
            subject_key: new URLSearchParams(window.location.search).get('subject'),
            term_slug: new URLSearchParams(window.location.search).get('term'),
            uid: $el.data('uid'),
        };

        /**
         * Выполняем AJAX-запрос на удаление шаблона.
         * @param {Object} response - Ответ сервера
         * @param {boolean} response.success - Флаг успешности операции
         * @param {string} response.data - Сообщение об ошибке (в случае неудачи)
         */
        $.post(fs_lms_vars.ajaxurl, data, (response) => {
            if (response.success) {
                /**
                 * При успешном удалении:
                 * 1. Находим строку таблицы, содержащую удаляемый шаблон
                 * 2. Меняем фон строки на красный
                 * 3. Плавно скрываем строку
                 * 4. После завершения анимации удаляем элемент из DOM
                 */
                $el.closest('tr')
                    .css('background', '#ff8d8d')
                    .fadeOut(400, function () {
                        $(this).remove();
                    });
            } else {
                /**
                 * При ошибке удаления показываем сообщение от сервера.
                 */
                alert(response.data);
            }
        });
    },
};

/**
 * @typedef {Object} SaveResponse
 * @property {boolean} success - Флаг успешности операции сохранения
 * @property {SaveResponseData} data - Данные ответа
 */

/**
 * @typedef {Object} SaveResponseData
 * @property {string} [uid] - UID созданного шаблона (возвращается при создании нового)
 * @property {string} [message] - Сообщение об ошибке
 */

/**
 * @typedef {Object} DeleteResponse
 * @property {boolean} success - Флаг успешности операции удаления
 * @property {string} [data] - Сообщение об ошибке (в случае неудачи)
 */

/**
 * @typedef {Object} DeleteRequestData
 * @property {string} action - AJAX-действие для удаления шаблона
 * @property {string} nonce - Nonce для проверки безопасности
 * @property {string} subject_key - Ключ предмета
 * @property {string} term_slug - Слаг термина таксономии
 * @property {string} uid - Уникальный идентификатор удаляемого шаблона
 */