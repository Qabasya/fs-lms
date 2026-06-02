import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;
const REVEAL_TIMEOUT_MS = 30000;

export const PersonDetail = {
    _initialized: false,

    init() {
        if ( this._initialized ) return;
        if ( ! $( '.fs-person-detail' ).length ) return;

        this._initialized = true;
        this._bindTabs();
        this._bindReveal();
        this._bindEditModal();
        this._bindAddRepresentativeModal();
        this._bindReplaceRepresentativeModal();
        this._bindDelete();
        this._bindExport();
    },

    // ── Tabs ──────────────────────────────────────────────────────────────

    _bindTabs() {
        $( '.fs-person-detail .nav-tab' ).on( 'click', ( e ) => {
            e.preventDefault();
            const target = $( e.currentTarget ).data( 'tab' );
            $( '.fs-person-detail .nav-tab' ).removeClass( 'nav-tab-active' );
            $( e.currentTarget ).addClass( 'nav-tab-active' );
            $( '.fs-person-detail .fs-tab-panel' ).hide();
            $( `#${ target }` ).show();
        } );
    },

    // ── Reveal PII ────────────────────────────────────────────────────────

    _bindReveal() {
        $( document ).on( 'click', '.js-reveal-pii', ( e ) => {
            e.preventDefault();
            const $btn    = $( e.currentTarget );
            const $wrap   = $btn.closest( '.fs-pii-field' );
            const field   = $btn.data( 'field' );
            const personId = $btn.data( 'person-id' );

            if ( $btn.prop( 'disabled' ) ) return;
            $btn.prop( 'disabled', true ).text( '...' );

            $.post( fs_lms_vars.ajaxurl, {
                action:    fs_lms_vars.ajax_actions.revealPiiField,
                person_id: personId,
                field:     field,
                reason:    'admin_reveal',
                security:  fs_lms_person_vars.nonces.reveal,
            } )
            .done( ( res ) => {
                if ( ! res.success ) {
                    $btn.prop( 'disabled', false ).text( 'Показать' );
                    return;
                }
                const $masked   = $wrap.find( '.fs-pii-field__masked' );
                const $revealed = $wrap.find( '.fs-pii-field__revealed' );

                $masked.hide();
                $revealed.text( res.data.value ).show();
                $btn.text( 'Скрыть' ).prop( 'disabled', false );

                const timer = setTimeout( () => this._hideField( $wrap, $btn ), REVEAL_TIMEOUT_MS );
                $btn.data( 'timer', timer );

                $btn.off( 'click.hide' ).on( 'click.hide', ( ev ) => {
                    ev.preventDefault();
                    clearTimeout( $btn.data( 'timer' ) );
                    this._hideField( $wrap, $btn );
                } );
            } )
            .fail( () => {
                $btn.prop( 'disabled', false ).text( 'Показать' );
            } );
        } );
    },

    _hideField( $wrap, $btn ) {
        $wrap.find( '.fs-pii-field__revealed' ).hide().text( '' );
        $wrap.find( '.fs-pii-field__masked' ).show();
        $btn.off( 'click.hide' ).text( 'Показать' );
    },

    // ── Edit person modal ─────────────────────────────────────────────────

    _bindEditModal() {
        const $modal = $( '#fs-edit-person-modal' );
        if ( ! $modal.length ) return;

        $( document ).on( 'click', '.js-open-edit-person', ( e ) => {
            e.preventDefault();
            bindEsc( 'edit_person', () => closeModal( $modal ) );
            openModal( $modal );
        } );

        $modal.on( 'click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .js-modal-close', ( e ) => {
            e.preventDefault();
            unbindEsc( 'edit_person' );
            closeModal( $modal );
        } );

        $modal.find( 'form' ).on( 'submit', ( e ) => {
            e.preventDefault();
            const data = {};
            $modal.find( '[name]' ).each( function () {
                if ( $( this ).val() ) data[ $( this ).attr( 'name' ) ] = $( this ).val();
            } );
            data.action    = fs_lms_vars.ajax_actions.updatePerson;
            data.security  = fs_lms_person_vars.nonces.update;

            $.post( fs_lms_vars.ajaxurl, data ).done( ( res ) => {
                if ( res.success ) location.reload();
            } );
        } );
    },

    // ── Add representative modal ──────────────────────────────────────────

    _bindAddRepresentativeModal() {
        const $modal = $( '#fs-add-representative-modal' );
        if ( ! $modal.length ) return;

        $( document ).on( 'click', '.js-open-add-representative', ( e ) => {
            e.preventDefault();
            bindEsc( 'add_rep', () => closeModal( $modal ) );
            openModal( $modal );
        } );

        $modal.on( 'click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .js-modal-close', ( e ) => {
            e.preventDefault();
            unbindEsc( 'add_rep' );
            closeModal( $modal );
        } );

        $modal.find( 'form' ).on( 'submit', ( e ) => {
            e.preventDefault();
            const formData = $modal.find( 'form' ).serialize();
            $.post( fs_lms_vars.ajaxurl, formData + '&action=' + fs_lms_vars.ajax_actions.addRepresentative + '&security=' + fs_lms_person_vars.nonces.add_representative )
            .done( ( res ) => {
                if ( res.success ) location.reload();
            } );
        } );
    },

    // ── Replace representative modal ──────────────────────────────────────

    _bindReplaceRepresentativeModal() {
        const $modal = $( '#fs-replace-representative-modal' );
        if ( ! $modal.length ) return;

        $( document ).on( 'click', '.js-open-replace-representative', ( e ) => {
            e.preventDefault();
            const relId = $( e.currentTarget ).data( 'rel-id' );
            $modal.find( '[name="relationship_id"]' ).val( relId );
            bindEsc( 'replace_rep', () => closeModal( $modal ) );
            openModal( $modal );
        } );

        $modal.on( 'click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .js-modal-close', ( e ) => {
            e.preventDefault();
            unbindEsc( 'replace_rep' );
            closeModal( $modal );
        } );

        $modal.find( 'form' ).on( 'submit', ( e ) => {
            e.preventDefault();
            const formData = $modal.find( 'form' ).serialize();
            $.post( fs_lms_vars.ajaxurl, formData + '&action=' + fs_lms_vars.ajax_actions.replaceRepresentative + '&security=' + fs_lms_person_vars.nonces.replace_representative )
            .done( ( res ) => {
                if ( res.success ) location.reload();
            } );
        } );
    },

    // ── Delete PII ────────────────────────────────────────────────────────

    _bindDelete() {
        $( document ).on( 'click', '.js-delete-pii', ( e ) => {
            e.preventDefault();
            if ( ! confirm( 'Удалить персональные данные? Это действие нельзя отменить немедленно — данные будут обезличены через 30 дней.' ) ) return;

            const personId = $( e.currentTarget ).data( 'person-id' );
            $.post( fs_lms_vars.ajaxurl, {
                action:    fs_lms_vars.ajax_actions.requestPiiDeletion,
                person_id: personId,
                security:  fs_lms_person_vars.nonces.delete,
            } ).done( ( res ) => {
                if ( res.success ) location.reload();
            } );
        } );
    },

    // ── Export PII ────────────────────────────────────────────────────────

    _bindExport() {
        $( document ).on( 'click', '.js-export-pii', ( e ) => {
            e.preventDefault();
            const personId = $( e.currentTarget ).data( 'person-id' );
            $.post( fs_lms_vars.ajaxurl, {
                action:    fs_lms_vars.ajax_actions.exportPii,
                person_id: personId,
                security:  fs_lms_person_vars.nonces.export,
            } ).done( ( res ) => {
                if ( res.success && res.data.download_url ) {
                    window.location.href = res.data.download_url;
                }
            } );
        } );
    },
};
