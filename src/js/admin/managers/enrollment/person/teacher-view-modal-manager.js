/**
 * @module TeacherViewModalManager
 * @description Менеджер модалки карточки преподавателя. Сама модалка — только просмотр
 *              ({@link TeacherViewModal}), поэтому единственное действие с сервером — снятие блокировки
 *              входа аккаунта (кнопка «Снять блокировку входа») — живёт здесь, а не в UI-компоненте.
 *
 * @requires jQuery
 * @requires TeacherViewModal - UI-компонент модального окна
 */

import { TeacherViewModal } from '../../../modals/enrollment/teacher-view-modal.js';
import { unlockUserLogin } from './person-modal-actions.js';

const $ = jQuery;

export const TeacherViewModalManager = {

    /** @type {boolean} Защита от повторного навешивания обработчиков */
    _initialized: false,

    /**
     * Инициализация менеджера: вызывается после {@link TeacherViewModal.init}.
     */
    init() {
        if ( this._initialized ) return;

        // Нет самой модалки — навешивать нечего.
        if ( ! TeacherViewModal._initialized ) return;

        this._initialized = true;
        this._bindEvents();
    },

    /**
     * @private
     */
    _bindEvents() {
        $( document ).on( 'click.tvmm_unlock', '#fs-teacher-view-modal .js-tvm-unlock', ( e ) => {
            e.preventDefault();
            unlockUserLogin(
                TeacherViewModal.getWpUserId(),
                $( e.currentTarget ),
                TeacherViewModal.$modal.find( '.fs-lms-modal-body' )
            );
        } );
    },
};
