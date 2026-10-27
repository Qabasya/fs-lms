/**
 * @module ApplicationReviewModalManager
 * @description Менеджер окна проверки заявки (данные студента и родителя). Общая логика —
 *              {@link createApplicationModalManager}.
 *
 * @requires ApplicationReviewModal - UI-компонент модального окна
 */

import { ApplicationReviewModal } from '../../../modals/enrollment/applications/application-review-modal.js';
import { createApplicationModalManager } from './application-modal-manager-base.js';

const appVars = window.fs_lms_applications_vars;

export const ApplicationReviewModalManager = createApplicationModalManager( {
    modal:        ApplicationReviewModal,
    openSelector: '.js-review-application',
    action:       () => fs_lms_vars.ajax_actions.updateReviewData,
    nonce:        () => appVars.nonces.review,
    readButton:   ( $btn ) => ( {
        id:                     $btn.data( 'id' ),
        student_last_name:      $btn.data( 's-last-name' ),
        student_first_name:     $btn.data( 's-first-name' ),
        student_middle_name:    $btn.data( 's-middle-name' ),
        student_birth_date:     $btn.data( 's-birth-date' ),
        student_doc_type:       $btn.data( 's-doc-type' ),
        student_doc_number:     $btn.data( 's-doc-number' ),
        student_inn:            $btn.data( 's-inn' ),
        parent_last_name:       $btn.data( 'p-last-name' ),
        parent_first_name:      $btn.data( 'p-first-name' ),
        parent_middle_name:     $btn.data( 'p-middle-name' ),
        parent_birth_date:      $btn.data( 'p-birth-date' ),
        parent_email:           $btn.data( 'p-email' ),
        parent_phone:           $btn.data( 'p-phone' ),
        parent_doc_type:        $btn.data( 'p-doc-type' ),
        parent_doc_number:      $btn.data( 'p-doc-number' ),
        parent_doc_issued_by:   $btn.data( 'p-doc-issued-by' ),
        parent_doc_issued_date: $btn.data( 'p-doc-issued-date' ),
        parent_inn:             $btn.data( 'p-inn' ),
        parent_address:         $btn.data( 'p-address' ),
    } ),
} );
