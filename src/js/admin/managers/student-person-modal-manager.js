import { StudentPersonModal } from '../modals/student-person-modal.js';

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
            this._startEditing();
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

        $( document ).on( 'fs:student:expelled', ( e, { studentId } ) => {
            StudentPersonModal.close();
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
        const studentName = $btn.data( 'displayName' ) || '';
        $( '#fs-student-person-modal .js-expel-student' )
            .data( 'expel-student-id', wpUserId )
            .data( 'expel-student-name', studentName )
            .attr( 'data-expel-student-id', wpUserId )
            .attr( 'data-expel-student-name', studentName );

        // Немедленно из данных строки — без ожидания AJAX
        StudentPersonModal.fill( {
            display_name:  $btn.data( 'displayName' )      || '',
            last_name:     rowData.student_last_name        || '',
            first_name:    rowData.student_first_name       || '',
            middle_name:   rowData.student_middle_name      || '',
            email:         $btn.data( 'email' )             || '',
            login:         $btn.data( 'userLogin' )         || '',
            phone:         rowData.student_phone            || '',
            contract_no:   rowData.contract_no              || '',
            subject:       rowData.subject                  || '',
            group:         rowData.group                    || '',
            schedule:      rowData.schedule                 || '',
            school:        rowData.student_school           || '',
            grade:         String( rowData.student_grade    || '' ),
            birth_date:    rowData.student_birth_date       || '',
            guardian_name: rowData.guardian_full_name       || '',
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
                last_name:   enr.last_name   || '',
                first_name:  enr.first_name  || '',
                middle_name: enr.middle_name || '',
                schedule:    enr.schedule    || '',
                birth_date:  enr.birth_date  || '',
                school:      enr.school      || '',
                grade:       enr.grade       || '',
                doc_number:  pii.doc_number  || '',
                inn:         pii.inn         || '',
                login:       res.data.login  || '',
                password:    res.data.password || '',
            } );
        } );
    },

    _startEditing() {
        const personId = StudentPersonModal.getPersonId();
        const wpUserId = StudentPersonModal.getWpUserId();

        if ( ! personId ) {
            StudentPersonModal.setEditing( true );
            return;
        }

        const piiPromise = $.post( AJAX_URL(), {
            action:    ACTIONS().revealAllPersonPii,
            person_id: personId,
            reason:    'admin_userlist_edit',
            security:  NONCES().revealPii,
        } ).done( ( res ) => {
            if ( res.success ) StudentPersonModal.fillRevealed( res.data );
        } );

        const credPromise = wpUserId
            ? $.post( AJAX_URL(), {
                action:   ACTIONS().revealUserCredentials,
                user_id:  wpUserId,
                security: NONCES().revealPii,
            } ).done( ( res ) => {
                if ( res.success ) StudentPersonModal.fillRevealed( { password: res.data.password || '' } );
            } )
            : $.Deferred().resolve();

        $.when( piiPromise, credPromise ).always( () => {
            StudentPersonModal.setEditing( true );
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
        const allowed = [
            'last_name', 'first_name', 'middle_name',
            'phone', 'email', 'birth_date',
            'login', 'password',
            'school',
            'doc_number', 'inn',
        ];
        const edit = StudentPersonModal.getEditData();
        const payload = {
            action:    ACTIONS().updatePerson,
            security:  NONCES().updatePerson,
            person_id: personId,
        };
        allowed.forEach( k => {
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
