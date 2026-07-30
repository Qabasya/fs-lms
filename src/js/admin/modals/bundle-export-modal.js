/**
 * @module BundleExportModal
 * @description UI выбора объёма пакета переноса предмета (Этап 6).
 *              Только сбор решения пользователя — AJAX в SubjectBundleService.
 *
 * @requires jQuery
 * @requires openModal, closeModal, bindEsc, unbindEsc — базовые утилиты модалок
 */

import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

const ESC_SCOPE = 'bundle_export';
const EVT       = '.bundle_export';

/**
 * Модалка «Экспорт предмета в пакет».
 * @namespace BundleExportModal
 */
export const BundleExportModal = {

    /** @type {jQuery|null} Кэшированный контейнер модалки */
    $modal: null,

    /**
     * Инициализация: кэширует узел и вешает реакцию на чекбокс учеников.
     */
    init() {
        this.$modal = $( '#fs-lms-bundle-export-modal' );
        if ( ! this.$modal.length ) { return; }

        // Предупреждение о ПД показываем только когда оно относится к делу.
        this.$modal.on( 'change', '.js-bundle-students', ( e ) => {
            this.$modal
                .find( '.js-bundle-students-warning' )
                .toggleClass( 'hidden', ! e.currentTarget.checked );
        } );
    },

    /**
     * Открывает модалку и возвращает выбранный объём пакета.
     *
     * @param {Object} [options] Параметры отображения.
     * @param {string} [options.summary] Строка «какой предмет выгружаем».
     * @returns {Promise<{includeCurriculum: boolean, includeMedia: boolean, includeStudents: boolean}>}
     */
    confirm( { summary = '' } = {} ) {
        if ( ! this.$modal || ! this.$modal.length ) {
            return Promise.reject( 'no-modal' );
        }

        this.$modal.find( '.js-bundle-export-summary' ).text( summary );
        this.$modal.find( '.js-bundle-curriculum, .js-bundle-media' ).prop( 'checked', true );
        this.$modal.find( '.js-bundle-students' ).prop( 'checked', false );
        this.$modal.find( '.js-bundle-students-warning' ).addClass( 'hidden' );

        openModal( this.$modal );

        return new Promise( ( resolve, reject ) => {
            this.$modal.find( '.fs-lms-modal-confirm' )
                .off( `click${ EVT }` )
                .on( `click${ EVT }`, () => {
                    const scope = {
                        includeCurriculum: this.$modal.find( '.js-bundle-curriculum' ).prop( 'checked' ) === true,
                        includeMedia:      this.$modal.find( '.js-bundle-media' ).prop( 'checked' ) === true,
                        includeStudents:   this.$modal.find( '.js-bundle-students' ).prop( 'checked' ) === true,
                    };
                    this._close();
                    resolve( scope );
                } );

            this.$modal.find( '.fs-lms-modal-cancel, .fs-lms-modal-close, .fs-lms-modal-backdrop' )
                .off( `click${ EVT }` )
                .on( `click${ EVT }`, () => {
                    this._close();
                    reject( 'cancel' );
                } );

            bindEsc( ESC_SCOPE, () => {
                this._close();
                reject( 'esc' );
            } );
        } );
    },

    /**
     * Закрытие с очисткой глобального слушателя ESC.
     * @private
     */
    _close() {
        closeModal( this.$modal );
        unbindEsc( ESC_SCOPE );
    },
};
