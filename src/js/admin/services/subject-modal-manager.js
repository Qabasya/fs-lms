import '../_types.js';
import { SubjectModal } from '../components/subject-modal.js';
import { ConfirmModal } from '../components/confirm-modal.js';
import {
    toggleButton,
    apiError,
    showNotice,
    escapeHtml,
} from '../modules/utils.js';

const $ = jQuery;

export const SubjectModalManager = {
    init() {
        SubjectModal.init();
        SubjectModal.onSave((formData) => this._handleSave(formData));
        this._bindEvents();
    },

    _bindEvents() {
        $(document).on('click', '.open-quick-edit', (e) => this._handleQuickEdit(e));
        $(document).on('click', '.delete-subject', (e) => this._handleDelete(e));
        $(document).on('click', '.js-export-subject', (e) => this._handleExport(e));
        $('#fs-import-trigger').on('click', () => $('#fs-import-file').trigger('click'));
        $('#fs-import-file').on('change', (e) => this._handleImport(e));
    },

    _handleSave(formData) {
        SubjectModal.setSaveState(true);

        $.post(fs_lms_vars.ajaxurl, {
            action:      fs_lms_vars.ajax_actions.storeSubject,
            name:        formData.name,
            key:         formData.key,
            tasks_count: formData.tasks_count,
            security:    formData.security,
        })
            .done((res) => {
                if (res.success) {
                    location.reload();
                } else if (res.data?.error_code === 'duplicate_key') {
                    SubjectModal.setKeyError(res.data.message);
                    SubjectModal.setSaveState(false);
                } else {
                    showNotice(res.data?.message || res.data || 'Ошибка сохранения', 'error', SubjectModal.$modal);
                    SubjectModal.setSaveState(false);
                }
            })
            .fail(() => {
                apiError('Failed to save subject');
                SubjectModal.setSaveState(false);
            });
    },

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

    _handleDelete(e) {
        e.preventDefault();
        const $btn = $(e.target);
        const key = $btn.data('key');
        const $row = $btn.closest('tr');
        const name = $row.find('strong a').text().trim();
        const security = this._getNonce();

        this._showWarningModal(name, key, security, $btn, $row);
    },

    _handleExport(e) {
        e.preventDefault();
        const key = $(e.target).data('key');
        const security = this._getNonce();
        this._exportSubject(key, security, $(e.target));
    },

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
            const safeName = escapeHtml(name);

            ConfirmModal.confirm({
                title: 'Импорт предмета',
                message: `Импортировать «${safeName}»?\nБудут восстановлены: таксономии, термины, шаблоны, boilerplates и записи.`,
                confirmText: 'Импортировать',
                cancelText: 'Отмена',
            })
                .then(() => {
                    $.post(fs_lms_vars.ajaxurl, {
                        action:   fs_lms_vars.ajax_actions.importSubject,
                        json:     ev.target.result,
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

    _showWarningModal(name, key, security, $btn, $row) {
        const safeName = escapeHtml(name);
        const message =
            `Вы собираетесь удалить предмет «${safeName}».\n\n` +
            `Будут безвозвратно удалены все связанные таксономии, термины, привязки шаблонов, типовые условия и записи.\n` +
            `Рекомендуем экспортировать данные перед удалением.\n\n` +
            `Для выхода нажмите клавишу Esc или знак Х справа вверху`;

        ConfirmModal.confirm({
            title: 'Предупреждение',
            message: message,
            size: 'lg',
            isDanger: true,
            confirmText: 'Перейти к удалению',
            cancelText: 'Экспортировать и удалить',
        })
            .then(() => {
                setTimeout(() => {
                    this._showFinalConfirm(name, key, security, $btn, $row);
                }, 250);
            })
            .catch((reason) => {
                if (reason === 'cancel') {
                    this._exportSubject(key, security, $btn, () => {
                        setTimeout(() => {
                            this._showFinalConfirm(name, key, security, $btn, $row);
                        }, 250);
                    });
                }
            });
    },

    _showFinalConfirm(name, key, security, $btn, $row) {
        const safeName = escapeHtml(name);

        ConfirmModal.confirm({
            title: 'Подтвердите удаление',
            message: `Точно удалить предмет «${safeName}»?\nЭто действие необратимо.`,
            size: 'sm',
            isDanger: true,
            confirmText: 'Да, удалить',
            cancelText: 'Отмена',
        })
            .then(() => {
                this._doDelete(key, security, $btn, $row);
            })
            .catch(() => {});
    },

    _exportSubject(key, security, $btn, onComplete = null) {
        toggleButton($btn, true, 'Экспорт...');

        $.post(fs_lms_vars.ajaxurl, {
            action:   fs_lms_vars.ajax_actions.exportSubject,
            key:      key,
            security: security,
        })
            .done((res) => {
                if (res.success) {
                    const blob = new Blob([JSON.stringify(res.data, null, 2)], { type: 'application/json' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `subject_${key}_export.json`;
                    a.click();
                    URL.revokeObjectURL(url);
                } else {
                    showNotice(res.data || 'Ошибка экспорта', 'error', $btn.closest('td'));
                }
            })
            .fail(() => apiError('Failed to export subject'))
            .always(() => {
                toggleButton($btn, false);
                if (typeof onComplete === 'function') {
                    onComplete();
                }
            });
    },

    _doDelete(key, security, $btn, $row) {
        toggleButton($btn, true, '...');

        $.post(fs_lms_vars.ajaxurl, {
            action:   fs_lms_vars.ajax_actions.deleteSubject,
            key:      key,
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

    _getNonce() {
        return $('#fs-add-subject-form [name="security"]').val()
            || $('#fs-quick-edit-form [name="security"]').val()
            || '';
    },
};
