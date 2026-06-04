import { apiError } from '../modules/utils.js';

const $ = jQuery;

export const StudentsTable = {
    _initialized: false,

    init() {
        if ( this._initialized ) return;
        if ( ! $( '.fs-lms-students' ).length ) return;
        this._initialized = true;
        this._bindEvents();
    },

    _bindEvents() {
        $( document ).on( 'change', '#js-select-all-students', ( e ) => {
            $( '.js-student-cb' ).prop( 'checked', e.currentTarget.checked );
        } );

        $( document ).on( 'change', '.js-student-cb', () => {
            const total   = $( '.js-student-cb' ).length;
            const checked = $( '.js-student-cb:checked' ).length;
            $( '#js-select-all-students' ).prop( 'indeterminate', checked > 0 && checked < total );
            $( '#js-select-all-students' ).prop( 'checked', checked === total );
        } );

        $( document ).on( 'click', '#js-bulk-apply', () => this._applyBulk() );

        $( document ).on( 'fs:student:expelled', ( e, { studentId } ) => {
            $( `tr[data-wp-user-id="${ studentId }"]` ).fadeOut( 300, function () { $( this ).remove(); } );
        } );
    },

    _applyBulk() {
        const action = $( '#js-bulk-action' ).val();
        if ( action !== 'expel' ) return;

        const $checked = $( '.js-student-cb:checked' );
        if ( ! $checked.length ) return;

        const students = $checked.map( ( _, el ) => ( {
            id:   $( el ).val(),
            name: $( el ).data( 'student-name' ) || '',
        } ) ).get();

        if ( ! confirm( `Отчислить ${ students.length } студент(ов)? Это действие необратимо.` ) ) return;

        let done = 0;
        const onDone = ( studentId ) => {
            $( document ).trigger( 'fs:student:expelled', { studentId: parseInt( studentId, 10 ) } );
            if ( ++done === students.length ) {
                $( '#js-bulk-action' ).val( '' );
            }
        };

        students.forEach( ( { id } ) => {
            $.post( fs_lms_vars.ajaxurl, {
                action:     fs_lms_vars.ajax_actions.expelStudent,
                security:   fs_lms_vars.nonces.expulsion,
                student_id: id,
                reason:     'Массовое отчисление',
            } )
                .done( ( res ) => {
                    if ( res.success ) {
                        onDone( id );
                    } else {
                        alert( res.data?.message || 'Ошибка отчисления.' );
                        done++;
                    }
                } )
                .fail( () => {
                    apiError( 'Bulk expel failed' );
                    done++;
                } );
        } );
    },
};
