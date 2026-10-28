/**
 * @module PersonModalActions
 * @description AJAX-действия менеджеров модалок персоны (родитель, студент): раскрытие ПД и учётных
 *              данных, вход в режим правки, сохранение и перевыпуск пароля. Различия между ролями
 *              приходят параметрами: список редактируемых полей и предложение «Сгенерировать пароль».
 *
 * @requires jQuery
 */

import { showNotice } from '../../../modules/utils.js';

const $ = jQuery;

// Геттеры для глобальных переменных WordPress.
const NONCES   = () => fs_lms_applications_vars.nonces;
const ACTIONS  = () => fs_lms_vars.ajax_actions;
const AJAX_URL = () => fs_lms_vars.ajaxurl;

/**
 * Снимает блокировку входа аккаунта (серия неверных паролей) со всех адресов. Общий для карточек
 * ученика, родителя и преподавателя: сервер один, различается только то, где показать результат.
 *
 * @param {number} wpUserId  ID пользователя WP.
 * @param {jQuery} $btn      Кнопка «Снять блокировку входа» (блокируется на время запроса).
 * @param {jQuery} $notices  Контейнер для уведомления о результате (тело модалки).
 */
export function unlockUserLogin( wpUserId, $btn, $notices ) {
    if ( ! wpUserId ) return;

    $btn.prop( 'disabled', true );

    $.post( AJAX_URL(), {
        action:   ACTIONS().unlockUserLogin,
        user_id:  wpUserId,
        security: NONCES().revealPii,
    } ).done( ( res ) => {
        if ( res.success ) {
            showNotice( 'Блокировка входа снята: можно входить с первой попытки.', 'success', $notices );
        } else {
            showNotice( typeof res.data === 'string' ? res.data : 'Не удалось снять блокировку.', 'error', $notices );
        }
    } ).fail( () => {
        showNotice( 'Не удалось снять блокировку: ошибка соединения.', 'error', $notices );
    } ).always( () => {
        $btn.prop( 'disabled', false );
    } );
}

/**
 * @param {Object}   modal                 UI-компонент модалки персоны ({@link createPersonModal}).
 * @param {Object}   opts
 * @param {string[]} opts.editableFields   Поля, которые можно отправить на сохранение.
 * @param {boolean}  [opts.offerRegenerate] Показать кнопку «Сгенерировать новый пароль», если пароль раскрыть не удалось.
 */
export function createPersonActions( modal, { editableFields, offerRegenerate = false } ) {

    const revealPii = ( personId, reason ) => $.post( AJAX_URL(), {
        action:    ACTIONS().revealAllPersonPii,
        person_id: personId,
        reason,    // Причина раскрытия для логирования/аудита
        security:  NONCES().revealPii,
    } ).done( ( res ) => {
        if ( res.success ) modal.fillRevealed( res.data );
    } );

    const revealCredentials = ( wpUserId, onFailure = () => {} ) => $.post( AJAX_URL(), {
        action:   ACTIONS().revealUserCredentials,
        user_id:  wpUserId,
        security: NONCES().revealPii,
    } ).done( ( res ) => {
        if ( res.success ) {
            modal.fillRevealed( { password: res.data.password || '' } );
        } else {
            onFailure();
        }
    } );

    return {

        /**
         * Раскрывает все маскированные данные без перехода в режим редактирования.
         */
        revealAll() {
            const personId = modal.getPersonId();
            const wpUserId = modal.getWpUserId();
            if ( ! personId ) return;

            revealPii( personId, 'admin_userlist_reveal' );

            if ( wpUserId ) {
                revealCredentials( wpUserId, () => {
                    if ( offerRegenerate ) modal.showRegenerateButton( wpUserId );
                } );
            }
        },

        /**
         * Включает режим редактирования, предварительно раскрыв полные данные и пароль,
         * чтобы пользователь правил реальные значения, а не маски.
         */
        startEditing() {
            const personId = modal.getPersonId();
            const wpUserId = modal.getWpUserId();

            if ( ! personId ) {
                modal.setEditing( true );
                return;
            }

            const piiPromise  = revealPii( personId, 'admin_userlist_edit' );
            const credPromise = wpUserId
                ? revealCredentials( wpUserId )
                : $.Deferred().resolve(); // Если wpUserId нет, создаем сразу разрешенный Deferred

            $.when( piiPromise, credPromise ).always( () => {
                modal.setEditing( true );
            } );
        },

        /**
         * Сохраняет изменения: отправляет только разрешённые поля, исключая маскированные значения.
         */
        save() {
            const personId = modal.getPersonId();
            if ( ! personId ) return;

            const edit    = modal.getEditData();
            const payload = {
                action:    ACTIONS().updatePerson,
                security:  NONCES().updatePerson,
                person_id: personId,
            };

            editableFields.forEach( k => {
                if ( edit[ k ] && ! edit[ k ].includes( '•' ) ) payload[ k ] = edit[ k ];
            } );

            $.post( AJAX_URL(), payload ).done( ( res ) => {
                if ( res.success ) modal.setEditing( false );
            } );
        },

        /**
         * Снимает блокировку входа аккаунта (серия неверных паролей) со всех адресов.
         *
         * @param {number} wpUserId ID пользователя WP.
         * @param {jQuery} $btn     Кнопка «Снять блокировку входа» (блокируется на время запроса).
         */
        unlockLogin( wpUserId, $btn ) {
            unlockUserLogin( wpUserId, $btn, modal.$el.find( '.fs-lms-modal-body' ) );
        },

        /**
         * Перевыпускает пароль аккаунта и показывает новый в модалке.
         *
         * @param {number} wpUserId ID пользователя WP.
         * @param {jQuery} $btn     Кнопка «Сгенерировать новый пароль» (блокируется на время запроса).
         */
        regeneratePassword( wpUserId, $btn ) {
            $btn.prop( 'disabled', true );

            $.post( AJAX_URL(), {
                action:   ACTIONS().regenerateUserPassword,
                user_id:  wpUserId,
                security: NONCES().revealPii,
            } ).done( ( res ) => {
                if ( res.success ) {
                    modal.fillRevealed( { password: res.data.password || '' } );
                    $btn.remove();
                } else {
                    $btn.prop( 'disabled', false );
                }
            } );
        },
    };
}
