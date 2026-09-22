/**
 * @module TrialAccessModal
 * @description UI модалки временного доступа ученика до зачисления: выбор периода,
 *              предмета и группы. Сеть не трогает — отправку и подгрузку групп делает
 *              TrialAccessModalManager через подписки onSubmit / onFilterChange.
 *
 * @requires jQuery
 */

import { openModal, closeModal, bindEsc, unbindEsc } from '../../../modules/modal-base.js';
import { showModalError, clearModalError } from '../../../modules/utils.js';
import { escapeHtml } from '../../../../common/utils.js';

const $ = jQuery;

export const TrialAccessModal = {
    /** @type {jQuery|null} */
    $modal: null,

    /** @type {jQuery|null} */
    $form: null,

    /** @type {Function[]} */
    _submitCallbacks: [],

    /** @type {Function[]} */
    _filterCallbacks: [],

    _initialized: false,

    init() {
        if ( this._initialized ) return;

        this.$modal = $( '#fs-trial-access-modal' );
        if ( ! this.$modal.length ) return;

        this._initialized = true;
        this.$form        = $( '#fs-trial-access-form' );

        this._populateOptions();
        this._bindEvents();
    },

    _bindEvents() {
        this.$modal.on( 'click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .js-modal-close', ( e ) => {
            e.preventDefault();
            this.close();
        } );

        this.$modal.on( 'change', '#trial-period, #trial-subject', () => {
            this._filterCallbacks.forEach( cb => cb( this.filters() ) );
        } );

        this.$form.on( 'submit', ( e ) => {
            e.preventDefault();

            const data = {
                application_id: this.$form.find( '[name="application_id"]' ).val(),
                group_id:       this.$form.find( '[name="group_id"]' ).val(),
            };

            if ( ! data.group_id ) {
                showModalError( 'Выберите группу.', this.$modal );
                return;
            }

            this._submitCallbacks.forEach( cb => cb( data ) );
        } );
    },

    /**
     * Периоды и предметы — из data-атрибутов, отрисованных сервером.
     * @private
     */
    _populateOptions() {
        const periods  = JSON.parse( this.$modal.attr( 'data-periods' ) || '[]' );
        const subjects = JSON.parse( this.$modal.attr( 'data-subjects' ) || '[]' );
        const current  = this.$modal.attr( 'data-current-period' ) || '';

        const $period = this.$modal.find( '[name="period_key"]' ).empty()
            .append( '<option value="">— Выберите период —</option>' );
        periods.forEach( p => {
            $period.append( `<option value="${ escapeHtml( p.id ) }"${ p.id === current ? ' selected' : '' }>${ escapeHtml( p.name ) }</option>` );
        } );

        const $subject = this.$modal.find( '[name="subject_key"]' ).empty()
            .append( '<option value="">— Выберите предмет —</option>' );
        subjects.forEach( s => {
            $subject.append( `<option value="${ escapeHtml( s.key ) }">${ escapeHtml( s.name ) }</option>` );
        } );
    },

    /**
     * @param {string|number} appId       ID заявки
     * @param {string}        studentName ФИО ученика для заголовка
     * @param {string}        subjectKey  Направление заявки — предвыбор предмета
     */
    open( appId, studentName, subjectKey ) {
        clearModalError( this.$modal );
        this.$form.find( '[name="application_id"]' ).val( appId );
        $( '#trial-access-student' ).text( studentName );
        this.$form.find( '[name="subject_key"]' ).val( subjectKey || '' );
        this.populateGroups( [] );
        this.setBusy( false );

        openModal( this.$modal );
        bindEsc( 'trial_access', () => this.close() );

        this._filterCallbacks.forEach( cb => cb( this.filters() ) );
    },

    close() {
        closeModal( this.$modal );
        unbindEsc( 'trial_access' );
    },

    /** @returns {{period_id: string, subject_id: string}} */
    filters() {
        return {
            period_id:  this.$form.find( '[name="period_key"]' ).val(),
            subject_id: this.$form.find( '[name="subject_key"]' ).val(),
        };
    },

    /** @param {Array<{id: number, title: string}>} groups */
    populateGroups( groups ) {
        const $select = this.$form.find( '[name="group_id"]' ).empty();

        if ( ! groups.length ) {
            $select.append( '<option value="">— Нет доступных групп —</option>' ).prop( 'disabled', true );
            return;
        }

        $select.append( '<option value="">— Выберите группу —</option>' );
        groups.forEach( g => {
            $select.append( `<option value="${ escapeHtml( g.id ) }">${ escapeHtml( g.title ) }</option>` );
        } );
        $select.prop( 'disabled', false );
    },

    /** @param {boolean} busy */
    setBusy( busy ) {
        $( '#trial-access-submit' ).prop( 'disabled', busy ).text( busy ? 'Выдаём...' : 'Выдать доступ' );
    },

    /** @param {string} message */
    showError( message ) {
        showModalError( message, this.$modal );
    },

    /** @param {Function} callback Получает { application_id, group_id } */
    onSubmit( callback ) {
        if ( typeof callback === 'function' ) { this._submitCallbacks.push( callback ); }
    },

    /** @param {Function} callback Получает { period_id, subject_id } */
    onFilterChange( callback ) {
        if ( typeof callback === 'function' ) { this._filterCallbacks.push( callback ); }
    },
};
