import { ExpelModal } from '../modals/expel-modal.js';

const $ = jQuery;

const NONCES   = () => fs_lms_applications_vars.nonces;
const ACTIONS  = () => fs_lms_vars.ajax_actions;
const AJAX_URL = () => fs_lms_vars.ajaxurl;

export const StudentsTable = {
    _initialized:       false,
    _multiGroupQueue:   [],
    _singleGroupPending: [],

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

        $( document ).on( 'fs:student:expel-partial', ( e, { studentId, remaining } ) => {
            const $row = $( `tr[data-wp-user-id="${ studentId }"]` );
            if ( ! $row.length ) return;

            const esc = ( str ) => $( '<div>' ).text( String( str || '' ) ).html();

            const subjectHtml  = remaining.map( r => esc( r.subject_name ) ).join( '<br>' );
            const groupHtml    = remaining.map( r => esc( r.group_title ) ).join( '<br>' );
            const scheduleHtml = remaining.map( r => esc( r.schedule || '—' ) ).join( '<br>' );
            const contractHtml = remaining.map( r => esc( r.contract_no || '—' ) ).join( '<br>' );

            // Колонки: 0=чекбокс, 1=ФИО, 2=Предмет, 3=Группа, 4=Расписание, 5=Договор, 6=Действия
            $row.find( 'td' ).eq( 2 ).html( subjectHtml );
            $row.find( 'td' ).eq( 3 ).html( groupHtml );
            $row.find( 'td' ).eq( 4 ).html( scheduleHtml );
            $row.find( 'td' ).eq( 5 ).html( contractHtml );

            // Обновляем data-expel-enrollments кнопки в row-actions
            const newEnrollments = remaining.map( r => ( {
                record_id:    r.record_id,
                subject_name: r.subject_name,
                group_title:  r.group_title,
            } ) );
            $row.find( '.js-expel-student' ).attr( 'data-expel-enrollments', JSON.stringify( newEnrollments ) );
        } );
    },

    _applyBulk() {
        const action = $( '#js-bulk-action' ).val();
        if ( action === 'export' ) { this._applyBulkExport(); return; }
        if ( action !== 'expel' ) return;

        const $checked = $( '.js-student-cb:checked' );
        if ( ! $checked.length ) return;

        const singleGroup = [];
        const multiGroup  = [];

        $checked.each( ( _, el ) => {
            let enrollments = [];
            try {
                const raw = $( el ).closest( 'tr' ).find( '.js-expel-student' ).attr( 'data-expel-enrollments' );
                if ( raw ) enrollments = JSON.parse( raw );
            } catch ( _ ) { /* ignore */ }

            const student = {
                id:          $( el ).val(),
                name:        $( el ).data( 'student-name' ) || '',
                enrollments,
            };

            if ( enrollments.length > 1 ) {
                multiGroup.push( student );
            } else {
                singleGroup.push( student );
            }
        } );

        if ( multiGroup.length ) {
            this._multiGroupQueue    = [ ...multiGroup ];
            this._singleGroupPending = singleGroup;
            this._processNextMultiGroup();
        } else {
            ExpelModal.openBulk( singleGroup );
        }
    },

    _applyBulkExport() {
        $( '.js-student-cb:checked' ).each( ( _, el ) => {
            const personId = parseInt(
                $( el ).closest( 'tr' ).find( '.js-export-person' ).data( 'personId' ), 10
            );
            if ( ! personId ) return;
            $.post( AJAX_URL(), {
                action:    ACTIONS().exportPii,
                person_id: personId,
                security:  NONCES().exportPii,
            } ).done( ( r ) => {
                if ( r.success && r.data.download_url ) {
                    const a = document.createElement( 'a' );
                    a.href = r.data.download_url;
                    document.body.appendChild( a );
                    a.click();
                    document.body.removeChild( a );
                }
            } );
        } );
    },

    _processNextMultiGroup() {
        if ( ! this._multiGroupQueue.length ) {
            if ( this._singleGroupPending.length ) {
                const pending            = this._singleGroupPending;
                this._singleGroupPending = [];
                ExpelModal.openBulk( pending );
            }
            return;
        }

        const student = this._multiGroupQueue.shift();
        ExpelModal.setAfterClose( () => this._processNextMultiGroup() );
        ExpelModal.open( student.id, student.name, student.enrollments );
    },
};
