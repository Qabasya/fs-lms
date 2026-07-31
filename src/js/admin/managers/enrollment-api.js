/**
 * @fileoverview Транспорт заявок/родителей: поиск родителя, назначение и снятие.
 *
 * @module admin/managers/enrollment-api
 * @description Модалки (`admin/modals/`) — UI без AJAX (CLAUDE.md): запросы
 * живут здесь и возвращают промисы, разметку рисует вызывающая модалка.
 *
 * @requires jQuery
 */

import '../_types.js';

const $ = window.jQuery || jQuery;

/**
 * Нонсы экрана заявок (могут отсутствовать вне этого экрана).
 *
 * @return {Object} Карта нонсов.
 */
function nonces() {
    return ( window.fs_lms_applications_vars ?? {} ).nonces ?? {};
}

/**
 * Отправляет POST на admin-ajax и отдаёт промис ответа.
 *
 * @param {Object} data Полезная нагрузка (action + параметры + security).
 * @return {Promise<Object>} Ответ вида { success, data }.
 */
function post( data ) {
    return Promise.resolve( $.ajax( { url: fs_lms_vars.ajaxurl, method: 'POST', data } ) );
}

/**
 * Поиск существующих родителей по строке запроса.
 *
 * @param {string} query Поисковая строка.
 * @return {Promise<Object>} Ответ сервера.
 */
export function searchParents( query ) {
    return post( {
        action:   fs_lms_vars.ajax_actions.searchParents,
        query,
        security: nonces().manager ?? '',
    } );
}

/**
 * Назначает заявке существующего родителя.
 *
 * @param {number|string} applicationId ID заявки.
 * @param {number|string} personId      ID физлица родителя.
 * @return {Promise<Object>} Ответ сервера.
 */
export function selectExistingParent( applicationId, personId ) {
    return post( {
        action:           fs_lms_vars.ajax_actions.selectExistingParent,
        application_id:   applicationId,
        parent_person_id: personId,
        security:         nonces().selectExistingParent ?? '',
    } );
}

/**
 * Снимает назначение родителя с заявки.
 *
 * @param {number|string} applicationId ID заявки.
 * @return {Promise<Object>} Ответ сервера.
 */
export function removeParentAssignment( applicationId ) {
    return post( {
        action:         fs_lms_vars.ajax_actions.removeParentAssignment,
        application_id: applicationId,
        security:       nonces().removeParentAssignment ?? '',
    } );
}
