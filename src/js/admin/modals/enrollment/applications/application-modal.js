/**
 * @module ApplicationModal
 * @description UI-компонент модального окна редактирования заявки. Вся логика общая с окном
 *              проверки — {@link createApplicationModal}; здесь только идентификаторы и набор полей.
 *
 * @requires jQuery
 */

import { createApplicationModal } from './application-modal-base.js';

/**
 * Работает в связке с ApplicationModalManager, который обрабатывает AJAX-запросы.
 */
export const ApplicationModal = createApplicationModal( {
    selector:        '#fs-application-modal',
    saveBtnSelector: '#app-modal-save-btn',
    idSelector:      '#app-modal-id',
    escNs:           'application',
    accordion:       false,
    fields:          [ 'last_name', 'first_name', 'middle_name', 'birth_date', 'email', 'phone', 'school', 'grade', 'login', 'password' ],
} );
