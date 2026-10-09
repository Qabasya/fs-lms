import { ConfirmModal } from '../../modals/confirm-modal.js';
import { showNotice } from '../../modules/utils.js';

const $ = jQuery;

const NONCES   = () => fs_lms_applications_vars.nonces;
const ACTIONS  = () => fs_lms_vars.ajax_actions;
const AJAX_URL = () => fs_lms_vars.ajaxurl;

/**
 * Вкладка «Заявки с сайта» (Пользователи): выбор строк и удаление.
 * Удаление — только для роли с правом ManageLmsPlatform (кнопок у остальных нет).
 */
export const LeadsTable = {

    _initialized: false,

    init() {
        if ( this._initialized ) return;
        if ( ! $( '.fs-lms-leads' ).length ) return;
        this._initialized = true;
        ConfirmModal.init();
        this._bindEvents();
    },

    _bindEvents() {
        $( document ).on( 'change', '#js-select-all-leads', ( e ) => {
            $( '.js-lead-cb' ).prop( 'checked', e.currentTarget.checked );
        } );

        $( document ).on( 'change', '.js-lead-cb', () => {
            const total   = $( '.js-lead-cb' ).length;
            const checked = $( '.js-lead-cb:checked' ).length;
            $( '#js-select-all-leads' ).prop( 'indeterminate', checked > 0 && checked < total );
            $( '#js-select-all-leads' ).prop( 'checked', checked === total );
        } );

        $( document ).on( 'click', '#js-leads-bulk-apply', () => this._applyBulk() );
    },

    _applyBulk() {
        const action = $( '#js-leads-bulk-action' ).val();

        if ( action === 'delete' ) {
            this._deleteSelected();
        } else if ( action === 'delete_rejected' ) {
            this._deleteRejected();
        } else {
            showNotice( 'Выберите массовое действие.', 'warning' );
        }
    },

    _deleteSelected() {
        const ids = $( '.js-lead-cb:checked' ).map( ( _, el ) => parseInt( el.value, 10 ) ).get();
        if ( ! ids.length ) {
            showNotice( 'Выберите заявки для удаления.', 'warning' );
            return;
        }

        ConfirmModal.confirm( {
            title:       'Удалить заявки?',
            message:     `Выбрано заявок: ${ ids.length }. Удаление необратимо.`,
            confirmText: 'Удалить',
        } ).then( () => this._post( ACTIONS().deleteLeads, { ids } ) ).catch( () => {} );
    },

    _deleteRejected() {
        ConfirmModal.confirm( {
            title:       'Удалить все отклонённые заявки?',
            message:     'Будут удалены все отклонённые заявки, а не только на этой странице. Удаление необратимо.',
            confirmText: 'Удалить все',
        } ).then( () => this._post( ACTIONS().deleteRejectedLeads, {} ) ).catch( () => {} );
    },

    _post( action, data ) {
        return $.post( AJAX_URL(), { action, security: NONCES().deleteLeads, ...data } )
            .done( ( resp ) => {
                if ( resp && resp.success ) {
                    window.location.reload();
                    return;
                }
                showNotice( ( resp && resp.data && resp.data.message ) || 'Не удалось удалить заявки.', 'error' );
            } )
            .fail( () => showNotice( 'Ошибка сети.', 'error' ) );
    },
};
