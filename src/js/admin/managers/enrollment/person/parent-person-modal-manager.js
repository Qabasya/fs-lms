/**
 * @module ParentPersonModalManager
 * @description Менеджер для управления модальным окном просмотра и редактирования данных родителя.
 *              Отвечает за:
 *              - Отображение данных родителя с маскированием персональных данных (PII)
 *              - Раскрытие полных данных по запросу администратора
 *              - Редактирование и сохранение изменений
 *              - Экспорт персональных данных в файл
 *              - Регенерацию пароля для связанного пользователя WordPress
 *              - Подписку на глобальные события системы (например, отчисление студента)
 *
 * @requires jQuery
 * @requires ParentPersonModal - UI-компонент модального окна
 */

import { ParentPersonModal } from '../../../modals/enrollment/person/parent-person-modal.js';

const $ = jQuery;

// Геттеры для глобальных переменных WordPress.
const NONCES   = () => fs_lms_applications_vars.nonces;
const ACTIONS  = () => fs_lms_vars.ajax_actions;
const AJAX_URL = () => fs_lms_vars.ajaxurl;

/**
 * Основной объект-менеджер.
 * Методы с префиксом `_` — внутренние, не предназначены для вызова извне.
 */
export const ParentPersonModalManager = {

    /**
     * Флаг инициализации для предотвращения повторного навешивания обработчиков событий.
     * @private
     * @type {boolean}
     */
    _initialized: false,

    /**
     * Инициализация менеджера.
     * Точка входа, вызывается при загрузке страницы.
     */
    init() {
        // Защита от повторной инициализации (паттерн Singleton)
        if ( this._initialized ) return;

        ParentPersonModal.init();

        // Дополнительная проверка: если сам UI-компонент не инициализировался, 
        // не навешиваем обработчики событий менеджера
        if ( ! ParentPersonModal._initialized ) return;

        this._initialized = true;
        this._bindEvents();
    },

    /**
     * Привязка обработчиков событий.
     * Используется неймспейсинг событий (например, 'click.ppmm') для точечного управления обработчиками.
     * @private
     */
    _bindEvents() {
        // Открытие модального окна при клике на кнопку просмотра родителя
        $( document ).on( 'click.ppmm', '.js-view-person[data-person-type="parent"]', ( e ) => {
            e.preventDefault();
            this._openModal( $( e.currentTarget ) );
        } );

        // Раскрытие всех маскированных персональных данных
        $( document ).on( 'click.ppmm_reveal', '#fs-parent-person-modal .js-reveal-all', ( e ) => {
            e.preventDefault();
            this._revealAll();
        } );

        // Включение режима редактирования
        $( document ).on( 'click.ppmm_edit', '#fs-parent-person-modal .js-pmm-edit', ( e ) => {
            e.preventDefault();
            this._startEditing();
        } );

        // Отмена редактирования
        $( document ).on( 'click.ppmm_cancel', '#fs-parent-person-modal .js-pmm-cancel', ( e ) => {
            e.preventDefault();
            ParentPersonModal.setEditing( false );
        } );

        // Сохранение изменений
        $( document ).on( 'click.ppmm_save', '#fs-parent-person-modal .js-pmm-save', ( e ) => {
            e.preventDefault();
            this._save();
        } );

        // Экспорт персональных данных в файл
        $( document ).on( 'click.ppmm_export', '.js-export-person[data-person-type="parent"]', ( e ) => {
            e.preventDefault();
            const personId = parseInt( $( e.currentTarget ).data( 'personId' ), 10 );
            if ( personId ) this._export( personId );
        } );

        // Подписка на кастомное событие отчисления студента.
        // Если студент отчислен, закрываем модальное окно родителя, так как данные больше не актуальны.
        $( document ).on( 'fs:student:expelled', () => {
            ParentPersonModal.close();
        } );

        // Подписка на событие регенерации пароля (может быть вызвано из других частей системы)
        $( document ).on( 'fs-lms:regenerate-password', ( e, { wpUserId, $btn } ) => {
            this._regeneratePassword( wpUserId, $btn );
        } );
    },

    /**
     * Открытие модального окна с данными родителя.
     * Сначала заполняет модалку базовыми данными из DOM, затем загружает маскированные PII через AJAX.
     * @private
     * @param {jQuery} $btn - jQuery-объект кнопки, на которую нажали.
     */
    _openModal( $btn ) {
        const personId = parseInt( $btn.data( 'personId' ), 10 ) || 0;
        const wpUserId = parseInt( $btn.data( 'wpUserId' ), 10 ) || 0;

        // Извлекаем дополнительные данные из data-атрибутов строки таблицы.
        // Оператор || {} предотвращает ошибки, если атрибут отсутствует.
        const rowData  = $btn.closest( 'tr' ).data( 'parent' ) || {};

        // Сбрасываем состояние модалки перед открытием
        ParentPersonModal.reset();
        ParentPersonModal.setPersonId( personId );
        ParentPersonModal.setWpUserId( wpUserId );

        // Заполняем модалку базовыми данными, доступными в DOM (не требуют маскирования)
        ParentPersonModal.fill( {
            display_name:   $btn.data( 'displayName' ) || '',
            last_name:      rowData.last_name           || '',
            first_name:     rowData.first_name          || '',
            middle_name:    rowData.middle_name         || '',
            email:          $btn.data( 'email' )        || '',
            phone:          rowData.phone               || '',
            dependent_name: rowData.children            || '',
            birth_date:     rowData.birth_date          || '',
        } );

        ParentPersonModal.open();

        // Если personId отсутствует, не делаем AJAX-запрос (новый родитель или тестовые данные)
        if ( ! personId ) return;

        // LAZY LOADING: Загружаем маскированные персональные данные только при открытии модалки.
        // Это оптимизирует производительность: не нужно загружать PII для всех строк таблицы сразу.
        $.post( AJAX_URL(), {
            action:    ACTIONS().getPersonData,
            person_id: personId,
            security:  NONCES().manager,
        } ).done( ( res ) => {
            if ( ! res.success ) return;

            // Заполняем модалку маскированными данными (телефон, документы, ИНН и т.д.)
            const pii = res.data.masked_pii || {};
            ParentPersonModal.fill( {
                phone:          pii.phone          || '',
                doc_number:     pii.doc_number     || '',
                inn:            pii.inn            || '',
                address:        pii.address        || '',
                doc_issued_by:  pii.doc_issued_by  || '',
                doc_issued_date: pii.doc_issued_date || '',
            } );
        } );
    },

    /**
     * Включение режима редактирования с предварительной загрузкой полных (немаскированных) данных.
     * Параллельно запрашивает PII и учетные данные пользователя WordPress.
     * @private
     */
    _startEditing() {
        const personId = ParentPersonModal.getPersonId();
        const wpUserId = ParentPersonModal.getWpUserId();

        // Если personId отсутствует (новый родитель), просто включаем режим редактирования
        if ( ! personId ) {
            ParentPersonModal.setEditing( true );
            return;
        }

        // ПАТТЕРН: Параллельные AJAX-запросы через $.when
        // Запрашиваем полные персональные данные (без маскирования)
        const piiPromise = $.post( AJAX_URL(), {
            action:    ACTIONS().revealAllPersonPii,
            person_id: personId,
            reason:    'admin_userlist_edit', // Причина раскрытия для логирования/аудита
            security:  NONCES().revealPii,
        } ).done( ( res ) => {
            if ( res.success ) ParentPersonModal.fillRevealed( res.data );
        } );

        // Если есть связанный пользователь WordPress, запрашиваем его пароль
        const credPromise = wpUserId
            ? $.post( AJAX_URL(), {
                action:   ACTIONS().revealUserCredentials,
                user_id:  wpUserId,
                security: NONCES().revealPii,
            } ).done( ( res ) => {
                if ( res.success ) ParentPersonModal.fillRevealed( { password: res.data.password || '' } );
            } )
            : $.Deferred().resolve(); // Если wpUserId нет, создаем сразу разрешенный Deferred

        // Ждем завершения обоих запросов, затем включаем режим редактирования.
        // .always() срабатывает независимо от успеха/ошибки, гарантируя, что UI не заблокируется.
        $.when( piiPromise, credPromise ).always( () => {
            ParentPersonModal.setEditing( true );
        } );
    },

    /**
     * Раскрытие всех маскированных данных без включения режима редактирования.
     * Используется для просмотра полных данных без возможности их изменения.
     * @private
     */
    _revealAll() {
        const personId = ParentPersonModal.getPersonId();
        const wpUserId = ParentPersonModal.getWpUserId();
        if ( ! personId ) return;

        // Раскрываем PII
        $.post( AJAX_URL(), {
            action:    ACTIONS().revealAllPersonPii,
            person_id: personId,
            reason:    'admin_userlist_reveal',
            security:  NONCES().revealPii,
        } ).done( ( res ) => {
            if ( res.success ) ParentPersonModal.fillRevealed( res.data );
        } );

        // Раскрываем пароль, если есть связанный пользователь
        if ( wpUserId ) {
            $.post( AJAX_URL(), {
                action:   ACTIONS().revealUserCredentials,
                user_id:  wpUserId,
                security: NONCES().revealPii,
            } ).done( ( res ) => {
                if ( res.success ) ParentPersonModal.fillRevealed( { password: res.data.password || '' } );
            } );
        }
    },

    /**
     * Сохранение изменений данных родителя.
     * Фильтрует поля перед отправкой, исключая маскированные значения.
     * @private
     */
    _save() {
        const personId = ParentPersonModal.getPersonId();
        if ( ! personId ) return;

        // Белый список полей, которые разрешено редактировать.
        // Это защита от mass assignment атак, когда злоумышленник может подменить дополнительные поля в запросе.
        const allowed = [
            'last_name', 'first_name', 'middle_name',
            'phone', 'email', 'password',
            'birth_date', 'doc_number', 'inn', 'address',
            'doc_issued_by', 'doc_issued_date',
        ];

        const edit = ParentPersonModal.getEditData();
        const payload = {
            action:    ACTIONS().updatePerson,
            security:  NONCES().updatePerson,
            person_id: personId,
        };

        // Фильтрация полей: добавляем в payload только разрешенные поля, 
        // которые не содержат символ '•' (маркер маскированного значения).
        // Это предотвращает отправку на сервер маскированных данных вместо реальных.
        allowed.forEach( k => {
            if ( edit[ k ] && ! edit[ k ].includes( '•' ) ) payload[ k ] = edit[ k ];
        } );

        $.post( AJAX_URL(), payload ).done( ( res ) => {
            if ( res.success ) ParentPersonModal.setEditing( false );
        } );
    },

    /**
     * Экспорт персональных данных родителя в файл.
     * Получает URL для скачивания и перенаправляет браузер на него.
     * @private
     * @param {number} personId - ID родителя.
     */
    _export( personId ) {
        if ( ! personId ) return;

        $.post( AJAX_URL(), {
            action:    ACTIONS().exportPii,
            person_id: personId,
            security:  NONCES().exportPii,
        } ).done( r => {
            // Если сервер вернул URL для скачивания, перенаправляем браузер.
            // Это стандартный паттерн для скачивания файлов через AJAX: 
            // сервер генерирует временную ссылку, а клиент инициирует скачивание.
            if ( r.success && r.data.download_url ) window.location.href = r.data.download_url;
        } );
    },

    /**
     * Регенерация пароля для связанного пользователя WordPress.
     * @private
     * @param {number} wpUserId - ID пользователя WordPress.
     * @param {jQuery} $btn - jQuery-объект кнопки, на которую нажали.
     */
    _regeneratePassword( wpUserId, $btn ) {
        // Блокируем кнопку для предотвращения повторных кликов
        $btn.prop( 'disabled', true );

        $.post( AJAX_URL(), {
            action:   ACTIONS().regenerateUserPassword,
            user_id:  wpUserId,
            security: NONCES().revealPii,
        } ).done( ( res ) => {
            if ( res.success ) {
                // Обновляем отображение пароля в модалке
                ParentPersonModal.fillRevealed( { password: res.data.password || '' } );
                // Удаляем кнопку, так как пароль уже сгенерирован
                $btn.remove();
            } else {
                // Разблокируем кнопку при ошибке
                $btn.prop( 'disabled', false );
            }
        } );
    },
};