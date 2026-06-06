import { ApplicationReviewModal } from '../modals/application-review-modal.js';
import { showModalError, clearModalError } from '../modules/utils.js';

const $ = jQuery;
const appVars = window.fs_lms_applications_vars;

export const ApplicationReviewModalManager = {
    init() {
        ApplicationReviewModal.init();
        if ( ! ApplicationReviewModal._initialized ) return;

        this._bindEvents();
    },

    _bindEvents() {
        $( document ).on( 'click', '.js-review-application', ( e ) => {
            e.preventDefault();
            this._handleOpen( $( e.currentTarget ) );
        } );

        ApplicationReviewModal.onSave( ( data ) => this._handleSave( data ) );
    },

    _handleOpen( $btn ) {
        ApplicationReviewModal.open( {
            id:                    $btn.data( 'id' ),
            student_last_name:     $btn.data( 's-last-name' ),
            student_first_name:    $btn.data( 's-first-name' ),
            student_middle_name:   $btn.data( 's-middle-name' ),
            student_birth_date:    $btn.data( 's-birth-date' ),
            student_doc_type:      $btn.data( 's-doc-type' ),
            student_doc_number:    $btn.data( 's-doc-number' ),
            student_inn:           $btn.data( 's-inn' ),
            parent_last_name:      $btn.data( 'p-last-name' ),
            parent_first_name:     $btn.data( 'p-first-name' ),
            parent_middle_name:    $btn.data( 'p-middle-name' ),
            parent_birth_date:     $btn.data( 'p-birth-date' ),
            parent_email:          $btn.data( 'p-email' ),
            parent_phone:          $btn.data( 'p-phone' ),
            parent_doc_type:       $btn.data( 'p-doc-type' ),
            parent_doc_number:     $btn.data( 'p-doc-number' ),
            parent_doc_issued_by:  $btn.data( 'p-doc-issued-by' ),
            parent_doc_issued_date: $btn.data( 'p-doc-issued-date' ),
            parent_inn:            $btn.data( 'p-inn' ),
            parent_address:        $btn.data( 'p-address' ),
        } );
    },

    _handleSave( data ) {
        ApplicationReviewModal.setSaveState( true );

        $.post( fs_lms_vars.ajaxurl, {
            action:                 fs_lms_vars.ajax_actions.updateReviewData,
            security:               appVars.nonces.review,
            application_id:         data.application_id,
            student_last_name:      data.student_last_name,
            student_first_name:     data.student_first_name,
            student_middle_name:    data.student_middle_name,
            student_birth_date:     data.student_birth_date,
            student_doc_type:       data.student_doc_type,
            student_doc_number:     data.student_doc_number,
            student_inn:            data.student_inn,
            parent_last_name:       data.parent_last_name,
            parent_first_name:      data.parent_first_name,
            parent_middle_name:     data.parent_middle_name,
            parent_birth_date:      data.parent_birth_date,
            parent_email:           data.parent_email,
            parent_phone:           data.parent_phone,
            parent_doc_type:        data.parent_doc_type,
            parent_doc_number:      data.parent_doc_number,
            parent_doc_issued_by:   data.parent_doc_issued_by,
            parent_doc_issued_date: data.parent_doc_issued_date,
            parent_inn:             data.parent_inn,
            parent_address:         data.parent_address,
        } )
            .done( ( res ) => {
                if ( res.success ) {
                    ApplicationReviewModal.close();
                    location.reload();
                } else {
                    showModalError( res.data?.message || res.data || 'Ошибка сохранения.', ApplicationReviewModal.$modal );
                    ApplicationReviewModal.setSaveState( false );
                }
            } )
            .fail( () => {
                showModalError( 'Ошибка соединения.', ApplicationReviewModal.$modal );
                ApplicationReviewModal.setSaveState( false );
            } );
    },
};