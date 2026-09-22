/**
 * @module TrialAccessModalManager
 * @description Временный доступ ученика до зачисления из таблицы заявок:
 *              - «Временный доступ» (кнопка в модалке «Изменить») — модалка выбора группы → выдача;
 *              - «Снять доступ» (действие строки) — подтверждение → снятие.
 *              После успеха страница перезагружается: бейдж, колонка «Срок» и
 *              действия строки рисуются сервером.
 *
 * @requires jQuery
 * @requires TrialAccessModal - UI модалки
 * @requires ConfirmModal - подтверждение снятия
 */

import { TrialAccessModal } from '../../../modals/enrollment/applications/trial-access-modal.js';
import { ConfirmModal } from '../../../modals/confirm-modal.js';
import { showNotice } from '../../../modules/utils.js';

const $ = jQuery;

const appVars = window.fs_lms_applications_vars;

/** Чуть дольше анимации закрытия в closeModal() (200 мс). */
const CLOSE_ANIMATION_MS = 250;

export const TrialAccessModalManager = {

    init() {
        TrialAccessModal.init();
        if ( ! TrialAccessModal._initialized || ! appVars ) return;

        ConfirmModal.init();
        this._bindEvents();
    },

    _bindEvents() {
        // Кнопка живёт в подвале модалок «Изменить» (своих для «Ждёт родителя» и
        // «Готово к проверке»): заявку ей передаёт ссылка, которой модалку открыли.
        $( document ).on( 'click', '.js-edit-application, .js-review-application', ( e ) => {
            const $link = $( e.currentTarget );
            $( '.fs-lms-modal .js-grant-trial' )
                .data( {
                    id:      $link.data( 'id' ),
                    student: $link.data( 'trial-student' ) || '',
                    subject: $link.data( 'trial-subject' ) || '',
                } )
                .prop( 'hidden', !! $link.data( 'trial-active' ) );
        } );

        $( document ).on( 'click', '.js-grant-trial', ( e ) => {
            e.preventDefault();
            const $btn = $( e.currentTarget );

            // Модалки не стопкой: сначала закрываем «Изменить» её же кнопкой — со своим unbindEsc.
            $btn.closest( '.fs-lms-modal' ).find( '.fs-lms-modal-cancel' ).first().trigger( 'click' );

            // closeModal() снимает блокировку прокрутки по окончании анимации — открываем после неё,
            // иначе новая модалка останется без modal-open на <html>.
            setTimeout( () => {
                TrialAccessModal.open( $btn.data( 'id' ), $btn.data( 'student' ) || '', $btn.data( 'subject' ) || '' );
            }, CLOSE_ANIMATION_MS );
        } );

        $( document ).on( 'click', '.js-revoke-trial', ( e ) => {
            e.preventDefault();
            this._confirmRevoke( e.currentTarget );
        } );

        TrialAccessModal.onFilterChange( ( filters ) => this._loadGroups( filters ) );
        TrialAccessModal.onSubmit( ( data ) => this._grant( data ) );
    },

    /**
     * Группы по периоду и предмету — тот же справочник, что у формы зачисления.
     * @private
     * @param {{period_id: string, subject_id: string}} filters
     */
    _loadGroups( filters ) {
        if ( ! filters.period_id || ! filters.subject_id ) {
            TrialAccessModal.populateGroups( [] );
            return;
        }

        $.post( fs_lms_vars.ajaxurl, {
            action:     fs_lms_vars.ajax_actions.getStudentGroups,
            security:   appVars.nonces.manager,
            period_id:  filters.period_id,
            subject_id: filters.subject_id,
        } ).done( ( res ) => {
            TrialAccessModal.populateGroups( res.success ? res.data : [] );
        } );
    },

    /**
     * @private
     * @param {{application_id: string, group_id: string}} data
     */
    _grant( data ) {
        TrialAccessModal.setBusy( true );

        $.post( fs_lms_vars.ajaxurl, {
            action:         fs_lms_vars.ajax_actions.grantTrialAccess,
            security:       appVars.nonces.enroll,
            application_id: data.application_id,
            group_id:       data.group_id,
        } )
            .done( ( res ) => {
                if ( res.success ) {
                    location.reload();
                    return;
                }
                TrialAccessModal.showError( res.data?.message || res.data || 'Не удалось выдать доступ.' );
                TrialAccessModal.setBusy( false );
            } )
            .fail( () => {
                TrialAccessModal.showError( 'Ошибка соединения.' );
                TrialAccessModal.setBusy( false );
            } );
    },

    /**
     * @private
     * @param {HTMLElement} btn - Ссылка «Снять доступ»
     */
    _confirmRevoke( btn ) {
        ConfirmModal.confirm( {
            title:       'Снять временный доступ',
            message:     'Ученик потеряет доступ к группе. Учётка, заведённая для доступа, будет удалена вместе с его работами за это время. Продолжить?',
            confirmText: 'Снять доступ',
            cancelText:  'Отмена',
            size:        'sm',
            isDanger:    true,
        } )
            .then( () => this._revoke( btn ) )
            .catch( () => {} );
    },

    /**
     * @private
     * @param {HTMLElement} btn - Ссылка «Снять доступ»
     */
    _revoke( btn ) {
        btn.classList.add( 'disabled' );

        $.post( fs_lms_vars.ajaxurl, {
            action:         fs_lms_vars.ajax_actions.revokeTrialAccess,
            security:       appVars.nonces.enroll,
            application_id: btn.dataset.id,
        } )
            .done( ( res ) => {
                if ( res.success ) {
                    location.reload();
                    return;
                }
                btn.classList.remove( 'disabled' );
                showNotice( res.data?.message || res.data || 'Не удалось снять доступ.', 'error', $( btn ).closest( '.wrap' ) );
            } )
            .fail( () => {
                btn.classList.remove( 'disabled' );
                showNotice( 'Ошибка соединения с сервером.', 'error', $( btn ).closest( '.wrap' ) );
            } );
    },
};
