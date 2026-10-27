/**
 * @module ParentPersonModal
 * @description UI-компонент модального окна для просмотра и редактирования данных родителя.
 *              Вся логика общая со студентом — {@link createPersonModal}; здесь только
 *              идентификаторы родительской модалки.
 *
 * @requires jQuery
 */

import { createPersonModal } from './person-modal-base.js';

/**
 * UI-компонент модального окна управления данными родителя.
 * Работает в связке с ParentPersonModalManager, который обрабатывает AJAX-запросы.
 */
export const ParentPersonModal = createPersonModal( {
    selector:   '#fs-parent-person-modal',
    escNs:      'ppm',
    regenClass: 'js-pmm-regen-pwd',
    regenEvent: 'fs-lms:regenerate-password',
} );
