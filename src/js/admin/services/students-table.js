import { ExpelModal } from '../modals/expel-modal.js';

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

        ExpelModal.openBulk( students );
    },
};
