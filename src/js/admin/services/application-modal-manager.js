import { ApplicationModal } from '../components/application-modal.js';
import { showModalError, clearModalError } from '../modules/utils.js';

const $ = jQuery;
const appVars = window.fs_lms_applications_vars;

export const ApplicationModalManager = {
    init() {
        ApplicationModal.init();
        if ( ! ApplicationModal._initialized ) return;

        this._bindEvents();
    },

    _bindEvents() {
        $( document ).on( 'click', '.js-edit-application', ( e ) => {
            e.preventDefault();
            this._handleOpen( $( e.currentTarget ) );
        } );

        ApplicationModal.onSave( ( data ) => this._handleSave( data ) );
    },

    _handleOpen( $btn ) {
        ApplicationModal.open( {
            id:          $btn.data( 'id' ),
            last_name:   $btn.data( 'last-name' ),
            first_name:  $btn.data( 'first-name' ),
            middle_name: $btn.data( 'middle-name' ),
            birth_date:  $btn.data( 'birth-date' ),
            email:       $btn.data( 'email' ),
            phone:       $btn.data( 'phone' ),
            school:      $btn.data( 'school' ),
            grade:       String( $btn.data( 'grade' ) ),
        } );
    },

    _handleSave( data ) {
        ApplicationModal.setSaveState( true );

        $.post( fs_lms_vars.ajaxurl, {
            action:         fs_lms_vars.ajax_actions.updateApplicationData,
            security:       appVars.nonces.edit,
            application_id: data.application_id,
            last_name:      data.last_name,
            first_name:     data.first_name,
            middle_name:    data.middle_name,
            birth_date:     data.birth_date,
            email:          data.email,
            phone:          data.phone,
            school:         data.school,
            grade:          data.grade,
        } )
            .done( ( res ) => {
                if ( res.success ) {
                    ApplicationModal.close();
                    location.reload();
                } else {
                    showModalError( res.data?.message || res.data || 'Ошибка сохранения.', ApplicationModal.$modal );
                    ApplicationModal.setSaveState( false );
                }
            } )
            .fail( () => {
                showModalError( 'Ошибка соединения.', ApplicationModal.$modal );
                ApplicationModal.setSaveState( false );
            } );
    },
};