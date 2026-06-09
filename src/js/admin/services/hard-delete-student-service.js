import '../_types.js';
import { ConfirmModal } from '../modals/confirm-modal.js';
import { toggleButton, apiError, showNotice } from '../modules/utils.js';

const $ = jQuery;

export const HardDeleteStudentService = {
    init() {
        ConfirmModal.init();
        $(document).on('click', '.js-hard-delete-student', (e) => this._handleDelete(e));
    },

    _handleDelete(e) {
        e.preventDefault();
        const $btn     = $(e.currentTarget);
        const personId = $btn.data('person-id');
        const $row     = $btn.closest('tr');

        ConfirmModal.confirm({
            title:       'Безвозвратное удаление',
            message:     'Ученик и все связанные данные (зачисления, документы, учётная запись) будут удалены безвозвратно. Это действие нельзя отменить.',
            confirmText: 'Удалить',
            cancelText:  'Отмена',
            size:        'sm',
            isDanger:    true,
        })
            .then(() => this._doDelete(personId, $btn, $row))
            .catch(() => {});
    },

    _doDelete(personId, $btn, $row) {
        toggleButton($btn, true, '...');

        $.post(fs_lms_vars.ajaxurl, {
            action:    fs_lms_vars.ajax_actions.hardDeleteStudent,
            security:  fs_lms_vars.nonces.hardDeleteStudent,
            person_id: personId,
        })
            .done((res) => {
                if (res.success) {
                    if ($row.length) {
                        $row.fadeOut(400, () => $row.remove());
                    } else {
                        location.reload();
                    }
                } else {
                    toggleButton($btn, false);
                    showNotice(res.data?.message || 'Ошибка удаления.', 'error', $btn.closest('.wrap, .fs-person-detail'));
                }
            })
            .fail(() => {
                toggleButton($btn, false);
                apiError('Failed to hard delete student');
            });
    },
};
