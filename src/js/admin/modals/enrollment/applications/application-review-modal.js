/**
 * @module ApplicationReviewModal
 * @description UI-компонент модального окна проверки заявки: данные студента и родителя,
 *              разложенные по секциям аккордеона. Логика общая с окном редактирования —
 *              {@link createApplicationModal}; здесь только идентификаторы и набор полей.
 *
 * @requires jQuery
 */

import { createApplicationModal } from './application-modal-base.js';

/**
 * Работает в связке с ApplicationReviewModalManager, который обрабатывает AJAX-запросы.
 */
export const ApplicationReviewModal = createApplicationModal( {
    selector:        '#fs-application-review-modal',
    saveBtnSelector: '#review-modal-save-btn',
    idSelector:      '#review-modal-id',
    escNs:           'application_review',
    accordion:       true,
    fields:          [
        // Данные студента
        'student_last_name', 'student_first_name', 'student_middle_name',
        'student_birth_date', 'student_doc_type', 'student_doc_number', 'student_inn',
        // Данные родителя
        'parent_last_name', 'parent_first_name', 'parent_middle_name',
        'parent_birth_date',
        'parent_email', 'parent_phone',
        'parent_doc_type', 'parent_doc_number', 'parent_doc_issued_by', 'parent_doc_issued_date',
        'parent_inn', 'parent_address',
    ],
} );
