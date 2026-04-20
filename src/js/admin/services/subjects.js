/**
 * Модуль управления предметами (Subjects) для плагина FS-LMS.
 * @requires jQuery
 * @requires ../modules/utils.js
 * @requires ../components/confirm-modal.js
 */

import '../_types.js';
import {
    toggleButton,
    apiError,
    showNotice
} from '../modules/utils.js';
import { ConfirmModal } from '../components/confirm-modal.js';

const $ = jQuery;

/**
 * Управление предметами: CRUD, экспорт/импорт, inline-редактирование.
 * @namespace Subjects
 */
export const Subjects = {
    /**
     * Инициализация модуля.
     */
    init() {
        this._bindEvents();
    },

    /**
     * Привязка обработчиков событий.
     * @private
     */
    _bindEvents() {
        $('#fs-add-subject-form').on('submit', (e) => this._handleSave(e));
        $(document).on('click', '.open-quick-edit', (e) => this._handleQuickEdit(e));
        $(document).on('click', '.delete-subject', (e) => this._handleDelete(e));
        $(document).on('click', '.js-export-subject', (e) => this._handleExport(e));
        $('#fs-import-trigger').on('click', () => $('#fs-import-file').trigger('click'));
        $('#fs-import-file').on('change', (e) => this._handleImport(e));
    },

    /**
     * Обработка сохранения нового предмета.
     * @param {Event} e
     * @private
     */
    _handleSave(e) {
        e.preventDefault();
        const $form = $(e.target);
        const $btn = $form.find('.button-primary');

        toggleButton($btn, true, 'Сохранение...');

        $.post(fs_lms_vars.ajaxurl, $form.serialize() + '&action=' + fs_lms_vars.ajax_actions.storeSubject)
            .done((res) => {
                if (res.success) {
                    location.reload();
                } else {
                    showNotice(res.data || 'Ошибка сохранения', 'error', $form);
                    toggleButton($btn, false);
                }
            })
            .fail(() => {
                apiError('Failed to save subject');
                toggleButton($btn, false);
            });
    },

    /**
     * Обработка inline-редактирования предмета.
     * @param {Event} e
     * @private
     */
    _handleQuickEdit(e) {
        e.preventDefault();
        const $btn = $(e.target);
        const data = $btn.data();
        const $row = $btn.closest('tr');
        const $editRow = $('#fs-quick-edit-row').clone().show();

        $editRow.find('input[name="name"]').val(data.name);
        $editRow.find('input[name="tasks_count"]').val(data.count);
        $editRow.find('input[name="key"]').val(data.key);

        $row.hide().after($editRow);

        $editRow.find('.cancel').on('click', () => {
            $editRow.remove();
            $row.show();
        });

        $editRow.find('#fs-quick-edit-form').on('submit', (event) => {
            event.preventDefault();
            const $saveBtn = $editRow.find('.save');
            toggleButton($saveBtn, true, '...');

            $.post(fs_lms_vars.ajaxurl, $(event.target).serialize() + '&action=' + fs_lms_vars.ajax_actions.updateSubject)
                .done((res) => {
                    if (res.success) {
                        location.reload();
                    } else {
                        showNotice('Ошибка обновления', 'error', $editRow);
                        toggleButton($saveBtn, false);
                    }
                })
                .fail(() => {
                    apiError('Failed to update subject');
                    toggleButton($saveBtn, false);
                });
        });
    },

    /**
     * Начало процесса удаления: показ предупреждения.
     * @param {Event} e
     * @private
     */
    _handleDelete(e) {
        e.preventDefault();
        const $btn = $(e.target);
        const key = $btn.data('key');
        const $row = $btn.closest('tr');
        const name = $row.find('strong a').text().trim();
        const security = this._getNonce();

        this._showWarningModal(name, key, security, $btn, $row);
    },

    /**
     * Обработка экспорта предмета.
     * @param {Event} e
     * @private
     */
    _handleExport(e) {
        e.preventDefault();
        const key = $(e.target).data('key');
        const security = this._getNonce();
        this._exportSubject(key, security, $(e.target));
    },

    /**
     * Обработка импорта предмета из JSON-файла.
     * @param {Event} e
     * @private
     */
    _handleImport(e) {
        const file = e.target.files[0];
        if (!file) return;

        e.target.value = '';
        const reader = new FileReader();

        reader.onload = (ev) => {
            let data;
            try {
                data = JSON.parse(ev.target.result);
            } catch {
                showNotice('Не удалось прочитать файл. Убедитесь, что это корректный JSON.', 'error', $('#fs-import-trigger').parent());
                return;
            }

            const name = data?.subject?.name || data?.subject?.key || 'предмет';
            const safeName = this._escapeHtml(name);

            ConfirmModal.confirm({
                title: 'Импорт предмета',
                message: `Импортировать «${safeName}»?\nБудут восстановлены: таксономии, термины, шаблоны, boilerplates и записи.`,
                confirmText: 'Импортировать',
                cancelText: 'Отмена',
            })
                .then(() => {
                    $.post(fs_lms_vars.ajaxurl, {
                        action: fs_lms_vars.ajax_actions.importSubject,
                        json: ev.target.result,
                        security: this._getNonce(),
                    })
                        .done((res) => {
                            if (res.success) {
                                location.reload();
                            } else {
                                showNotice(res.data || 'Ошибка импорта', 'error', $('#fs-import-trigger').parent());
                            }
                        })
                        .fail(() => {
                            apiError('Failed to import subject');
                        });
                })
                .catch(() => {});
        };

        reader.onerror = () => {
            showNotice('Ошибка чтения файла', 'error', $('#fs-import-trigger').parent());
        };

        reader.readAsText(file);
    },

    /**
     * Показывает предупреждение перед удалением с опциями «Экспорт» и «Удалить».
     * Закрытие — через крестик или ESC.
     * @param {string} name
     * @param {string} key
     * @param {string} security
     * @param {JQuery} $btn
     * @param {JQuery} $row
     * @private
     */
    _showWarningModal(name, key, security, $btn, $row) {
        const safeName = this._escapeHtml(name);
        const message =
            `Вы собираетесь удалить предмет «${safeName}».\n\n` +
            `Будут безвозвратно удалены все связанные таксономии, термины, привязки шаблонов, типовые условия и записи.\n` +
            `Рекомендуем экспортировать данные перед удалением.\n\n` +
            `Для выхода нажмите клавишу Esc или знак Х справа вверху`;

        // Используем ConfirmModal, но переопределяем текст кнопок:
        // confirmText → "Удалить всё равно", cancelText → "Экспорт"
        ConfirmModal.confirm({
            title: 'Предупреждение',
            message: message,
            confirmText: 'Удалить всё равно',
            cancelText: 'Экспорт',
        })
            .then(() => {
                // Пользователь нажал "Удалить всё равно" → показываем финальное подтверждение
                this._showFinalConfirm(name, key, security, $btn, $row);
            })
            .catch(() => {
                // Пользователь нажал "Экспорт" или закрыл модалку (ESC/крестик)
                // Проверяем, было ли это нажатие на кнопку "Экспорт"
                // Для этого вешаем временный обработчик на кнопку модалки
                const $exportBtn = $('#fs-lms-confirm-modal .fs-lms-modal-cancel');
                if ($exportBtn.is(':visible') && $exportBtn.text().trim() === 'Экспорт') {
                    // Если модалка ещё не закрылась полностью — ждём и запускаем экспорт
                    setTimeout(() => {
                        this._exportSubject(key, security, $btn);
                    }, 100);
                }
                // Если закрыли через крестик/ESC — ничего не делаем (просто отмена)
            });
    },

    /**
     * Показывает финальное подтверждение удаления.
     * @param {string} name
     * @param {string} key
     * @param {string} security
     * @param {JQuery} $btn
     * @param {JQuery} $row
     * @private
     */
    _showFinalConfirm(name, key, security, $btn, $row) {
        const safeName = this._escapeHtml(name);

        ConfirmModal.confirm({
            title: 'Подтвердите удаление',
            message: `Точно удалить предмет «${safeName}»?\nЭто действие необратимо.`,
            confirmText: 'Да, удалить',
            cancelText: 'Отмена',
        })
            .then(() => {
                this._doDelete(key, security, $btn, $row);
            })
            .catch(() => {
                // Отмена — ничего не делаем
            });
    },

    /**
     * Экспортирует предмет и скачивает JSON-файл.
     * @param {string} key
     * @param {string} security
     * @param {JQuery} $btn
     * @private
     */
    _exportSubject(key, security, $btn) {
        toggleButton($btn, true, 'Экспорт...');

        $.post(fs_lms_vars.ajaxurl, {
            action: fs_lms_vars.ajax_actions.exportSubject,
            key: key,
            security: security,
        })
            .done((res) => {
                toggleButton($btn, false);

                if (!res.success) {
                    showNotice(res.data || 'Ошибка экспорта', 'error', $btn.closest('td'));
                    return;
                }

                const blob = new Blob([JSON.stringify(res.data, null, 2)], { type: 'application/json' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'subject_' + key + '_export.json';
                a.click();
                URL.revokeObjectURL(url);
            })
            .fail(() => {
                toggleButton($btn, false);
                apiError('Failed to export subject');
            });
    },

    /**
     * Выполняет удаление предмета после подтверждения.
     * @param {string} key
     * @param {string} security
     * @param {JQuery} $btn
     * @param {JQuery} $row
     * @private
     */
    _doDelete(key, security, $btn, $row) {
        toggleButton($btn, true, '...');

        $.post(fs_lms_vars.ajaxurl, {
            action: fs_lms_vars.ajax_actions.deleteSubject,
            key: key,
            security: security,
        })
            .done((res) => {
                if (res.success) {
                    $row.fadeOut(400, () => {
                        $row.remove();
                        if ($('#tab-1 table.wp-list-table tbody tr').length === 0) {
                            location.reload();
                        }
                    });
                } else {
                    toggleButton($btn, false);
                    showNotice(res.data || 'Ошибка удаления', 'error', $btn.closest('td'));
                }
            })
            .fail(() => {
                toggleButton($btn, false);
                apiError('Failed to delete subject');
            });
    },

    /**
     * Получает nonce из доступных форм.
     * @returns {string}
     * @private
     */
    _getNonce() {
        return $('#fs-add-subject-form [name="security"]').val()
            || $('#fs-quick-edit-form [name="security"]').val()
            || '';
    },

    /**
     * Экранирует строку для безопасной вставки в HTML.
     * @param {string} str
     * @returns {string}
     * @private
     */
    _escapeHtml(str) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;',
        };
        return String(str).replace(/[&<>"']/g, (m) => map[m]);
    },
};