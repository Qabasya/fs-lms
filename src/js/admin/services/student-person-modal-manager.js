import { StudentPersonModal } from '../components/student-person-modal.js';

const $ = jQuery;

const NONCES   = () => fs_lms_applications_vars.nonces;
const ACTIONS  = () => fs_lms_vars.ajax_actions;
const AJAX_URL = () => fs_lms_vars.ajaxurl;

export const StudentPersonModalManager = {
    _initialized: false,

    init() {
        if ( this._initialized || ! StudentPersonModal._initialized ) return;
        this._initialized = true;
        this._bindEvents();
    },

    _bindEvents() {
        $( document ).on( 'click.spmm', '.js-view-person[data-person-type="student"]', ( e ) => {
            e.preventDefault();
            this._openModal( $( e.currentTarget ) );
        } );

        $( document ).on( 'click.spmm_reveal', '#fs-student-person-modal .js-reveal-all', ( e ) => {
            e.preventDefault();
            this._revealAll();
        } );

        $( document ).on( 'click.spmm_edit', '#fs-student-person-modal .js-pmm-edit', ( e ) => {
            e.preventDefault();
            StudentPersonModal.setEditing( true );
        } );

        $( document ).on( 'click.spmm_cancel', '#fs-student-person-modal .js-pmm-cancel', ( e ) => {
            e.preventDefault();
            StudentPersonModal.setEditing( false );
        } );

        $( document ).on( 'click.spmm_save', '#fs-student-person-modal .js-pmm-save', ( e ) => {
            e.preventDefault();
            this._save();
        } );

        $( document ).on( 'click.spmm_export', '#fs-student-person-modal .js-pmm-export', ( e ) => {
            e.preventDefault();
            this._export();
        } );

        $( document ).on( 'click.spmm_delete', '#fs-student-person-modal .js-pmm-delete', ( e ) => {
            e.preventDefault();
            this._delete();
        } );

        $( document ).on( 'fs-lms:spm-regenerate-password', ( e, { wpUserId, $btn } ) => {
            this._regeneratePassword( wpUserId, $btn );
        } );
    },

    _openModal( $btn ) {
        const personId = parseInt( $btn.data( 'personId' ), 10 ) || 0;
        const wpUserId = parseInt( $btn.data( 'wpUserId' ), 10 ) || 0;
        const rowData  = $btn.closest( 'tr' ).data( 'enrollment' ) || {};

        StudentPersonModal.reset();
        StudentPersonModal.setPersonId( personId );
        StudentPersonModal.setWpUserId( wpUserId );

        // Немедленно из данных строки — без ожидания AJAX
        StudentPersonModal.fill( {
            display_name:  $btn.data( 'displayName' )   || '',
            full_name:     $btn.data( 'displayName' )   || '',
            email:         $btn.data( 'email' )          || '',
            phone:         rowData.student_phone         || '',
            contract_no:   rowData.contract_no           || '',
            subject:       rowData.subject               || '',
            group:         rowData.group                 || '',
            school:        rowData.student_school        || '',
            grade:         String( rowData.student_grade || '' ),
            birth_date:    rowData.student_birth_date    || '',
            guardian_name: rowData.guardian_full_name    || '',
        } );

        StudentPersonModal.open();
        if ( ! personId ) return;

        // AJAX для расписания, маскированных PII-полей и логина
        $.post( AJAX_URL(), {
            action:    ACTIONS().getPersonData,
            person_id: personId,
            security:  NONCES().manager,
        } ).done( ( res ) => {
            if ( ! res.success ) return;
            const enr = ( res.data.enrollments || [] )[0] || {};
            const pii = res.data.masked_pii || {};
            StudentPersonModal.fill( {
                schedule:   enr.schedule   || '',
                doc_number: pii.doc_number || '',
                inn:        pii.inn        || '',
                login:      res.data.login || '',
            } );
        } );
    },

    _revealAll() {
        const personId = StudentPersonModal.getPersonId();
        const wpUserId = StudentPersonModal.getWpUserId();
        if ( ! personId ) return;

        $.post( AJAX_URL(), {
            action:    ACTIONS().revealAllPersonPii,
            person_id: personId,
            reason:    'admin_userlist_reveal',
            security:  NONCES().revealPii,
        } ).done( ( res ) => {
            if ( res.success ) StudentPersonModal.fillRevealed( res.data );
        } );

        if ( wpUserId ) {
            $.post( AJAX_URL(), {
                action:   ACTIONS().revealUserCredentials,
                user_id:  wpUserId,
                security: NONCES().revealPii,
            } ).done( ( res ) => {
                if ( res.success ) {
                    StudentPersonModal.fillRevealed( { password: res.data.password || '' } );
                } else {
                    StudentPersonModal.showRegenerateButton( wpUserId );
                }
            } );
        }
    },

    _save() {
        const personId = StudentPersonModal.getPersonId();
        if ( ! personId ) return;
        const allowed = [ 'full_name', 'doc_number', 'inn', 'phone', 'email', 'address' ];
        const edit = StudentPersonModal.getEditData();
        const payload = {
            action:    ACTIONS().updatePerson,
            security:  NONCES().updatePerson,
            person_id: personId,
        };
        allowed.forEach( k => {
            // Не отправлять PII-поля, если они ещё в маске
            if ( edit[ k ] && ! edit[ k ].includes( '•' ) ) payload[ k ] = edit[ k ];
        } );
        $.post( AJAX_URL(), payload ).done( ( res ) => {
            if ( res.success ) StudentPersonModal.setEditing( false );
        } );
    },

    _export() {
        const id = StudentPersonModal.getPersonId();
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
        const id = StudentPersonModal.getPersonId();
        if ( ! id ) return;
        if ( ! confirm( 'Удалить персональные данные? Физическое удаление через 30 дней.' ) ) return;
        $.post( AJAX_URL(), {
            action:    ACTIONS().requestPiiDeletion,
            person_id: id,
            security:  NONCES().deletePii,
        } ).done( r => {
            if ( r.success ) StudentPersonModal.close();
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
                StudentPersonModal.fillRevealed( { password: res.data.password || '' } );
                $btn.remove();
            } else {
                $btn.prop( 'disabled', false );
            }
        } );
    },
};
