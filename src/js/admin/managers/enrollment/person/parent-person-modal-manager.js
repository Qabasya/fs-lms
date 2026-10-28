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
 * @requires PiiExportService - выгрузка ПД с предупреждением и выбором режима паролей
 */

import { ParentPersonModal } from '../../../modals/enrollment/person/parent-person-modal.js';
import { createPersonActions } from './person-modal-actions.js';
import { PiiExportService } from '../../../services/pii-export-service.js';

const $ = jQuery;

// Геттеры для глобальных переменных WordPress.
const NONCES   = () => fs_lms_applications_vars.nonces;
const ACTIONS  = () => fs_lms_vars.ajax_actions;
const AJAX_URL = () => fs_lms_vars.ajaxurl;

// AJAX-действия модалки; различия ролей — список полей и предложение нового пароля.
const actions = createPersonActions( ParentPersonModal, {
    editableFields: [
        'last_name', 'first_name', 'middle_name',
        'phone', 'email', 'password',
        'birth_date', 'doc_number', 'inn', 'address',
        'doc_issued_by', 'doc_issued_date',
    ],
} );

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

        // Снятие блокировки входа аккаунта родителя (после серии неверных паролей)
        $( document ).on( 'click.ppmm_unlock', '#fs-parent-person-modal .js-pmm-unlock', ( e ) => {
            e.preventDefault();
            actions.unlockLogin( ParentPersonModal.getWpUserId(), $( e.currentTarget ) );
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
        actions.startEditing();
    },

    /**
     * Раскрытие всех маскированных данных без включения режима редактирования.
     * Используется для просмотра полных данных без возможности их изменения.
     * @private
     */
    _revealAll() {
        actions.revealAll();
    },

    /**
     * Сохранение изменений данных родителя.
     * Фильтрует поля перед отправкой, исключая маскированные значения.
     * @private
     */
    _save() {
        actions.save();
    },

    /**
     * Экспорт персональных данных родителя в файл.
     * Получает URL для скачивания и перенаправляет браузер на него.
     * @private
     * @param {number} personId - ID родителя.
     */
    _export( personId ) {
        if ( ! personId ) return;

        // Единичный экспорт идёт через тот же сервис, что и массовый: одно
        // предупреждение о ПД и один флаг паролей на все точки выгрузки (A2).
        PiiExportService.exportParents( [ personId ] );
    },

    /**
     * Регенерация пароля для связанного пользователя WordPress.
     * @private
     * @param {number} wpUserId - ID пользователя WordPress.
     * @param {jQuery} $btn - jQuery-объект кнопки, на которую нажали.
     */
    _regeneratePassword( wpUserId, $btn ) {
        actions.regeneratePassword( wpUserId, $btn );
    },
};