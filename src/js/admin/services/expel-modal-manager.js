import { ExpelModal } from '../components/expel-modal.js';
import { apiError } from '../modules/utils.js';

const $ = jQuery;

/**
 * ExpelModalManager — глобальный менеджер отчисления студента.
 *
 * Триггер из любого места: любой элемент с классом `js-expel-student`
 * и атрибутами `data-expel-student-id` (WP user ID) и `data-expel-student-name`.
 *
 * После успешного отчисления стреляет кастомное событие на document:
 *   $(document).trigger('fs:student:expelled', { studentId })
 *
 * Каждая страница слушает это событие и убирает строку из своего UI.
 */
export const ExpelModalManager = {
    _initialized: false,

    init() {
        if ( this._initialized ) return;
        this._initialized = true;

        ExpelModal.init();

        $( document ).on( 'click', '.js-expel-student', ( e ) => this._handleTrigger( e ) );

        ExpelModal.onConfirm( ( formData ) => this._doExpel( formData ) );
    },

    _handleTrigger( e ) {
        e.preventDefault();
        e.stopPropagation();

        const $el         = $( e.currentTarget );
        const studentId   = $el.data( 'expel-student-id' );
        const studentName = $el.data( 'expel-student-name' ) || '';

        if ( ! studentId ) return;

        ExpelModal.open( studentId, studentName );
    },

    _doExpel( formData ) {
        if ( ! formData.reason ) {
            alert( 'Выберите причину отчисления.' );
            return;
        }

        if ( formData.is_other_empty ) {
            alert( 'Уточните конкретнее причину отчисления.' );
            return;
        }

        ExpelModal.setSaving( true );

        $.post( fs_lms_vars.ajaxurl, {
            action:     fs_lms_vars.ajax_actions.expelStudent,
            security:   fs_lms_vars.nonces.expulsion,
            student_id: formData.student_id,
            reason:     formData.reason,
        } )
            .done( ( res ) => {
                if ( res.success ) {
                    ExpelModal.close();
                    $( document ).trigger( 'fs:student:expelled', { studentId: formData.student_id } );
                } else {
                    alert( res.data?.message || res.data || 'Ошибка отчисления.' );
                    ExpelModal.setSaving( false );
                }
            } )
            .fail( () => {
                apiError( 'Failed to expel student' );
                ExpelModal.setSaving( false );
            } );
    },
};
