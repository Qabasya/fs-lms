import {
    toggleButton,
    apiError,
    escapeHtml,
    showNotice,
    showModalError,
} from '../modules/utils.js';
import { ConfirmModal } from '../modals/confirm-modal.js';
import { GroupModal } from '../modals/group-modal';
import { ExpelModal } from '../modals/expel-modal.js';

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
                    showNotice(res.data?.message || res.data || 'Ошибка сохранения группы.', 'error', GroupModal.$modal.find( '.fs-lms-modal-body' ));
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
        const safeName = escapeHtml(name);

        ConfirmModal.confirm({
            title:       'Удалить группу?',
            message:     `Удаление группы «${safeName}» приведёт к отчислению всех её учеников.\nПосле подтверждения вы выберете причину отчисления.`,
            confirmText: 'Продолжить',
            cancelText:  'Отмена',
            size:        'sm',
            isDanger:    true,
        })
            .then( () => this._fetchStudentsAndExpel( id, $btn, $row ) )
            .catch( () => {} );
    },

    _fetchStudentsAndExpel( id, $btn, $row ) {
        toggleButton( $btn, true, '...' );

        $.post( fs_lms_vars.ajaxurl, {
            action:   fs_lms_vars.ajax_actions.getStudentsByGroup,
            group_id: id,
            security: fs_lms_vars.nonces.manager,
        } )
            .done( ( res ) => {
                toggleButton( $btn, false );

                if ( ! res.success ) {
                    showNotice( 'Ошибка загрузки списка учеников.', 'error', $row.closest( '.wrap' ) );
                    return;
                }

                const students = res.data || [];
                const doDelete = () => this._doDelete( id, $btn, $row );

                if ( ! students.length ) {
                    doDelete();
                    return;
                }

                ExpelModal.openBulk( students, { afterExpel: doDelete } );
            } )
            .fail( () => {
                toggleButton( $btn, false );
                apiError( 'Failed to load students for group' );
            } );
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
                    showNotice(res.data?.message || 'Ошибка удаления группы.', 'error', $row.closest('.wrap'));
                }
            })
            .fail(() => {
                toggleButton($btn, false);
                apiError('Failed to delete student group');
            });
    }
};
