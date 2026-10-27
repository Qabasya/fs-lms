/**
 * @module ApplicationModalManager
 * @description Менеджер окна редактирования заявки. Общая логика — {@link createApplicationModalManager}.
 *
 * @requires ApplicationModal - UI-компонент модального окна
 */

import { ApplicationModal } from '../../../modals/enrollment/applications/application-modal.js';
import { createApplicationModalManager } from './application-modal-manager-base.js';

const appVars = window.fs_lms_applications_vars;

export const ApplicationModalManager = createApplicationModalManager( {
    modal:        ApplicationModal,
    openSelector: '.js-edit-application',
    action:       () => fs_lms_vars.ajax_actions.updateApplicationData,
    nonce:        () => appVars.nonces.edit,
    // Дефис в HTML (data-last-name) автоматически превращается в camelCase при обращении.
    readButton:   ( $btn ) => ( {
        id:          $btn.data( 'id' ),
        last_name:   $btn.data( 'last-name' ),
        first_name:  $btn.data( 'first-name' ),
        middle_name: $btn.data( 'middle-name' ),
        birth_date:  $btn.data( 'birth-date' ),
        email:       $btn.data( 'email' ),
        phone:       $btn.data( 'phone' ),
        school:      $btn.data( 'school' ),
        grade:       String( $btn.data( 'grade' ) ),
        login:       $btn.data( 'login' ) ?? '',
        password:    $btn.data( 'password' ) ?? '',
    } ),
} );
