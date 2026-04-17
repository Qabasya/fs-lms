/**
 * @typedef {Object} FSLmsHooks
 * @property {string} storeSubject
 * @property {string} updateSubject
 * @property {string} deleteSubject
 * @property {string} exportSubject
 * @property {string} importSubject
 * @property {string} storeTaxonomy
 * @property {string} updateTaxonomy
 * @property {string} deleteTaxonomy
 * @property {string} saveBoilerplate
 * @property {string} deleteBoilerplate
 * @property {string} getTaskTypes
 * @property {string} getTaskBoilerplates
 * @property {string} createTask
 * @property {string} getTemplateStructure
 * @property {string} saveTaskBoilerplate
 * @property {string} getTaskBoilerplate
 * @property {string} updateTermTemplate
 * @property {string} saveTemplateAssignment
 */

/**
 * Глобальные переменные плагина для AJAX-запросов и настроек.
 *
 * @typedef {Object} FSLmsVars
 * @property {string}     ajaxurl        URL для AJAX-запросов
 * @property {string}     subject_nonce  Nonce для операций с предметами
 * @property {string}     manager_nonce  Nonce для операций менеджера заданий
 * @property {FSLmsHooks} ajax_actions   Объект с названиями AJAX-действий
 */

/**
 * Данные для создания заданий (передаются из PHP в JS).
 *
 * @typedef {Object} FSLmsTaskData
 * @property {string} ajax_url    URL для AJAX-запросов
 * @property {string} nonce       Nonce для создания заданий
 * @property {string} subject_key Ключ текущего предмета
 * @property {string} post_type   Тип поста (CPT заданий)
 */

/** @type {FSLmsVars} */
window.fs_lms_vars = window.fs_lms_vars || /** @type {any} */ ({});

/** @type {FSLmsTaskData} */
window.fs_lms_task_data = window.fs_lms_task_data || /** @type {any} */ ({});