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
 * @property {string} getPostsTable
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

/**
 * Базовый ответ сервера для AJAX-запросов.
 *
 * @typedef {Object} AjaxResponse
 * @property {boolean} success - Флаг успешности операции
 * @property {*} data - Данные ответа (строка, объект, массив)
 */

/**
 * Данные формы таксономии.
 *
 * @typedef {Object} TaxonomyFormData
 * @property {string} action - Тип действия ('store' или 'update')
 * @property {string} subject_key - Ключ предмета
 * @property {string} tax_slug - Слаг таксономии
 * @property {string} tax_name - Название таксономии
 * @property {string} display_type - Тип отображения ('select' или другой)
 */

/**
 * Данные формы создания задачи.
 *
 * @typedef {Object} TaskFormData
 * @property {string} termId - ID выбранного термина (номера задачи)
 * @property {string} title - Заголовок задачи
 * @property {string} boilerplateUid - UID выбранного шаблона (может быть пустым)
 */

/**
 * Данные для экспорта/импорта предмета.
 *
 * @typedef {Object} SubjectExportData
 * @property {Object} subject - Данные предмета
 * @property {Object} taxonomies - Таксономии предмета
 * @property {Object} terms - Термины таксономий
 * @property {Object} boilerplates - Шаблоны (boilerplates)
 * @property {Object} tasks - Задачи (посты)
 */

/**
 * Параметры запроса для таблицы постов.
 *
 * @typedef {Object} PostsTableRequest
 * @property {string} action - AJAX-действие
 * @property {string} security - Nonce для безопасности
 * @property {string} subject_key - Ключ предмета
 * @property {string} tab - Вкладка
 * @property {string} page_slug - Слаг страницы
 * @property {string} post_status - Статус постов (publish, draft, trash)
 * @property {number} paged - Номер страницы пагинации
 * @property {string} s - Поисковый запрос
 */

/**
 * Ответ сервера для таблицы постов.
 *
 * @typedef {Object} PostsTableResponse
 * @property {boolean} success - Флаг успешности
 * @property {PostsTableResponseData} data - Данные ответа
 */

/**
 * @typedef {Object} PostsTableResponseData
 * @property {string} html - HTML-код таблицы постов
 */

/**
 * Тип задачи (номер задачи).
 *
 * @typedef {Object} TaskType
 * @property {string} id - ID типа задачи
 * @property {string} slug - Слаг типа задачи
 * @property {string} description - Описание (отображаемый номер)
 */

/**
 * Шаблон (boilerplate).
 *
 * @typedef {Object} Boilerplate
 * @property {string} uid - Уникальный идентификатор шаблона
 * @property {string} title - Заголовок шаблона
 * @property {string} [content] - Содержимое шаблона
 */

/**
 * Ответ сервера для получения шаблонов.
 *
 * @typedef {Object} BoilerplatesResponse
 * @property {boolean} success - Флаг успешности
 * @property {Boilerplate[]} data - Массив шаблонов
 */

/**
 * Ответ сервера при создании задачи.
 *
 * @typedef {Object} CreateTaskResponse
 * @property {boolean} success - Флаг успешности
 * @property {CreateTaskResponseData} data - Данные ответа
 */

/**
 * @typedef {Object} CreateTaskResponseData
 * @property {string} redirect - URL для открытия созданной задачи
 * @property {string} [message] - Сообщение об ошибке
 */

/**
 * Запрос на обновление шаблона термина.
 *
 * @typedef {Object} UpdateTermTemplateRequest
 * @property {string} action - AJAX-действие
 * @property {string} security - Nonce для безопасности
 * @property {string} term_id - ID термина
 * @property {string} template - Выбранный шаблон
 * @property {string} key - Ключ предмета
 * @property {string} name - Название задачи
 */

/**
 * Строка таблицы таксономии (data-атрибуты).
 *
 * @typedef {Object} TaxonomyRowData
 * @property {string} slug - Слаг таксономии
 * @property {string} name - Название таксономии
 * @property {string} display - Тип отображения
 */

/**
 * Строка таблицы задач (data-атрибуты).
 *
 * @typedef {Object} TaskRowData
 * @property {string} term_id - ID термина (задачи)
 * @property {string} task_name - Название задачи
 */

/**
 * Параметры для модального окна таксономии.
 *
 * @typedef {Object} TaxonomyModalData
 * @property {string} [slug] - Слаг таксономии (для редактирования)
 * @property {string} [name] - Название таксономии (для редактирования)
 * @property {string} [display] - Тип отображения (для редактирования)
 */

/** ============================================ */
/**  ГЛОБАЛЬНЫЕ ПЕРЕМЕННЫЕ (для IDE)             */
/** ============================================ */

/**
 * @global
 * @type {FSLmsVars}
 */
window.fs_lms_vars = window.fs_lms_vars || /** @type {any} */ ({});

/**
 * @global
 * @type {FSLmsTaskData}
 */
window.fs_lms_task_data = window.fs_lms_task_data || /** @type {any} */ ({});