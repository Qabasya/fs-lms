/**
 * @module BundleExportModal
 * @description UI экспорта предмета: формат (JSON-структура или ZIP-пакет) и,
 *              для пакета, его объём. Только сбор решения пользователя —
 *              запросы в SubjectTransferService/SubjectTransferApi.
 *
 * @requires jQuery
 * @requires openModal, closeModal, bindEsc, unbindEsc — базовые утилиты модалок
 */

import { openModal, closeModal, unbindEsc, awaitModalDecision } from '../modules/modal-base.js';

const $ = jQuery;

const SCOPE = 'bundle_export';

/**
 * Модалка «Экспорт предмета в пакет».
 * @namespace BundleExportModal
 */
export const BundleExportModal = {

    /** @type {jQuery|null} Кэшированный контейнер модалки */
    $modal: null,

    /**
     * Инициализация: кэширует узел и вешает реакции на выбор формата и
     * на чекбокс учеников.
     */
    /** Повторный init() — no-op: модалку поднимает и автозагрузчик ui.js, и admin.js. */
    _initialized: false,

    init() {
        if ( this._initialized ) { return; }
        this._initialized = true;
        this.$modal = $( '#fs-lms-bundle-export-modal' );
        if ( ! this.$modal.length ) { return; }

        // Предупреждение о ПД показываем только когда оно относится к делу.
        this.$modal.on( 'change', '.js-bundle-students', ( e ) => {
            this.$modal
                .find( '.js-bundle-students-warning' )
                .toggleClass( 'hidden', ! e.currentTarget.checked );
        } );

        // Состав архива относится только к ZIP — для JSON он не имеет смысла.
        this.$modal.on( 'change', '.js-bundle-format', () => this._syncFormat() );
    },

    /**
     * Прячет блок состава и меняет надпись кнопки под выбранный формат.
     * @private
     */
    _syncFormat() {
        const isZip = this._format() === 'zip';

        this.$modal.find( '.js-bundle-scope' ).toggleClass( 'hidden', ! isZip );
        this.$modal.find( '.fs-lms-modal-confirm' ).text( isZip ? 'Собрать пакет' : 'Выгрузить JSON' );
    },

    /**
     * Выбранный формат выгрузки.
     * @private
     * @returns {'json'|'zip'}
     */
    _format() {
        return this.$modal.find( '.js-bundle-format:checked' ).val() === 'zip' ? 'zip' : 'json';
    },

    /**
     * Открывает модалку и возвращает выбранный объём пакета.
     *
     * @param {Object} [options] Параметры отображения.
     * @param {string} [options.summary] Строка «какой предмет выгружаем».
     * @returns {Promise<{format: 'json'|'zip', includeCurriculum: boolean, includeMedia: boolean, includeStudents: boolean}>}
     */
    confirm( { summary = '' } = {} ) {
        if ( ! this.$modal || ! this.$modal.length ) {
            return Promise.reject( 'no-modal' );
        }

        this.$modal.find( '.js-bundle-export-summary' ).text( summary );
        this.$modal.find( '.js-bundle-format[value="json"]' ).prop( 'checked', true );
        this.$modal.find( '.js-bundle-curriculum, .js-bundle-media' ).prop( 'checked', true );
        this.$modal.find( '.js-bundle-students' ).prop( 'checked', false );
        this.$modal.find( '.js-bundle-students-warning' ).addClass( 'hidden' );
        this._syncFormat();

        openModal( this.$modal );

        return awaitModalDecision( this.$modal, SCOPE, () => ( {
            format:            this._format(),
            includeCurriculum: this.$modal.find( '.js-bundle-curriculum' ).prop( 'checked' ) === true,
            includeMedia:      this.$modal.find( '.js-bundle-media' ).prop( 'checked' ) === true,
            includeStudents:   this.$modal.find( '.js-bundle-students' ).prop( 'checked' ) === true,
        } ) );
    },

    /**
     * Закрытие с очисткой глобального слушателя ESC.
     * @private
     */
    _close() {
        closeModal( this.$modal );
        unbindEsc( SCOPE );
    },
};
