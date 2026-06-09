import {
    toggleButton,
    apiError,
    escapeHtml,
    showNotice,
} from '../modules/utils.js';
import { ConfirmModal } from '../modals/confirm-modal.js';
import { AcademicPeriodModal } from '../modals/academic-period-modal';

const $ = jQuery;

export const AcademicPeriodModalManager = {
    init() {
        AcademicPeriodModal.init();
        ConfirmModal.init();

        this._bindEvents();
    },

    _bindEvents() {
        $('.js-add-period').on('click', (e) => this._handleOpenAddModal(e));
        $(document).on('click', '.js-edit-period', (e) => this._handleOpenEditModal(e));
        $(document).on('click', '.js-delete-period', (e) => this._handleDelete(e));

        AcademicPeriodModal.onSave((formData) => this._handleSave(formData));
    },

    _handleOpenAddModal(e) {
        e.preventDefault();
        AcademicPeriodModal.open('add');
    },

    _handleOpenEditModal(e) {
        e.preventDefault();
        const $link = $(e.currentTarget);

        AcademicPeriodModal.open('edit', {
            id:         $link.data('id'),
            name:       $link.data('name'),
            start_date: $link.data('start-date'),
            end_date:   $link.data('end-date'),
            is_current: parseInt($link.data('current'), 10) === 1,
        });
    },

    _handleSave(formData) {
        AcademicPeriodModal.setSaveState(true);

        $.post(fs_lms_vars.ajaxurl, {
            action:      fs_lms_vars.ajax_actions.saveAcademicPeriod,
            security:    fs_lms_vars.nonces.manager,
            action_type: formData.action_type,
            id:          formData.id,
            name:        formData.name,
            start_date:  formData.start_date,
            end_date:    formData.end_date,
            is_current:  formData.is_current,
        })
            .done((res) => {
                if (res.success) {
                    location.reload();
                } else if (res.data?.error_code === 'duplicate_id') {
                    AcademicPeriodModal.setIdError(res.data.message);
                    AcademicPeriodModal.setSaveState(false);
                } else {
                    showNotice(res.data?.message || res.data || 'Ошибка сохранения.', 'error', AcademicPeriodModal.$modal.find('.fs-lms-modal-body'));
                    AcademicPeriodModal.setSaveState(false);
                }
            })
            .fail(() => {
                apiError('Failed to save academic period');
                AcademicPeriodModal.setSaveState(false);
            });
    },

    _handleDelete(e) {
        e.preventDefault();
        const $btn  = $(e.currentTarget);
        const id    = $btn.data('key');
        const name  = $btn.data('name');
        const $row  = $btn.closest('tr');

        toggleButton($btn, true, '...');

        $.post(fs_lms_vars.ajaxurl, {
            action:    fs_lms_vars.ajax_actions.checkPeriodDeletion,
            security:  fs_lms_vars.nonces.deletePeriod,
            period_id: id,
        })
            .done((res) => {
                toggleButton($btn, false);

                if (!res.success) {
                    showNotice(res.data?.message || 'Ошибка проверки периода.', 'error', $row.closest('.wrap'));
                    return;
                }

                const studentCount = res.data?.student_count ?? 0;
                const groupCount   = res.data?.group_count   ?? 0;
                const safeName     = escapeHtml(name);

                let message;
                if (studentCount === 0) {
                    message = `Вы уверены, что хотите полностью удалить учебный период «${safeName}»?\nЭто действие необратимо.`;
                } else {
                    message =
                        `Период «${safeName}» содержит ${groupCount} гр. и ${studentCount} уч.\n` +
                        `Ученики без других зачислений будут удалены безвозвратно вместе со всеми данными.\n` +
                        `Это действие необратимо.`;
                }

                ConfirmModal.confirm({
                    title:       'Удалить период?',
                    message,
                    confirmText: 'Да, удалить',
                    cancelText:  'Отмена',
                    size:        studentCount > 0 ? 'md' : 'sm',
                    isDanger:    true,
                })
                    .then(() => this._doDelete(id, $btn, $row))
                    .catch(() => {});
            })
            .fail(() => {
                toggleButton($btn, false);
                apiError('Failed to check period deletion');
            });
    },

    _doDelete(id, $btn, $row) {
        toggleButton($btn, true, '...');

        $.post(fs_lms_vars.ajaxurl, {
            action:    fs_lms_vars.ajax_actions.deletePeriod,
            security:  fs_lms_vars.nonces.deletePeriod,
            period_id: id,
        })
            .done((res) => {
                if (res.success) {
                    $row.fadeOut(400, () => {
                        $row.remove();
                        if ($row.parent().children('tr').length === 0) {
                            location.reload();
                        }
                    });
                } else {
                    toggleButton($btn, false);
                    showNotice(res.data?.message || 'Ошибка удаления периода.', 'error', $row.closest('.wrap'));
                }
            })
            .fail(() => {
                toggleButton($btn, false);
                apiError('Failed to delete period');
            });
    },
};
