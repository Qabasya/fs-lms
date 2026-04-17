/**
 * @fileoverview Модуль управления предметами (Subjects) для плагина FS-LMS.
 * @description Обеспечивает полный цикл управления предметами: создание, редактирование,
 *              удаление (с многоступенчатым подтверждением), экспорт и импорт.
 *              Включает функционал быстрого редактирования inline и модальные окна подтверждения.
 * @requires jQuery - глобальная зависимость WordPress.
 * @requires ../_types.js - глобальные типы данных.
 * @requires ../modules/utils.js - Утилиты для работы с кнопками и ошибками.
 */

import '../_types.js';
import {Utils} from '../modules/utils.js';

/**
 * Объект для управления предметами (Subjects).
 * @namespace Subjects
 * @typedef {Object} Subjects
 */
export const Subjects = {
    /**
     * Инициализирует модуль управления предметами.
     * @memberof Subjects
     * @instance
     * @returns {void}
     * @example
     * // Инициализация после загрузки DOM
     * jQuery(document).ready(() => {
     *     Subjects.init();
     * });
     */
    init() {
        this.bindEvents();
    },

    /**
     * Навешивает все обработчики событий для управления предметами.
     * @memberof Subjects
     * @instance
     * @listens submit#fs-add-subject-form - Сохранение нового предмета
     * @listens click.open-quick-edit - Открытие формы быстрого редактирования
     * @listens click.delete-subject - Удаление предмета
     * @listens click.js-export-subject - Экспорт предмета
     * @listens click#fs-import-trigger - Триггер выбора файла для импорта
     * @listens change#fs-import-file - Обработка выбранного файла импорта
     * @returns {void}
     */
    bindEvents() {
        const $ = jQuery;

        /**
         * Обработчик отправки формы добавления предмета.
         * @param {Event} e - Событие submit
         */
        $('#fs-add-subject-form').on('submit', (e) => this.handleSave(e));

        /**
         * Обработчик клика по кнопке быстрого редактирования (делегирование на document).
         * @param {Event} e - Событие click
         */
        $(document).on('click', '.open-quick-edit', (e) => this.handleQuickEdit(e));

        /**
         * Обработчик клика по кнопке удаления предмета (делегирование на document).
         * @param {Event} e - Событие click
         */
        $(document).on('click', '.delete-subject', (e) => this.handleDelete(e));

        /**
         * Обработчик клика по кнопке экспорта предмета (делегирование на document).
         * @param {Event} e - Событие click
         */
        $(document).on('click', '.js-export-subject', (e) => this.handleRowExport(e));

        /**
         * Обработчик клика по триггеру импорта — имитирует клик по скрытому input file.
         */
        $('#fs-import-trigger').on('click', () => $('#fs-import-file').trigger('click'));

        /**
         * Обработчик выбора файла для импорта.
         * @param {Event} e - Событие change
         */
        $('#fs-import-file').on('change', (e) => this.handleImport(e));
    },

    /**
     * Обрабатывает сохранение (создание) нового предмета.
     * @memberof Subjects
     * @instance
     * @param {Event} e - Событие submit
     * @returns {void}
     * @fires jQuery.post - AJAX-запрос на сохранение предмета
     */
    handleSave(e) {
        e.preventDefault(); // Отменяем стандартную отправку формы

        /**
         * Форма добавления предмета.
         * @type {jQuery}
         */
        const $form = jQuery(e.target);

        /**
         * Кнопка отправки формы.
         * @type {jQuery}
         */
        const $btn = $form.find('.button-primary');

        // Переключаем кнопку в состояние загрузки
        Utils.toggleButton($btn, true, 'Сохранение...');

        /**
         * Выполняем AJAX-запрос на сервер для сохранения предмета.
         * @param {string} url - URL обработчика AJAX WordPress
         * @param {string} data - Сериализованные данные формы + action
         * @param {Object} res - Ответ сервера
         * @param {boolean} res.success - Флаг успешности операции
         * @param {string} res.data - Сообщение об ошибке (при неудаче)
         */
        jQuery.post(fs_lms_vars.ajaxurl, $form.serialize() + '&action=' + fs_lms_vars.ajax_actions.storeSubject, (res) => {
            if (res.success) {
                // При успехе перезагружаем страницу для отображения обновлённого списка
                location.reload();
            } else {
                // При ошибке показываем сообщение и восстанавливаем кнопку
                alert(res.data || 'Ошибка сохранения');
                Utils.toggleButton($btn, false);
            }
        }).fail(Utils.apiError); // Обработка ошибок HTTP-запроса
    },

    /**
     * Обрабатывает открытие формы быстрого редактирования предмета (inline edit).
     * @memberof Subjects
     * @instance
     * @param {Event} e - Событие click
     * @returns {void}
     */
    handleQuickEdit(e) {
        e.preventDefault(); // Отменяем стандартное поведение ссылки

        /**
         * Кнопка, по которой был клик.
         * @type {jQuery}
         */
        const $btn = jQuery(e.target);

        /**
         * Данные из data-атрибутов кнопки.
         * @type {Object}
         * @property {string} name - Название предмета
         * @property {string} count - Количество задач
         * @property {string} key - Уникальный ключ предмета
         */
        const data = $btn.data();

        /**
         * Строка таблицы, содержащая редактируемый предмет.
         * @type {jQuery}
         */
        const $row = $btn.closest('tr');

        /**
         * Клонируем шаблон формы быстрого редактирования и показываем его.
         * @type {jQuery}
         */
        const $editRow = jQuery('#fs-quick-edit-row').clone().show();

        // Заполняем поля формы текущими значениями
        $editRow.find('input[name="name"]').val(data.name);
        $editRow.find('input[name="tasks_count"]').val(data.count);
        $editRow.find('input[name="key"]').val(data.key);

        // Скрываем исходную строку и вставляем форму редактирования после неё
        $row.hide().after($editRow);

        /**
         * Обработчик отмены редактирования.
         */
        $editRow.find('.cancel').on('click', () => {
            $editRow.remove(); // Удаляем форму редактирования
            $row.show();       // Показываем исходную строку
        });

        /**
         * Обработчик отправки формы быстрого редактирования.
         * @param {Event} event - Событие submit
         */
        $editRow.find('#fs-quick-edit-form').on('submit', (event) => {
            event.preventDefault();

            /**
             * Кнопка сохранения в форме редактирования.
             * @type {jQuery}
             */
            const $saveBtn = $editRow.find('.save');
            Utils.toggleButton($saveBtn, true, '...');

            /**
             * Выполняем AJAX-запрос на обновление предмета.
             */
            jQuery.post(fs_lms_vars.ajaxurl, jQuery(event.target).serialize() + '&action=' + fs_lms_vars.ajax_actions.updateSubject, (res) => {
                if (res.success) {
                    // При успехе перезагружаем страницу
                    location.reload();
                } else {
                    alert('Ошибка');
                }
            }).fail(Utils.apiError);
        });
    },

    /**
     * Обрабатывает начало процесса удаления предмета (показывает предупреждение).
     * @memberof Subjects
     * @instance
     * @param {Event} e - Событие click
     * @returns {void}
     */
    handleDelete(e) {
        e.preventDefault(); // Отменяем стандартное поведение

        /**
         * Кнопка удаления.
         * @type {jQuery}
         */
        const $btn = jQuery(e.target);

        /**
         * Ключ предмета для удаления.
         * @type {string}
         */
        const key = $btn.data('key');

        /**
         * Строка таблицы с предметом.
         * @type {jQuery}
         */
        const $row = $btn.closest('tr');

        /**
         * Название предмета (из текста ссылки в первой ячейке).
         * @type {string}
         */
        const name = $row.find('strong a').text().trim();

        /**
         * Nonce для безопасности запроса.
         * @type {string}
         */
        const security = this._nonce();

        // Показываем модальное окно с предупреждением
        this._showWarningModal(name, key, security, $btn, $row);
    },

    /**
     * Обрабатывает экспорт предмета.
     * @memberof Subjects
     * @instance
     * @param {Event} e - Событие click
     * @returns {void}
     * @fires jQuery.post - AJAX-запрос на экспорт данных предмета
     */
    handleRowExport(e) {
        e.preventDefault(); // Отменяем стандартное поведение ссылки

        /**
         * Ссылка экспорта.
         * @type {jQuery}
         */
        const $link = jQuery(e.target);

        /**
         * Ключ предмета для экспорта.
         * @type {string}
         */
        const key = $link.data('key');

        /**
         * Выполняем экспорт предмета.
         */
        this._exportSubject(key, this._nonce(), $link);
    },

    /**
     * Обрабатывает импорт предмета из JSON-файла.
     * @memberof Subjects
     * @instance
     * @param {Event} e - Событие change от input file
     * @returns {void}
     * @fires FileReader - Чтение выбранного файла
     * @fires jQuery.post - AJAX-запрос на импорт данных
     */
    handleImport(e) {
        /**
         * Выбранный файл.
         * @type {File|undefined}
         */
        const file = e.target.files[0];

        // Если файл не выбран — выходим
        if (!file) return;

        // Очищаем значение input, чтобы можно было выбрать тот же файл повторно
        e.target.value = '';

        /**
         * Читаем файл с помощью FileReader.
         * @type {FileReader}
         */
        const reader = new FileReader();

        /**
         * Обработчик успешного чтения файла.
         * @param {ProgressEvent} ev - Событие завершения чтения
         */
        reader.onload = (ev) => {
            /**
             * Парсим JSON из файла.
             * @type {Object}
             */
            let data;
            try {
                data = JSON.parse(ev.target.result);
            } catch (_) {
                alert('Не удалось прочитать файл. Убедитесь, что это корректный JSON.');
                return;
            }

            /**
             * Название предмета из импортируемых данных.
             * @type {string}
             */
            const name = data?.subject?.name || data?.subject?.key || 'предмет';

            /**
             * Создаём модальное окно подтверждения импорта.
             * @type {jQuery}
             */
            const $modal = this._createModal(
                `<p>Импортировать <strong>${name}</strong>?</p>` +
                `<p>Будут восстановлены: таксономии, термины, шаблоны, boilerplates и записи.</p>` +
                `<div class="fs-modal-actions">` +
                `<button class="button" data-action="cancel">Отмена</button>` +
                `<button class="button button-primary" data-action="confirm">Импортировать</button>` +
                `</div>`
            );

            /**
             * Обработчик кнопки "Отмена".
             */
            $modal.find('[data-action="cancel"]').on('click', () => $modal.remove());

            /**
             * Обработчик кнопки "Импортировать".
             * @param {Event} ev2 - Событие click
             */
            $modal.find('[data-action="confirm"]').on('click', (ev2) => {
                /**
                 * Кнопка импорта.
                 * @type {jQuery}
                 */
                const $btn = jQuery(ev2.target);
                Utils.toggleButton($btn, true, 'Импорт...');

                /**
                 * Выполняем AJAX-запрос на импорт данных.
                 */
                jQuery.post(fs_lms_vars.ajaxurl, {
                    action: fs_lms_vars.ajax_actions.importSubject,
                    json: ev.target.result,
                    security: this._nonce(),
                }, (res) => {
                    $modal.remove(); // Закрываем модальное окно

                    if (res.success) {
                        // При успехе перезагружаем страницу
                        location.reload();
                    } else {
                        alert(res.data || 'Ошибка импорта');
                    }
                }).fail(() => {
                    $modal.remove();
                    Utils.apiError();
                });
            });
        };

        // Запускаем чтение файла как текст
        reader.readAsText(file);
    },

    /**
     * Показывает модальное окно с предупреждением перед удалением предмета.
     * @memberof Subjects
     * @instance
     * @private
     * @param {string} name - Название предмета.
     * @param {string} key - Ключ предмета.
     * @param {string} security - Nonce для безопасности.
     * @param {jQuery} $btn - Кнопка, вызвавшая удаление.
     * @param {jQuery} $row - Строка таблицы с предметом.
     * @returns {void}
     */
    _showWarningModal(name, key, security, $btn, $row) {
        /**
         * Создаём модальное окно с предупреждением о последствиях удаления.
         * @type {jQuery}
         */
        const $modal = this._createModal(
            `<p>Вы собираетесь удалить предмет <strong>${name}</strong>.</p>` +
            `<p>Будут безвозвратно удалены все связанные таксономии, термины, привязки шаблонов, boilerplates и записи.</p>` +
            `<p>Рекомендуем экспортировать данные перед удалением.</p>` +
            `<div class="fs-modal-actions">` +
            `<button class="button" data-action="cancel">Отмена</button>` +
            `<button class="button button-secondary" data-action="export">Экспорт</button>` +
            `<button class="button" data-action="proceed" style="background:#d63638;border-color:#d63638;color:#fff;">Удалить всё равно</button>` +
            `</div>`
        );

        /**
         * Обработчик кнопки "Отмена".
         */
        $modal.find('[data-action="cancel"]').on('click', () => $modal.remove());

        /**
         * Обработчик кнопки "Экспорт".
         * @param {Event} ev - Событие click
         */
        $modal.find('[data-action="export"]').on('click', (ev) => {
            this._exportSubject(key, security, jQuery(ev.target));
        });

        /**
         * Обработчик кнопки "Удалить всё равно" — показывает финальное подтверждение.
         */
        $modal.find('[data-action="proceed"]').on('click', () => {
            $modal.remove();
            this._showConfirmModal(name, key, security, $btn, $row);
        });
    },

    /**
     * Показывает финальное модальное окно подтверждения удаления предмета.
     * @memberof Subjects
     * @instance
     * @private
     * @param {string} name - Название предмета.
     * @param {string} key - Ключ предмета.
     * @param {string} security - Nonce для безопасности.
     * @param {jQuery} $btn - Кнопка, вызвавшая удаление.
     * @param {jQuery} $row - Строка таблицы с предметом.
     * @returns {void}
     */
    _showConfirmModal(name, key, security, $btn, $row) {
        /**
         * Создаём финальное модальное окно подтверждения.
         * @type {jQuery}
         */
        const $modal = this._createModal(
            `<p><strong>Точно удалить «${name}»?</strong></p>` +
            `<p>Это действие необратимо.</p>` +
            `<div class="fs-modal-actions">` +
            `<button class="button" data-action="cancel">Отмена</button>` +
            `<button class="button" data-action="confirm" style="background:#d63638;border-color:#d63638;color:#fff;">Точно удалить предмет</button>` +
            `</div>`
        );

        /**
         * Обработчик кнопки "Отмена".
         */
        $modal.find('[data-action="cancel"]').on('click', () => $modal.remove());

        /**
         * Обработчик кнопки "Точно удалить предмет" — выполняет удаление.
         */
        $modal.find('[data-action="confirm"]').on('click', () => {
            $modal.remove();
            this._doDelete(key, security, $btn, $row);
        });
    },

    /**
     * Создаёт модальное окно с заданным содержимым.
     * @memberof Subjects
     * @instance
     * @private
     * @param {string} content - HTML-содержимое модального окна.
     * @returns {jQuery} jQuery-объект созданного модального окна.
     */
    _createModal(content) {
        /**
         * Создаём overlay и модальное окно с переданным содержимым.
         * @type {jQuery}
         */
        const $overlay = jQuery(
            `<div class="fs-modal-overlay" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.6);z-index:160000;display:flex;align-items:center;justify-content:center;">` +
            `<div class="fs-modal-box" style="background:#fff;padding:24px;max-width:480px;width:90%;border-radius:4px;box-shadow:0 4px 20px rgba(0,0,0,.3);">` +
            content +
            `</div>` +
            `</div>`
        );

        // Добавляем модальное окно в DOM
        jQuery('body').append($overlay);

        return $overlay;
    },

    /**
     * Выполняет экспорт предмета и скачивает JSON-файл.
     * @memberof Subjects
     * @instance
     * @private
     * @param {string} key - Ключ предмета для экспорта.
     * @param {string} security - Nonce для безопасности.
     * @param {jQuery} $btn - Кнопка, вызвавшая экспорт.
     * @returns {void}
     * @fires jQuery.post - AJAX-запрос на получение данных для экспорта
     */
    _exportSubject(key, security, $btn) {
        /**
         * Оригинальный текст кнопки для восстановления.
         * @type {string}
         */
        const origText = $btn.text();
        Utils.toggleButton($btn, true, 'Экспорт...');

        /**
         * Выполняем AJAX-запрос на получение данных для экспорта.
         */
        jQuery.post(fs_lms_vars.ajaxurl, {
            action: fs_lms_vars.ajax_actions.exportSubject,
            key: key,
            security: security,
        }, (res) => {
            // Восстанавливаем состояние кнопки
            Utils.toggleButton($btn, false, origText);

            // Проверяем успешность ответа
            if (!res.success) {
                alert(res.data || 'Ошибка экспорта');
                return;
            }

            /**
             * Создаём Blob с JSON-данными и инициируем скачивание файла.
             * @type {Blob}
             */
            const blob = new Blob([JSON.stringify(res.data, null, 2)], {type: 'application/json'});
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'subject_' + key + '_export.json';
            a.click(); // Программный клик для скачивания
            URL.revokeObjectURL(url); // Освобождаем URL-объект
        }).fail(Utils.apiError);
    },

    /**
     * Выполняет фактическое удаление предмета после подтверждения.
     * @memberof Subjects
     * @instance
     * @private
     * @param {string} key - Ключ предмета для удаления.
     * @param {string} security - Nonce для безопасности.
     * @param {jQuery} $btn - Кнопка, вызвавшая удаление.
     * @param {jQuery} $row - Строка таблицы с предметом.
     * @returns {void}
     * @fires jQuery.post - AJAX-запрос на удаление предмета
     */
    _doDelete(key, security, $btn, $row) {
        // Переключаем кнопку в состояние загрузки
        Utils.toggleButton($btn, true, '...');

        /**
         * Выполняем AJAX-запрос на удаление предмета.
         */
        jQuery.post(fs_lms_vars.ajaxurl, {
            action: fs_lms_vars.ajax_actions.deleteSubject,
            key: key,
            security: security,
        }, (res) => {
            if (res.success) {
                /**
                 * При успешном удалении плавно скрываем строку таблицы.
                 */
                $row.fadeOut(400, () => {
                    $row.remove(); // Удаляем строку из DOM

                    /**
                     * Если в таблице не осталось строк — перезагружаем страницу.
                     */
                    if (jQuery('#tab-1 table.wp-list-table tbody').find('tr').length === 0) {
                        location.reload();
                    }
                });
            } else {
                // При ошибке восстанавливаем кнопку и показываем сообщение
                Utils.toggleButton($btn, false);
                alert(res.data || 'Ошибка удаления');
            }
        }).fail(Utils.apiError);
    },

    /**
     * Получает значение nonce из форм на странице.
     * @memberof Subjects
     * @instance
     * @private
     * @returns {string} Значение nonce из формы добавления или формы быстрого редактирования.
     */
    _nonce() {
        return jQuery('#fs-add-subject-form [name="security"]').val()
            || jQuery('#fs-quick-edit-form [name="security"]').val();
    },
};

/**
 * @typedef {Object} SubjectData
 * @property {string} name - Название предмета
 * @property {string} count - Количество задач в предмете
 * @property {string} key - Уникальный ключ предмета
 */

/**
 * @typedef {Object} ImportResponse
 * @property {boolean} success - Флаг успешности импорта
 * @property {string} [data] - Сообщение об ошибке
 */

/**
 * @typedef {Object} ExportResponse
 * @property {boolean} success - Флаг успешности получения данных
 * @property {Object} data - Экспортируемые данные предмета
 */

/**
 * @typedef {Object} DeleteResponse
 * @property {boolean} success - Флаг успешности удаления
 * @property {string} [data] - Сообщение об ошибке
 */

/**
 * @typedef {Object} StoreSubjectResponse
 * @property {boolean} success - Флаг успешности сохранения
 * @property {string} [data] - Сообщение об ошибке
 */

/**
 * @typedef {Object} UpdateSubjectResponse
 * @property {boolean} success - Флаг успешности обновления
 * @property {string} [data] - Сообщение об ошибке
 */