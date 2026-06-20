/**
 * @module ApplicationEnrollmentModalManager
 * @description Менеджер для управления модальным окном зачисления студента по заявке.
 *              Отвечает за:
 *              - Инициализацию и заполнение модалки данными (периоды, предметы, группы)
 *              - Обработку открытия модалки (с предварительным запуском процесса зачисления или без)
 *              - Динамическую подгрузку групп при смене периода/предмета (каскадные dropdowns)
 *              - Финальное зачисление студента через AJAX
 *              - Откат статуса заявки, если пользователь закрыл модалку без зачисления
 *
 * @requires jQuery
 * @requires ApplicationEnrollmentModal - UI-компонент модального окна
 * @requires showModalError, clearModalError - утилиты для отображения ошибок в модалке
 */

import { ApplicationEnrollmentModal } from '../../../modals/enrollment/applications/application-enrollment-modal.js';
import { showModalError, clearModalError } from '../../../modules/utils.js';

// Глобальный алиас для jQuery
const $ = jQuery;

// Локальная ссылка на глобальный объект с переменными WordPress для этого модуля.
//wp_localize_script позволяет передать PHP-переменные (ajaxurl, nonce, имена действий) в JS.
// Здесь используется отдельный объект `fs_lms_applications_vars`, так как nonce для зачисления 
// могут отличаться от общих nonce менеджера.
const appVars = window.fs_lms_applications_vars;

/**
 * Основной объект-менеджер.
 * Методы с префиксом `_` — внутренние, не предназначены для вызова извне.
 */
export const ApplicationEnrollmentModalManager = {

    /**
     * Инициализация менеджера.
     * Точка входа, вызывается при загрузке страницы.
     */
    init() {
        // Инициализируем сам UI модального окна
        ApplicationEnrollmentModal.init();

        //Защита от повторной инициализации.
        // Если модальное окно уже было инициализировано (например, скрипт подгрузился дважды),
        // мы не будем заново навешивать обработчики событий и парсить данные — это предотвратит
        // дублирование событий и утечки памяти.
        if ( ! ApplicationEnrollmentModal._initialized ) return;

        this._loadModalOptions();
        this._bindEvents();
    },

    /**
     * Загрузка начальных опций для выпадающих списков в модалке.
     * Считывает JSON из data-атрибутов контейнера модалки, которые были отрендерены на бэкенде.
     * @private
     */
    _loadModalOptions() {
        const $modal    = $( '#fs-application-enrollment-modal' );

        //Парсинг JSON из data-атрибута.
        // Оператор `|| '[]'` — защита от случая, когда атрибут пустой или отсутствует.
        // JSON.parse('') или JSON.parse(undefined) выбросит SyntaxError и сломает весь скрипт.
        // Поэтому всегда передаём fallback — пустой массив в виде строки.
        const periods   = JSON.parse( $modal.attr( 'data-periods' )  || '[]' );
        const subjects  = JSON.parse( $modal.attr( 'data-subjects' ) || '[]' );

        // ID текущего (активного) учебного периода — чтобы сразу выбрать его в dropdown
        const currentId = $modal.attr( 'data-current-period' ) || '';

        ApplicationEnrollmentModal.populatePeriods( periods, currentId );
        ApplicationEnrollmentModal.populateSubjects( subjects );
    },

    /**
     * Привязка обработчиков событий.
     * @private
     */
    _bindEvents() {
        // Открытие модалки по клику на кнопку "Зачислить".
        // Используется делегирование событий через $(document).on(...) — см. пояснение в предыдущем модуле.
        $( document ).on( 'click', '.js-enrollment-application', ( e ) => {
            e.preventDefault();
            const $btn   = $( e.currentTarget );
            const appId  = $btn.data( 'id' );
            const status = $btn.data( 'status' );

            //Два разных UX-сценария в зависимости от статуса заявки.
            // Если заявка в статусе 'ready_for_review', значит процесс зачисления ещё не начат,
            // и нужно сначала сделать AJAX-запрос на сервер, чтобы "зарезервировать" заявку 
            // (сменить её статус на "в процессе зачисления"). Иначе другой администратор 
            // может одновременно открыть ту же заявку и возникнет конфликт.
            if ( status === 'ready_for_review' ) {
                this._handleStartThenOpen( appId, $btn );
            } else {
                // Если заявка уже в процессе — просто открываем модалку с её данными
                this._handleOpen( appId );
            }
        } );

        // Обработчик кнопки "Начать зачисление" внутри модалки (если она там есть)
        $( document ).on( 'click', '.js-start-enrollment', ( e ) => {
            e.preventDefault();
            this._handleStartEnrollment( $( e.currentTarget ) );
        } );

        // КАСКАДНЫЕ DROPDOWNS: При изменении периода или предмета — перезагружаем список групп.
        //Группы зависят и от периода, и от предмета. Поэтому при изменении любого 
        // из этих полей нужно заново запросить у сервера доступные группы.
        $( document ).on( 'change', '#enroll-period, #enroll-subject', () => {
            this._reloadGroups();
        } );

        // Подписка на событие "Зачислить" внутри модалки
        ApplicationEnrollmentModal.onEnroll( ( data ) => this._handleEnroll( data ) );

        // Подписка на событие закрытия модалки (крестик, ESC, клик вне модалки).
        // Нужно, чтобы откатить статус заявки, если пользователь передумал зачислять.
        ApplicationEnrollmentModal.onClose( ( data ) => this._handleRevertStatus( data.application_id ) );
    },

    /**
     * Открытие модалки с загрузкой данных заявки.
     * @private
     * @param {string|number} appId - ID заявки.
     */
    _handleOpen( appId ) {
        ApplicationEnrollmentModal.open( appId );
        this._loadApplicationData( appId );
    },

    /**
     * Запуск процесса зачисления (смена статуса заявки) по клику на кнопку ВНУТРИ модалки.
     * @private
     * @param {jQuery} $btn - jQuery-объект нажатой кнопки.
     */
    _handleStartEnrollment( $btn ) {
        //Используем .prop() вместо .attr() для булевых атрибутов (disabled, checked, selected).
        // .attr() работает с HTML-разметкой (изначальным состоянием), а .prop() — с текущим состоянием DOM-элемента.
        // Для disabled всегда используйте .prop(), иначе кнопка может не заблокироваться корректно.
        $btn.prop( 'disabled', true );

        $.post( fs_lms_vars.ajaxurl, {
            action:         fs_lms_vars.ajax_actions.startEnrollment,
            security:       appVars.nonces.manager,
            application_id: $btn.data( 'id' ),
        } )
            .done( ( res ) => {
                if ( res.success ) {
                    // После успешного старта — перезагружаем страницу, чтобы обновить статус заявки в списке
                    location.reload();
                } else {
                    showModalError( res.data?.message || res.data || 'Ошибка.', ApplicationEnrollmentModal.$modal );
                    $btn.prop( 'disabled', false ); // Разблокируем кнопку при ошибке
                }
            } )
            .fail( () => {
                showModalError( 'Ошибка соединения.', ApplicationEnrollmentModal.$modal );
                $btn.prop( 'disabled', false );
            } );
    },

    /**
     * Запуск процесса зачисления И последующее открытие модалки.
     * Используется, когда пользователь нажимает "Зачислить" на ещё не начатой заявке.
     * @private
     * @param {string|number} appId - ID заявки.
     * @param {jQuery} $btn - jQuery-объект нажатой кнопки.
     */
    _handleStartThenOpen( appId, $btn ) {
        $btn.prop( 'disabled', true );

        $.post( fs_lms_vars.ajaxurl, {
            action:         fs_lms_vars.ajax_actions.startEnrollment,
            security:       appVars.nonces.manager,
            application_id: appId,
        } )
            .done( ( res ) => {
                $btn.prop( 'disabled', false );
                if ( res.success ) {
                    //После успешного старта процесса — сразу открываем модалку, 
                    // не дожидаясь перезагрузки страницы. Это улучшает UX: пользователь видит 
                    // результат своего действия мгновенно.
                    this._handleOpen( appId );
                } else {
                    showModalError( res.data?.message || res.data || 'Ошибка.', ApplicationEnrollmentModal.$modal );
                }
            } )
            .fail( () => {
                showModalError( 'Ошибка соединения.', ApplicationEnrollmentModal.$modal );
                $btn.prop( 'disabled', false );
            } );
    },

    /**
     * Загрузка детальных данных заявки (информация о студенте и родителе).
     * @private
     * @param {string|number} appId - ID заявки.
     */
    _loadApplicationData( appId ) {
        $.post( fs_lms_vars.ajaxurl, {
            action:         fs_lms_vars.ajax_actions.getApplicationData,
            security:       appVars.nonces.manager,
            application_id: appId,
        } )
            .done( ( res ) => {
                if ( res.success ) {
                    // Заполняем модалку данными студента и родителя.
                    //Разделяем данные на два вызова, так как они отображаются 
                    // в разных вкладках/секциях модалки. Это делает код UI-компонента чище.
                    ApplicationEnrollmentModal.populateStudentData( res.data.student );
                    ApplicationEnrollmentModal.populateParentData( res.data.parent );

                    // Предвыбор предмета, если заявка привязана к направлению (Этап 0).
                    if ( res.data.subject_key ) {
                        $( '#enroll-subject' ).val( res.data.subject_key ).trigger( 'change' );
                    }
                }
            } );
    },

    /**
     * Перезагрузка списка групп при изменении периода или предмета.
     * Реализует паттерн "каскадных dropdowns" (зависимых выпадающих списков).
     * @private
     */
    _reloadGroups() {
        const periodId  = $( '#enroll-period' ).val();
        const subjectId = $( '#enroll-subject' ).val();

        //Ранний выход (early return), если не выбраны обязательные поля.
        // Это предотвращает лишний AJAX-запрос на сервер и сразу очищает список групп.
        if ( ! periodId || ! subjectId ) {
            ApplicationEnrollmentModal.populateGroups( [] );
            return;
        }

        $.post( fs_lms_vars.ajaxurl, {
            action:    fs_lms_vars.ajax_actions.getStudentGroups,
            security:  appVars.nonces.manager,
            period_id:  periodId,
            subject_id: subjectId,
        } )
            .done( ( res ) => {
                if ( res.success ) {
                    ApplicationEnrollmentModal.populateGroups( res.data );
                }
            } );
    },

    /**
     * Финальное зачисление студента после заполнения всех данных в модалке.
     * @private
     * @param {Object} data - Данные формы из модального окна.
     */
    _handleEnroll( data ) {
        // Блокируем кнопку "Зачислить" и показываем спиннер
        ApplicationEnrollmentModal.setEnrollState( true );

        $.post( fs_lms_vars.ajaxurl, {
            action:         fs_lms_vars.ajax_actions.enrollStudent,
            security:       appVars.nonces.enroll, // Отдельный nonce именно для действия зачисления
            application_id: data.application_id,
            contract_no:    data.contract_no,
            contract_date:  data.contract_date,
            order_no:       data.order_no,
            order_date:     data.order_date,
            enrolled_at:    data.enrolled_at,
            period_key:     data.period_key,
            subject_key:    data.subject_key,
            group_id:       data.group_id,
            send_email_auto: data.send_email_auto, // Флаг: отправить ли студенту письмо автоматически
        } )
            .done( ( res ) => {
                if ( res.success ) {
                    // Визуально помечаем модалку как "успешно завершено" (показываем галочку/анимацию)
                    ApplicationEnrollmentModal.markCompleted();
                    // Закрываем модалку с небольшой задержкой, чтобы пользователь увидел анимацию успеха
                    ApplicationEnrollmentModal.close();
                    // Перезагружаем страницу, чтобы обновить список заявок
                    location.reload();
                } else {
                    showModalError( res.data?.message || res.data || 'Ошибка зачисления.', ApplicationEnrollmentModal.$modal );
                    ApplicationEnrollmentModal.setEnrollState( false ); // Разблокируем кнопку при ошибке
                }
            } )
            .fail( () => {
                showModalError( 'Ошибка соединения.', ApplicationEnrollmentModal.$modal );
                ApplicationEnrollmentModal.setEnrollState( false );
            } );
    },

    /**
     * Откат статуса заявки при закрытии модалки без зачисления.
     *Это важный UX-паттерн "бронирования".
     * Когда мы открыли модалку для заявки в статусе 'ready_for_review', мы на сервере
     * поменяли её статус на "в процессе зачисления" (чтобы другие админы не могли её редактировать).
     * Если пользователь закрыл модалку, не нажав "Зачислить", нужно вернуть статус обратно,
     * иначе заявка "зависнет" в промежуточном состоянии навсегда.
     * @private
     * @param {string|number} appId - ID заявки.
     */
    _handleRevertStatus( appId ) {
        //Здесь мы НЕ обрабатываем .done() и .fail() — это "fire and forget" запрос.
        // Нам не важно, успешно ли откатился статус: если нет, администратор просто увидит заявку 
        // в статусе "в процессе" и сможет открыть её снова. Главное — не блокировать UI ожиданием.
        $.post( fs_lms_vars.ajaxurl, {
            action:         fs_lms_vars.ajax_actions.cancelEnrollment,
            security:       appVars.nonces.manager,
            application_id: appId,
        } );
    },
};