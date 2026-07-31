/**
 * @module PiiExportService
 * @description Единая точка выгрузки персональных данных учеников/родителей (A2).
 *              Показывает предупреждение, спрашивает про пароли, запрашивает у сервера
 *              одноразовую ссылку и инициирует скачивание.
 *
 *              Зачем один сервис на четыре точки вызова (массовый экспорт учеников,
 *              массовый экспорт родителей и две карточки лица): предупреждение и
 *              флаг `include_passwords` обязаны быть одинаковыми везде — иначе
 *              появляется «тихий» путь выгрузки паролей мимо предупреждения.
 *
 * @requires jQuery
 * @requires PiiExportModal — UI подтверждения
 */

import '../_types.js';
import { PiiExportModal } from '../modals/pii-export-modal.js';

const $ = jQuery;

/**
 * Инициирует скачивание файла по одноразовой ссылке.
 *
 * Браузеры не дают сохранить файл прямо из AJAX-ответа, поэтому сервер отдаёт
 * URL, а мы кликаем по невидимой ссылке.
 *
 * @param {string} url Одноразовая ссылка на файл.
 */
function download( url ) {
    const a = document.createElement( 'a' );
    a.href = url;
    document.body.appendChild( a );
    a.click();
    document.body.removeChild( a );
}

export const PiiExportService = {

    /**
     * Экспорт учеников.
     *
     * @param {number[]} ids Person ID; пустой массив — весь датасет.
     * @returns {Promise<void>}
     */
    exportStudents( ids ) {
        return this._run( fs_lms_vars.ajax_actions.exportStudents, ids, 'учеников' );
    },

    /**
     * Экспорт родителей.
     *
     * @param {number[]} ids Person ID; пустой массив — весь датасет.
     * @returns {Promise<void>}
     */
    exportParents( ids ) {
        return this._run( fs_lms_vars.ajax_actions.exportParents, ids, 'родителей' );
    },

    /**
     * Общий сценарий: подтверждение → AJAX → скачивание.
     *
     * @private
     * @param {string}   action Имя AJAX-действия WordPress.
     * @param {number[]} ids    Выбранные person ID.
     * @param {string}   noun   Родительный падеж для строки-описания («учеников»).
     * @returns {Promise<void>} Отказ пользователя не считается ошибкой.
     */
    _run( action, ids, noun ) {
        const list    = Array.isArray( ids ) ? ids.filter( Boolean ) : [];
        const summary = list.length
            ? `Будут выгружены данные ${ noun }: ${ list.length } шт.`
            : `Будут выгружены данные всех ${ noun }.`;

        return PiiExportModal.confirm( { summary } )
            .then( ( { includePasswords } ) => new Promise( ( resolve ) => {
                $.post( fs_lms_vars.ajaxurl, {
                    action,
                    ids:               list,
                    include_passwords: includePasswords ? 1 : 0,
                    security:          fs_lms_vars.nonces.manager,
                } ).done( ( res ) => {
                    if ( res.success && res.data?.url ) {
                        download( res.data.url );
                    }
                } ).always( resolve );
            } ) )
            // Пользователь закрыл модалку — штатный путь, не ошибка.
            .catch( () => {} );
    },
};
