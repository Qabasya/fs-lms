import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

export const ApplicationViewModal = {
    $modal: null,
    _initialized: false,

    init() {
        if ( this._initialized ) return;

        this.$modal = $( '#fs-application-view-modal' );
        if ( ! this.$modal.length ) return;

        this._initialized = true;
        this._bindEvents();
    },

    _bindEvents() {
        $( document ).on( 'click.avm', '.js-view-application', ( e ) => {
            e.preventDefault();
            this.open( $( e.currentTarget ) );
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

    open( $trigger ) {
        const d = $trigger.data();
        this._fill( d );
        bindEsc( 'app_view', () => this.close() );
        openModal( this.$modal );
    },

    close() {
        unbindEsc( 'app_view' );
        closeModal( this.$modal );
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

    _fill( d ) {
        const empty = '—';
        const f = ( v ) => v || empty;

        const map = {
            s_last_name:      d.sLastName,
            s_first_name:     d.sFirstName,
            s_middle_name:    d.sMiddleName,
            s_birth_date:     d.sBirthDate,
            s_email:          d.sEmail,
            s_phone:          d.sPhone,
            s_school:         d.sSchool,
            s_grade:          d.sGrade,
            s_doc_type:       d.sDocType,
            s_doc_number:     d.sDocNumber,
            s_inn:            d.sInn,
            p_last_name:      d.pLastName,
            p_first_name:     d.pFirstName,
            p_middle_name:    d.pMiddleName,
            p_birth_date:     d.pBirthDate,
            p_relation_type:  d.pRelationType,
            p_email:          d.pEmail,
            p_phone:          d.pPhone,
            p_doc_type:       d.pDocType,
            p_doc_number:     d.pDocNumber,
            p_doc_issued_by:  d.pDocIssuedBy,
            p_doc_issued_date: d.pDocIssuedDate,
            p_inn:            d.pInn,
            p_address:        d.pAddress,
        };

        Object.entries( map ).forEach( ( [ key, value ] ) => {
            this.$modal.find( `[data-avm="${ key }"]` ).text( f( value ) );
        } );
    },
};
