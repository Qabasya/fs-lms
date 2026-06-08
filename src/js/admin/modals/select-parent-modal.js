import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

export const SelectParentModal = {
    $modal: null,
    _initialized: false,

    init() {
        if ( this._initialized ) return;

        this.$modal = $( '#fs-select-parent-modal' );
        if ( ! this.$modal.length ) return;

        this._initialized = true;
        this._bindEvents();
    },

    _bindEvents() {
        $( document ).on( 'click.spm', '.js-select-existing-parent', ( e ) => {
            e.preventDefault();
            const appId = $( e.currentTarget ).data( 'application-id' );
            this.open( appId );
        } );

        this.$modal.on( 'click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .js-modal-close, .fs-close', ( e ) => {
            e.preventDefault();
            this.close();
        } );

        this.$modal.on( 'click', '#spm-search-btn', () => this._search() );

        this.$modal.on( 'keydown', '#spm-search', ( e ) => {
            if ( e.key === 'Enter' ) { e.preventDefault(); this._search(); }
        } );

        this.$modal.on( 'click', '.js-spm-select', ( e ) => {
            e.preventDefault();
            const personId = $( e.currentTarget ).data( 'person-id' );
            this._selectParent( personId );
        } );
    },

    open( applicationId ) {
        this.$modal.find( '#spm-application-id' ).val( applicationId );
        this.$modal.find( '#spm-search' ).val( '' );
        this.$modal.find( '#spm-table' ).prop( 'hidden', true );
        this.$modal.find( '#spm-no-results' ).prop( 'hidden', true );
        bindEsc( 'select_parent', () => this.close() );
        openModal( this.$modal );
    },

    close() {
        unbindEsc( 'select_parent' );
        closeModal( this.$modal );
    },

    _search() {
        const query = this.$modal.find( '#spm-search' ).val().trim();
        const vars  = window.fs_lms_applications_vars ?? {};

        $.ajax( {
            url:    fs_lms_vars.ajaxurl,
            method: 'POST',
            data:   {
                action:   fs_lms_vars.ajax_actions.searchParents,
                query:    query,
                security: vars.nonces?.manager ?? '',
            },
            success: ( res ) => {
                if ( ! res.success ) { return; }
                const rows = res.data ?? [];
                const $tbody = this.$modal.find( '#spm-tbody' ).empty();

                if ( rows.length === 0 ) {
                    this.$modal.find( '#spm-table' ).prop( 'hidden', true );
                    this.$modal.find( '#spm-no-results' ).prop( 'hidden', false );
                    return;
                }

                rows.forEach( ( r ) => {
                    if ( ! r.person_id ) return;
                    $tbody.append(
                        `<tr>
                            <td>${ r.display_name ?? '' }</td>
                            <td>${ r.email ?? '' }</td>
                            <td>
                                <button type="button"
                                    class="button button-small js-spm-select"
                                    data-person-id="${ r.person_id }">
                                    Выбрать
                                </button>
                            </td>
                        </tr>`
                    );
                } );

                this.$modal.find( '#spm-no-results' ).prop( 'hidden', true );
                this.$modal.find( '#spm-table' ).prop( 'hidden', false );
            },
            error: () => {},
        } );
    },

    _selectParent( personId ) {
        const appId = this.$modal.find( '#spm-application-id' ).val();
        const vars  = window.fs_lms_applications_vars ?? {};

        $.ajax( {
            url:    fs_lms_vars.ajaxurl,
            method: 'POST',
            data:   {
                action:           fs_lms_vars.ajax_actions.selectExistingParent,
                application_id:   appId,
                parent_person_id: personId,
                security:         vars.nonces?.selectExistingParent ?? '',
            },
            success: ( res ) => {
                if ( ! res.success ) {
                    alert( res.data || 'Ошибка назначения родителя.' );
                    return;
                }
                this.close();
                location.reload();
            },
            error: () => alert( 'Сетевая ошибка.' ),
        } );
    },
};
