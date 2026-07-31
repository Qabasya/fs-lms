/**
 * @fileoverview Транспорт создания черновиков контента (работа, урок, задача).
 *
 * @module admin/managers/draft-api
 * @description Модалка создания черновика — UI без AJAX (CLAUDE.md).
 *
 * @requires jQuery
 */

import '../_types.js';

const $ = window.jQuery || jQuery;

/**
 * Создаёт черновик записи выбранного типа.
 *
 * @param {Object} payload Данные запроса: action, security, subject_key, title[, work_type].
 * @return {Promise<Object>} Ответ вида { success, data: { id, title } }.
 */
export function createDraft( payload ) {
    return Promise.resolve( $.post( fs_lms_vars.ajaxurl, payload ) );
}
