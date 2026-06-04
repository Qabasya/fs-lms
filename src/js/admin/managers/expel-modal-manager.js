import { ExpelModal } from '../modals/expel-modal.js';
import { apiError, showModalError, clearModalError } from '../modules/utils.js';

const $ = jQuery;

/**
 * Глобальный менеджер отчисления.
 *
 * Одиночное: любой элемент .js-expel-student с data-expel-student-id / data-expel-student-name.
 * Массовое:  StudentsTable вызывает ExpelModal.openBulk(students[]) напрямую.
 *
 * После отчисления стреляет событие:
 *   $(document).trigger('fs:student:expelled', { studentId })
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
            showModalError( 'Выберите причину отчисления.', ExpelModal.$modal );
            return;
        }

        if ( formData.is_other_empty ) {
            showModalError( 'Уточните конкретнее причину отчисления.', ExpelModal.$modal );
            return;
        }

        clearModalError( ExpelModal.$modal );

        if ( formData.student_ids ) {
            this._doExpelBulk( formData );
        } else {
            this._doExpelSingle( formData );
        }
    },

    _doExpelSingle( formData ) {
        console.log('Одинарная')
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
                    showModalError( res.data?.message || res.data || 'Ошибка отчисления.', ExpelModal.$modal );
                    ExpelModal.setSaving( false );
                }
            } )
            .fail( () => {
                apiError( 'Failed to expel student' );
                ExpelModal.setSaving( false );
            } );
    },

    _doExpelBulk( formData ) {
        console.log('булк')
        ExpelModal.setSaving( true );

        let done   = 0;
        let errors = 0;
        const total = formData.student_ids.length;

        formData.student_ids.forEach( ( studentId ) => {
            $.post( fs_lms_vars.ajaxurl, {
                action:     fs_lms_vars.ajax_actions.expelStudent,
                security:   fs_lms_vars.nonces.expulsion,
                student_id: studentId,
                reason:     formData.reason,
            } )
                .done( ( res ) => {
                    if ( res.success ) {
                        $( document ).trigger( 'fs:student:expelled', { studentId: parseInt( studentId, 10 ) } );
                    } else {
                        errors++;
                        showModalError( res.data?.message || 'Ошибка отчисления.', ExpelModal.$modal );
                    }
                    if ( ++done === total ) this._onBulkDone( errors, formData );
                } )
                .fail( () => {
                    errors++;
                    apiError( 'Bulk expel failed' );
                    if ( ++done === total ) this._onBulkDone( errors, formData );
                } );
        } );
    },

    _onBulkDone( errors, formData ) {
        if ( errors === 0 ) {
            ExpelModal.close();
            $( '#js-bulk-action' ).val( '' );
            if ( typeof formData.afterExpel === 'function' ) {
                formData.afterExpel();
            }
        } else {
            ExpelModal.setSaving( false );
        }
    },
};
