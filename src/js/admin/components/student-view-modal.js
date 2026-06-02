import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

export const StudentViewModal = {
    $modal: null,
    _initialized: false,

    init() {
        if ( this._initialized ) return;

        this.$modal = $( '#fs-student-view-modal' );
        if ( ! this.$modal.length ) return;

        this._initialized = true;
        this._bindEvents();
    },

    _bindEvents() {
        $( document ).on( 'click.svm', '.js-view-student', ( e ) => {
            e.preventDefault();
            const data = $( e.currentTarget ).closest( 'tr' ).data( 'enrollment' );
            if ( data ) this.open( data );
        } );

        this.$modal.on( 'click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .js-modal-close, .fs-close', ( e ) => {
            e.preventDefault();
            this.close();
        } );

        this.$modal.on( 'click', '.fs-modal-accordion__header', ( e ) => {
            e.preventDefault();
            this._toggleAccordion( $( e.currentTarget ) );
        } );
    },

    _toggleAccordion( $header ) {
        const $body  = $( '#' + $header.attr( 'aria-controls' ) );
        const isOpen = $header.attr( 'aria-expanded' ) === 'true';

        this.$modal.find( '.fs-modal-accordion__header' ).attr( 'aria-expanded', 'false' );
        this.$modal.find( '.fs-modal-accordion__body' ).prop( 'hidden', true );

        if ( ! isOpen ) {
            $header.attr( 'aria-expanded', 'true' );
            $body.prop( 'hidden', false );
        }
    },

    open( data ) {
        this._fill( data );
        bindEsc( 'student_view', () => this.close() );
        openModal( this.$modal );
    },

    close() {
        unbindEsc( 'student_view' );
        closeModal( this.$modal );
    },

    _fill( data ) {
        const empty = '—';

        const fields = {
            subject:                  data.subject               || empty,
            group:                    data.group                 || empty,
            teacher:                  data.teacher               || empty,
            contract_no:              data.contract_no           || empty,
            contract_date:            data.contract_date         || empty,
            order_no:                 data.order_no              || empty,
            order_date:               data.order_date            || empty,
            enrolled_at:              data.enrolled_at           || empty,
            student_full_name:        data.student_full_name     || empty,
            student_birth_date:       data.student_birth_date    || empty,
            student_email:            data.student_email         || empty,
            student_phone:            data.student_phone         || empty,
            student_school:           data.student_school        || empty,
            student_grade:            data.student_grade         || empty,
            student_doc_type:         data.student_doc_type      || empty,
            student_doc_number:       data.student_doc_number    || empty,
            student_inn:              data.student_inn           || empty,
            guardian_full_name:       data.guardian_full_name    || empty,
            guardian_relation_type:   data.guardian_relation_type || empty,
            guardian_birth_date:      data.guardian_birth_date   || empty,
            guardian_email:           data.guardian_email        || empty,
            guardian_phone:           data.guardian_phone        || empty,
            guardian_doc_type:        data.guardian_doc_type     || empty,
            guardian_doc_number:      data.guardian_doc_number   || empty,
            guardian_doc_issued_by:   data.guardian_doc_issued_by || empty,
            guardian_doc_issued_date: data.guardian_doc_issued_date || empty,
            guardian_inn:             data.guardian_inn          || empty,
            guardian_address:         data.guardian_address      || empty,
        };

        Object.entries( fields ).forEach( ( [ key, value ] ) => {
            this.$modal.find( `[data-svm="${ key }"]` ).text( value );
        } );
    },
};
