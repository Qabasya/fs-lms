import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

export const ParentViewModal = {
    $modal: null,
    _initialized: false,

    init() {
        if ( this._initialized ) return;

        this.$modal = $( '#fs-parent-view-modal' );
        if ( ! this.$modal.length ) return;

        this._initialized = true;
        this._bindEvents();
    },

    _bindEvents() {
        $( document ).on( 'click.pvm', '.js-view-parent', ( e ) => {
            e.preventDefault();
            const data = $( e.currentTarget ).closest( 'tr' ).data( 'parent' );
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

    open( data ) {
        this._fill( data );
        bindEsc( 'parent_view', () => this.close() );
        openModal( this.$modal );
    },

    close() {
        unbindEsc( 'parent_view' );
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

    _fill( data ) {
        const empty = '—';

        const fields = {
            full_name:       data.full_name       || empty,
            relation_type:   data.relation_type   || empty,
            birth_date:      data.birth_date       || empty,
            email:           data.email            || empty,
            phone:           data.phone            || empty,
            doc_type:        data.doc_type         || empty,
            doc_number:      data.doc_number       || empty,
            doc_issued_by:   data.doc_issued_by    || empty,
            doc_issued_date: data.doc_issued_date  || empty,
            inn:             data.inn              || empty,
            address:         data.address          || empty,
            children:        data.children         || empty,
        };

        Object.entries( fields ).forEach( ( [ key, value ] ) => {
            this.$modal.find( `[data-pvm="${ key }"]` ).text( value );
        } );
    },
};
