import {
    toggleButton,
    apiError,
    escapeHtml,
} from '../modules/utils.js';
import { ConfirmModal } from '../components/confirm-modal.js';
import { GroupModal } from '../components/group-modal';

const $ = jQuery;

export const GroupModalManager = {
    init() {
        GroupModal.init();
        ConfirmModal.init();

        this._bindEvents();
    },

    _bindEvents() {
        $(document).on('click', '.js-open-group-modal', (e) => this._handleOpenAddModal(e));
        $(document).on('click', '.js-delete-group', (e) => this._handleDelete(e));

        GroupModal.onSave((formData) => this._handleSave(formData));
    },

    _handleOpenAddModal(e) {
        e.preventDefault();
        GroupModal.open('add');
    },

    _handleSave(formData) {
        GroupModal.setSaveState(true);

        $.post(fs_lms_vars.ajaxurl, {
            action:     fs_lms_vars.ajax_actions.saveStudentGroup,
            security:   fs_lms_vars.nonces.manager,
            title:      formData.title,
            period_id:  formData.period_id,
            subject_id: formData.subject_id,
            teacher_id: formData.teacher_id,
        })
            .done((res) => {
                if (res.success) {
                    location.reload();
                } else {
                    alert(res.data?.message || res.data || 'Ошибка сохранения группы.');
                    GroupModal.setSaveState(false);
                }
            })
            .fail(() => {
                apiError('Failed to save student group');
                GroupModal.setSaveState(false);
            });
    },

    _handleDelete(e) {
        e.preventDefault();
        const $btn = $(e.currentTarget);
        const id = $btn.data('id');
        const $row = $btn.closest('tr');
        const name = $row.find('.column-title strong').text().trim();

        ConfirmModal.confirm({
            title: 'Подтвердите удаление',
            message: `Вы уверены, что хотите удалить группу «${escapeHtml(name)}»?\nЭто действие необратимо.`,
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
            action:   fs_lms_vars.ajax_actions.deleteStudentGroup,
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
                    alert(res.data?.message || 'Ошибка удаления группы.');
                }
            })
            .fail(() => {
                toggleButton($btn, false);
                apiError('Failed to delete student group');
            });
    }
};
