import { ParentPersonModal } from '../modals/parent-person-modal.js';

const $ = jQuery;

const NONCES   = () => fs_lms_applications_vars.nonces;
const ACTIONS  = () => fs_lms_vars.ajax_actions;
const AJAX_URL = () => fs_lms_vars.ajaxurl;

export const ParentPersonModalManager = {
    _initialized: false,

    init() {
        if ( this._initialized ) return;
        ParentPersonModal.init();
        if ( ! ParentPersonModal._initialized ) return;
        this._initialized = true;
        this._bindEvents();
    },

    _bindEvents() {
        $( document ).on( 'click.ppmm', '.js-view-person[data-person-type="parent"]', ( e ) => {
            e.preventDefault();
            this._openModal( $( e.currentTarget ) );
        } );

        $( document ).on( 'click.ppmm_reveal', '#fs-parent-person-modal .js-reveal-all', ( e ) => {
            e.preventDefault();
            this._revealAll();
        } );

        $( document ).on( 'click.ppmm_edit', '#fs-parent-person-modal .js-pmm-edit', ( e ) => {
            e.preventDefault();
            this._startEditing();
        } );

        $( document ).on( 'click.ppmm_cancel', '#fs-parent-person-modal .js-pmm-cancel', ( e ) => {
            e.preventDefault();
            ParentPersonModal.setEditing( false );
        } );

        $( document ).on( 'click.ppmm_save', '#fs-parent-person-modal .js-pmm-save', ( e ) => {
            e.preventDefault();
            this._save();
        } );

        $( document ).on( 'click.ppmm_export', '#fs-parent-person-modal .js-pmm-export', ( e ) => {
            e.preventDefault();
            this._export();
        } );

        $( document ).on( 'fs:student:expelled', () => {
            ParentPersonModal.close();
        } );

        $( document ).on( 'fs-lms:regenerate-password', ( e, { wpUserId, $btn } ) => {
            this._regeneratePassword( wpUserId, $btn );
        } );
    },

    _openModal( $btn ) {
        const personId = parseInt( $btn.data( 'personId' ), 10 ) || 0;
        const wpUserId = parseInt( $btn.data( 'wpUserId' ), 10 ) || 0;
        const rowData  = $btn.closest( 'tr' ).data( 'parent' ) || {};

        ParentPersonModal.reset();
        ParentPersonModal.setPersonId( personId );
        ParentPersonModal.setWpUserId( wpUserId );

        ParentPersonModal.fill( {
            display_name:   $btn.data( 'displayName' ) || '',
            last_name:      rowData.last_name           || '',
            first_name:     rowData.first_name          || '',
            middle_name:    rowData.middle_name         || '',
            email:          $btn.data( 'email' )        || '',
            phone:          rowData.phone               || '',
            dependent_name: rowData.children            || '',
            birth_date:     rowData.birth_date          || '',
        } );

        ParentPersonModal.open();
        if ( ! personId ) return;

        // AJAX только для маскированных PII-полей родителя
        $.post( AJAX_URL(), {
            action:    ACTIONS().getPersonData,
            person_id: personId,
            security:  NONCES().manager,
        } ).done( ( res ) => {
            if ( ! res.success ) return;
            const pii = res.data.masked_pii || {};
            ParentPersonModal.fill( {
                doc_number:     pii.doc_number     || '',
                inn:            pii.inn            || '',
                address:        pii.address        || '',
                password:       pii.password       || '',
                doc_issued_by:  pii.doc_issued_by  || '',
                doc_issued_date: pii.doc_issued_date || '',
            } );
        } );
    },

    _startEditing() {
        const personId = ParentPersonModal.getPersonId();
        const wpUserId = ParentPersonModal.getWpUserId();

        if ( ! personId ) {
            ParentPersonModal.setEditing( true );
            return;
        }

        const piiPromise = $.post( AJAX_URL(), {
            action:    ACTIONS().revealAllPersonPii,
            person_id: personId,
            reason:    'admin_userlist_edit',
            security:  NONCES().revealPii,
        } ).done( ( res ) => {
            if ( res.success ) ParentPersonModal.fillRevealed( res.data );
        } );

        const credPromise = wpUserId
            ? $.post( AJAX_URL(), {
                action:   ACTIONS().revealUserCredentials,
                user_id:  wpUserId,
                security: NONCES().revealPii,
            } ).done( ( res ) => {
                if ( res.success ) ParentPersonModal.fillRevealed( { password: res.data.password || '' } );
            } )
            : $.Deferred().resolve();

        $.when( piiPromise, credPromise ).always( () => {
            ParentPersonModal.setEditing( true );
        } );
    },

    _revealAll() {
        const personId = ParentPersonModal.getPersonId();
        const wpUserId = ParentPersonModal.getWpUserId();
        if ( ! personId ) return;

        $.post( AJAX_URL(), {
            action:    ACTIONS().revealAllPersonPii,
            person_id: personId,
            reason:    'admin_userlist_reveal',
            security:  NONCES().revealPii,
        } ).done( ( res ) => {
            if ( res.success ) ParentPersonModal.fillRevealed( res.data );
        } );

        if ( wpUserId ) {
            $.post( AJAX_URL(), {
                action:   ACTIONS().revealUserCredentials,
                user_id:  wpUserId,
                security: NONCES().revealPii,
            } ).done( ( res ) => {
                if ( res.success ) ParentPersonModal.fillRevealed( { password: res.data.password || '' } );
            } );
        }
    },

    _save() {
        const personId = ParentPersonModal.getPersonId();
        if ( ! personId ) return;
        const allowed = [
            'last_name', 'first_name', 'middle_name',
            'phone', 'email', 'password',
            'birth_date', 'doc_number', 'inn', 'address',
            'doc_issued_by', 'doc_issued_date',
        ];
        const edit = ParentPersonModal.getEditData();
        const payload = {
            action:    ACTIONS().updatePerson,
            security:  NONCES().updatePerson,
            person_id: personId,
        };
        allowed.forEach( k => {
            if ( edit[ k ] && ! edit[ k ].includes( '•' ) ) payload[ k ] = edit[ k ];
        } );
        $.post( AJAX_URL(), payload ).done( ( res ) => {
            if ( res.success ) ParentPersonModal.setEditing( false );
        } );
    },

    _export() {
        const id = ParentPersonModal.getPersonId();
        if ( ! id ) return;
        $.post( AJAX_URL(), {
            action:    ACTIONS().exportPii,
            person_id: id,
            security:  NONCES().exportPii,
        } ).done( r => {
            if ( r.success && r.data.download_url ) window.location.href = r.data.download_url;
        } );
    },

    _regeneratePassword( wpUserId, $btn ) {
        $btn.prop( 'disabled', true );
        $.post( AJAX_URL(), {
            action:   ACTIONS().regenerateUserPassword,
            user_id:  wpUserId,
            security: NONCES().revealPii,
        } ).done( ( res ) => {
            if ( res.success ) {
                ParentPersonModal.fillRevealed( { password: res.data.password || '' } );
                $btn.remove();
            } else {
                $btn.prop( 'disabled', false );
            }
        } );
    },
};
