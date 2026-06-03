import { ParentPersonModal } from '../components/parent-person-modal.js';

const $ = jQuery;

const NONCES  = () => fs_lms_applications_vars.nonces;
const ACTIONS = () => fs_lms_vars.ajax_actions;
const AJAX_URL = () => fs_lms_vars.ajaxurl;

export const ParentPersonModalManager = {
    _initialized: false,

    init() {
        if ( this._initialized || ! ParentPersonModal._initialized ) return;
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
            ParentPersonModal.setEditing( true );
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

        $( document ).on( 'click.ppmm_delete', '#fs-parent-person-modal .js-pmm-delete', ( e ) => {
            e.preventDefault();
            this._delete();
        } );
    },

    _openModal( $btn ) {
        const personId = parseInt( $btn.data( 'personId' ), 10 ) || 0;
        const wpUserId = parseInt( $btn.data( 'wpUserId' ), 10 ) || 0;
        const rowData  = $btn.closest( 'tr' ).data( 'parent' ) || {};

        ParentPersonModal.reset();
        ParentPersonModal.setPersonId( personId );
        ParentPersonModal.setWpUserId( wpUserId );

        // Немедленно из данных строки — без ожидания AJAX
        ParentPersonModal.fill( {
            display_name:     $btn.data( 'displayName' )       || '',
            email:            $btn.data( 'email' )              || '',
            phone:            rowData.phone                     || '',
            relation_type:    rowData.relation_type             || '',
            dependent_name:   rowData.children                  || '',
            birth_date:       rowData.birth_date                || '',
            child_doc_number: rowData.child_doc_number          || '',
            child_inn:        rowData.child_inn                 || '',
            child_birth_date: rowData.child_birth_date          || '',
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
                doc_number: pii.doc_number || '',
                inn:        pii.inn        || '',
                address:    pii.address    || '',
            } );
        } );
    },

    _revealAll() {
        const personId = ParentPersonModal.getPersonId();
        const wpUserId = ParentPersonModal.getWpUserId();
        if ( ! personId ) return;

        // Два независимых запроса — сбой одного не блокирует другой
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
                if ( res.success ) ParentPersonModal.fillRevealed( {
                    password: res.data.password || '',
                } );
            } );
        }
    },

    _save() {
        const personId = ParentPersonModal.getPersonId();
        if ( ! personId ) return;
        const allowed = [ 'full_name', 'doc_number', 'inn', 'phone', 'email', 'address' ];
        const edit = ParentPersonModal.getEditData();
        const payload = {
            action:    ACTIONS().updatePerson,
            security:  NONCES().updatePerson,
            person_id: personId,
        };
        allowed.forEach( k => { if ( edit[ k ] ) payload[ k ] = edit[ k ]; } );
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

    _delete() {
        const id = ParentPersonModal.getPersonId();
        if ( ! id ) return;
        if ( ! confirm( 'Удалить персональные данные? Физическое удаление через 30 дней.' ) ) return;
        $.post( AJAX_URL(), {
            action:    ACTIONS().requestPiiDeletion,
            person_id: id,
            security:  NONCES().deletePii,
        } ).done( r => {
            if ( r.success ) ParentPersonModal.close();
        } );
    },
};
