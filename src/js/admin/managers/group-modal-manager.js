import {
    toggleButton,
    apiError,
    escapeHtml,
    showNotice,
} from '../modules/utils.js';
import { ConfirmModal } from '../modals/confirm-modal.js';
import { GroupModal } from '../modals/group-modal';

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
            action:         fs_lms_vars.ajax_actions.saveStudentGroup,
            security:       fs_lms_vars.nonces.manager,
            title:          formData.title,
            period_id:      formData.period_id,
            subject_id:     formData.subject_id,
            teacher_id:     formData.teacher_id,
            schedule_json:  formData.schedule_json,
        })
            .done((res) => {
                if (res.success) {
                    location.reload();
                } else {
                    showNotice(res.data?.message || res.data || 'Ошибка сохранения группы.', 'error', GroupModal.$modal.find('.fs-lms-modal-body'));
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
        const $btn  = $(e.currentTarget);
        const id    = $btn.data('id');
        const $row  = $btn.closest('tr');
        const name  = $row.find('.column-title strong').text().trim();

        toggleButton($btn, true, '...');

        $.post(fs_lms_vars.ajaxurl, {
            action:   fs_lms_vars.ajax_actions.checkGroupDeletion,
            security: fs_lms_vars.nonces.deleteGroup,
            group_id: id,
        })
            .done((res) => {
                toggleButton($btn, false);

                if (!res.success) {
                    showNotice(res.data?.message || 'Ошибка проверки группы.', 'error', $row.closest('.wrap'));
                    return;
                }

                const studentCount = res.data?.student_count ?? 0;

                if (studentCount === 0) {
                    this._doDelete(id, $btn, $row);
                    return;
                }

                this._loadStudentsAndConfirm(id, $btn, $row, name, studentCount);
            })
            .fail(() => {
                toggleButton($btn, false);
                apiError('Failed to check group deletion');
            });
    },

    _loadStudentsAndConfirm(id, $btn, $row, groupName, studentCount) {
        $.post(fs_lms_vars.ajaxurl, {
            action:   fs_lms_vars.ajax_actions.getStudentsByGroup,
            group_id: id,
            security: fs_lms_vars.nonces.manager,
        })
            .done((res) => {
                const students = res.data || [];
                const studentList = students
                    .map(s => `• ${escapeHtml(s.name || s.last_name || String(s.id))}`)
                    .join('\n');

                const safeName = escapeHtml(groupName);
                const orphanNote = 'Ученики, у которых нет других зачислений, будут удалены безвозвратно вместе со всеми данными.';
                const message = `В группе «${safeName}» ${studentCount} уч.:\n${studentList}\n\n${orphanNote}`;

                ConfirmModal.confirm({
                    title:       'Удалить группу?',
                    message,
                    confirmText: 'Удалить',
                    cancelText:  'Отмена',
                    size:        'sm',
                    isDanger:    true,
                })
                    .then(() => this._doDelete(id, $btn, $row))
                    .catch(() => {});
            })
            .fail(() => {
                const safeName = escapeHtml(groupName);
                ConfirmModal.confirm({
                    title:       'Удалить группу?',
                    message:     `В группе «${safeName}» ${studentCount} уч. Ученики без других зачислений будут удалены безвозвратно. Продолжить?`,
                    confirmText: 'Удалить',
                    cancelText:  'Отмена',
                    size:        'sm',
                    isDanger:    true,
                })
                    .then(() => this._doDelete(id, $btn, $row))
                    .catch(() => {});
            });
    },

    _doDelete(id, $btn, $row) {
        toggleButton($btn, true, '...');

        $.post(fs_lms_vars.ajaxurl, {
            action:   fs_lms_vars.ajax_actions.deleteGroup,
            security: fs_lms_vars.nonces.deleteGroup,
            group_id: id,
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
                    showNotice(res.data?.message || 'Ошибка удаления группы.', 'error', $row.closest('.wrap'));
                }
            })
            .fail(() => {
                toggleButton($btn, false);
                apiError('Failed to delete group');
            });
    },
};
