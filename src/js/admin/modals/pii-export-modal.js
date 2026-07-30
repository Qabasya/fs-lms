/**
 * @module PiiExportModal
 * @description UI-компонент подтверждения выгрузки персональных данных (A2).
 *              Проговаривает риск и делает выгрузку паролей отдельным осознанным
 *              выбором. AJAX здесь нет — модалка только собирает решение
 *              пользователя и возвращает его промисом.
 *
 * @requires jQuery
 * @requires openModal, closeModal, bindEsc, unbindEsc — базовые утилиты модалок
 */

import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

const ESC_SCOPE = 'pii_export';
const EVT       = '.pii_export';

/**
 * Модалка «Экспорт персональных данных».
 * @namespace PiiExportModal
 */
export const PiiExportModal = {

    /** @type {jQuery|null} Кэшированный контейнер модалки */
    $modal: null,

    /**
     * Инициализация: кэширует DOM-узел модалки, если она есть на странице.
     */
    init() {
        this.$modal = $( '#fs-lms-pii-export-modal' );
    },

    /**
     * Открывает модалку и возвращает Promise с выбором пользователя.
     *
     * @param {Object} [options] Параметры отображения.
     * @param {string} [options.summary] Строка «что именно выгружаем».
     * @returns {Promise<{includePasswords: boolean}>} resolve — подтверждено,
     *          reject('cancel'|'close'|'esc') — отказ.
     */
    confirm( { summary = '' } = {} ) {
        // Модалки нет на странице (шаблон не подключён) — не блокируем сценарий,
        // но и не выгружаем пароли без явного согласия.
        if ( ! this.$modal || ! this.$modal.length ) {
            return Promise.resolve( { includePasswords: false } );
        }

        const $checkbox = this.$modal.find( '.js-pii-export-passwords' );
        $checkbox.prop( 'checked', false );
        this.$modal.find( '.js-pii-export-summary' ).text( summary );

        openModal( this.$modal );

        return new Promise( ( resolve, reject ) => {
            this.$modal.find( '.fs-lms-modal-confirm' )
                .off( `click${ EVT }` )
                .on( `click${ EVT }`, () => {
                    const includePasswords = $checkbox.prop( 'checked' ) === true;
                    this._close();
                    resolve( { includePasswords } );
                } );

            this.$modal.find( '.fs-lms-modal-cancel' )
                .off( `click${ EVT }` )
                .on( `click${ EVT }`, () => {
                    this._close();
                    reject( 'cancel' );
                } );

            this.$modal.find( '.fs-lms-modal-close, .fs-lms-modal-backdrop' )
                .off( `click${ EVT }` )
                .on( `click${ EVT }`, () => {
                    this._close();
                    reject( 'close' );
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
