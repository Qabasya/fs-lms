import {
    toggleButton,
    apiError,
    escapeHtml,
} from '../modules/utils.js';
import { ConfirmModal } from '../components/confirm-modal.js';
import { AcademicPeriodModal } from '../components/academic-period-modal';

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
            is_current: parseInt($link.data('current'), 10) === 1
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
            is_current:  formData.is_current
        })
            .done((res) => {
                if (res.success) {
                    location.reload();
                } else if (res.data?.error_code === 'duplicate_id') {
                    AcademicPeriodModal.setIdError(res.data.message);
                    AcademicPeriodModal.setSaveState(false);
                } else {
                    alert(res.data?.message || res.data || 'Ошибка сохранения.');
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
        const $btn = $(e.currentTarget);
        const id = $btn.data('key');
        const name = $btn.data('name');
        const $row = $btn.closest('tr');

        const safeName = escapeHtml(name);

        ConfirmModal.confirm({
            title: 'Подтвердите удаление',
            message: `Вы уверены, что хотите полностью удалить учебный период «${safeName}»?\nЭто действие необратимо.`,
            confirmText: 'Да, удалить',
            cancelText: 'Отмена',
            size: 'sm',
            isDanger: true
        })
            .then(() => {
                this._doDelete(id, $btn, $row);
            })
            .catch(() => {});
    },

    _doDelete(id, $btn, $row) {
        toggleButton($btn, true, '...');

        $.post(fs_lms_vars.ajaxurl, {
            action:   fs_lms_vars.ajax_actions.deleteAcademicPeriod,
            security: fs_lms_vars.nonces.manager,
            id:       id
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
                    alert(res.data?.message || 'Ошибка удаления');
                }
            })
            .fail(() => {
                toggleButton($btn, false);
                apiError('Failed to delete academic period');
            });
    }
};
