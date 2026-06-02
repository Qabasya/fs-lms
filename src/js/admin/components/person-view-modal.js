import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;
const REVEAL_TIMEOUT_MS = 30000;

export const PersonViewModal = {
    $modal:    null,
    _personId: null,
    _initialized: false,

    init() {
        if ( this._initialized ) return;

        this.$modal = $( '#fs-person-view-modal' );
        if ( ! this.$modal.length ) return;

        this._initialized = true;
        this._bindEvents();
    },

    _bindEvents() {
        $( document ).on( 'click.pvm', '.js-view-person', ( e ) => {
            e.preventDefault();
            this.open( $( e.currentTarget ).data() );
        } );

        this.$modal.on( 'click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .js-modal-close, .fs-close', ( e ) => {
            e.preventDefault();
            this.close();
        } );

        this.$modal.on( 'click', '.fs-modal-accordion__header', ( e ) => {
            e.preventDefault();
            this._toggleAccordion( $( e.currentTarget ) );
        } );

        this.$modal.on( 'click', '.js-pvm-reveal', ( e ) => {
            e.preventDefault();
            this._reveal( $( e.currentTarget ) );
        } );
    },

    open( data ) {
        this._personId = data.personId || null;
        this._fill( data );
        this._resetRevealFields();
        bindEsc( 'person_view', () => this.close() );
        openModal( this.$modal );
    },

    close() {
        unbindEsc( 'person_view' );
        closeModal( this.$modal, () => {
            this._resetRevealFields();
            this._personId = null;
        } );
    },

    _fill( data ) {
        const empty = '—';

        this.$modal.find( '#pvm-title' ).text( data.displayName || empty );
        this.$modal.find( '[data-pvm="display_name"]' ).text( data.displayName || empty );
        this.$modal.find( '[data-pvm="email"]' ).text( data.email || empty );
        this.$modal.find( '[data-pvm="birth_date"]' ).text( data.birthDate || empty );

        const isStudent = data.personType === 'student';
        const isParent  = data.personType === 'parent';

        this.$modal.find( '.pvm-student-fields' ).prop( 'hidden', ! isStudent );
        this.$modal.find( '.pvm-parent-fields' ).prop( 'hidden', ! isParent );

        if ( isStudent ) {
            this.$modal.find( '[data-pvm="school"]' ).text( data.school || empty );
            this.$modal.find( '[data-pvm="grade"]' ).text( data.grade  || empty );
        }

        if ( isParent ) {
            this.$modal.find( '[data-pvm="relation_type"]' ).text( data.relationType || empty );
        }
    },

    _resetRevealFields() {
        this.$modal.find( '.fs-pii-field' ).each( function () {
            const $wrap = $( this );
            $wrap.find( '.fs-pii-field__revealed' ).hide().text( '' );
            $wrap.find( '.fs-pii-field__masked' ).show();
            const $btn = $wrap.find( '.js-pvm-reveal' );
            clearTimeout( $btn.data( 'timer' ) );
            $btn.prop( 'disabled', false ).html(
                '<span class="dashicons dashicons-visibility"></span> Показать'
            );
        } );
    },

    _reveal( $btn ) {
        if ( ! this._personId || $btn.prop( 'disabled' ) ) return;

        const $wrap = $btn.closest( '.fs-pii-field' );
        const field = $btn.data( 'field' );

        $btn.prop( 'disabled', true ).text( '...' );

        $.post( fs_lms_vars.ajaxurl, {
            action:    fs_lms_vars.ajax_actions.revealPiiField,
            person_id: this._personId,
            field:     field,
            reason:    'admin_userlist_view',
            security:  fs_lms_applications_vars.nonces.reveal,
        } )
        .done( ( res ) => {
            if ( ! res.success ) {
                $btn.prop( 'disabled', false ).html(
                    '<span class="dashicons dashicons-visibility"></span> Показать'
                );
                return;
            }

            $wrap.find( '.fs-pii-field__masked' ).hide();
            $wrap.find( '.fs-pii-field__revealed' ).text( res.data.value ).show();
            $btn.prop( 'disabled', false ).html(
                '<span class="dashicons dashicons-hidden"></span> Скрыть'
            );

            const timer = setTimeout( () => this._hideField( $wrap, $btn ), REVEAL_TIMEOUT_MS );
            $btn.data( 'timer', timer ).off( 'click.hide' ).on( 'click.hide', ( ev ) => {
                ev.preventDefault();
                clearTimeout( timer );
                this._hideField( $wrap, $btn );
            } );
        } )
        .fail( () => {
            $btn.prop( 'disabled', false ).html(
                '<span class="dashicons dashicons-visibility"></span> Показать'
            );
        } );
    },

    _hideField( $wrap, $btn ) {
        $wrap.find( '.fs-pii-field__revealed' ).hide().text( '' );
        $wrap.find( '.fs-pii-field__masked' ).show();
        $btn.off( 'click.hide' ).html(
            '<span class="dashicons dashicons-visibility"></span> Показать'
        );
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
};
