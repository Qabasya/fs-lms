import { ApplicationEnrollmentModal } from '../modals/application-enrollment-modal.js';
import { showModalError, clearModalError } from '../modules/utils.js';

const $ = jQuery;
const appVars = window.fs_lms_applications_vars;

export const ApplicationEnrollmentModalManager = {
    init() {
        ApplicationEnrollmentModal.init();
        if ( ! ApplicationEnrollmentModal._initialized ) return;

        this._loadModalOptions();
        this._bindEvents();
    },

    _loadModalOptions() {
        const $modal    = $( '#fs-application-enrollment-modal' );
        const periods   = JSON.parse( $modal.attr( 'data-periods' )  || '[]' );
        const subjects  = JSON.parse( $modal.attr( 'data-subjects' ) || '[]' );
        const currentId = $modal.attr( 'data-current-period' ) || '';

        ApplicationEnrollmentModal.populatePeriods( periods, currentId );
        ApplicationEnrollmentModal.populateSubjects( subjects );
    },

    _bindEvents() {
        $( document ).on( 'click', '.js-enrollment-application', ( e ) => {
            e.preventDefault();
            const $btn   = $( e.currentTarget );
            const appId  = $btn.data( 'id' );
            const status = $btn.data( 'status' );

            if ( status === 'ready_for_review' ) {
                this._handleStartThenOpen( appId, $btn );
            } else {
                this._handleOpen( appId );
            }
        } );

        $( document ).on( 'click', '.js-start-enrollment', ( e ) => {
            e.preventDefault();
            this._handleStartEnrollment( $( e.currentTarget ) );
        } );

        // Reload groups when period or subject changes
        $( document ).on( 'change', '#enroll-period, #enroll-subject', () => {
            this._reloadGroups();
        } );

        ApplicationEnrollmentModal.onEnroll( ( data ) => this._handleEnroll( data ) );
        ApplicationEnrollmentModal.onClose( ( data ) => this._handleRevertStatus( data.application_id ) );
    },

    _handleOpen( appId ) {
        ApplicationEnrollmentModal.open( appId );
        this._loadApplicationData( appId );
    },

    _handleStartEnrollment( $btn ) {
        $btn.prop( 'disabled', true );

        $.post( fs_lms_vars.ajaxurl, {
            action:         fs_lms_vars.ajax_actions.startEnrollment,
            security:       appVars.nonces.manager,
            application_id: $btn.data( 'id' ),
        } )
            .done( ( res ) => {
                if ( res.success ) {
                    location.reload();
                } else {
                    showModalError( res.data?.message || res.data || 'Ошибка.', ApplicationEnrollmentModal.$modal );
                    $btn.prop( 'disabled', false );
                }
            } )
            .fail( () => {
                showModalError( 'Ошибка соединения.', ApplicationEnrollmentModal.$modal );
                $btn.prop( 'disabled', false );
            } );
    },

    _handleStartThenOpen( appId, $btn ) {
        $btn.prop( 'disabled', true );

        $.post( fs_lms_vars.ajaxurl, {
            action:         fs_lms_vars.ajax_actions.startEnrollment,
            security:       appVars.nonces.manager,
            application_id: appId,
        } )
            .done( ( res ) => {
                $btn.prop( 'disabled', false );
                if ( res.success ) {
                    this._handleOpen( appId );
                } else {
                    showModalError( res.data?.message || res.data || 'Ошибка.', ApplicationEnrollmentModal.$modal );
                }
            } )
            .fail( () => {
                showModalError( 'Ошибка соединения.', ApplicationEnrollmentModal.$modal );
                $btn.prop( 'disabled', false );
            } );
    },

    _loadApplicationData( appId ) {
        $.post( fs_lms_vars.ajaxurl, {
            action:         fs_lms_vars.ajax_actions.getApplicationData,
            security:       appVars.nonces.manager,
            application_id: appId,
        } )
            .done( ( res ) => {
                if ( res.success ) {
                    ApplicationEnrollmentModal.populateStudentData( res.data.student );
                    ApplicationEnrollmentModal.populateParentData( res.data.parent );
                }
            } );
    },

    _reloadGroups() {
        const periodId  = $( '#enroll-period' ).val();
        const subjectId = $( '#enroll-subject' ).val();

        if ( ! periodId || ! subjectId ) {
            ApplicationEnrollmentModal.populateGroups( [] );
            return;
        }

        $.post( fs_lms_vars.ajaxurl, {
            action:    fs_lms_vars.ajax_actions.getStudentGroups,
            security:  appVars.nonces.manager,
            period_id:  periodId,
            subject_id: subjectId,
        } )
            .done( ( res ) => {
                if ( res.success ) {
                    ApplicationEnrollmentModal.populateGroups( res.data );
                }
            } );
    },

    _handleEnroll( data ) {
        ApplicationEnrollmentModal.setEnrollState( true );

        $.post( fs_lms_vars.ajaxurl, {
            action:         fs_lms_vars.ajax_actions.enrollStudent,
            security:       appVars.nonces.enroll,
            application_id: data.application_id,
            contract_no:    data.contract_no,
            contract_date:  data.contract_date,
            order_no:       data.order_no,
            order_date:     data.order_date,
            enrolled_at:    data.enrolled_at,
            period_key:     data.period_key,
            subject_key:    data.subject_key,
            group_id:       data.group_id,
            send_email_auto: data.send_email_auto,
        } )
            .done( ( res ) => {
                if ( res.success ) {
                    ApplicationEnrollmentModal.markCompleted();
                    ApplicationEnrollmentModal.close();
                    location.reload();
                } else {
                    showModalError( res.data?.message || res.data || 'Ошибка зачисления.', ApplicationEnrollmentModal.$modal );
                    ApplicationEnrollmentModal.setEnrollState( false );
                }
            } )
            .fail( () => {
                showModalError( 'Ошибка соединения.', ApplicationEnrollmentModal.$modal );
                ApplicationEnrollmentModal.setEnrollState( false );
            } );
    },

    _handleRevertStatus( appId ) {
        $.post( fs_lms_vars.ajaxurl, {
            action:         fs_lms_vars.ajax_actions.cancelEnrollment,
            security:       appVars.nonces.manager,
            application_id: appId,
        } );
    },
};