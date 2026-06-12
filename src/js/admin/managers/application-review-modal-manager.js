/**
 * @module ApplicationReviewModalManager
 * @description Менеджер для управления модальным окном проверки заявки.
 *              Отвечает за:
 *              - Инициализацию модального окна
 *              - Открытие модалки с данными студента и родителя из data-атрибутов кнопки
 *              - Сохранение изменений через AJAX
 *              - Обработку ошибок и блокировку кнопки во время запроса
 *
 * @requires jQuery
 * @requires ApplicationReviewModal - UI-компонент модального окна
 * @requires showModalError - утилита для отображения ошибок в модалке
 */

import { ApplicationReviewModal } from '../modals/application-review-modal.js';
import { showModalError, clearModalError } from '../modules/utils.js';

// Глобальный алиас для jQuery
const $ = jQuery;

// Локальная ссылка на глобальный объект с переменными WordPress для этого модуля.
// wp_localize_script позволяет передать PHP-переменные (ajaxurl, nonce, имена действий) в JS.
const appVars = window.fs_lms_applications_vars;

/**
 * Основной объект-менеджер.
 * Методы с префиксом `_` — внутренние, не предназначены для вызова извне.
 */
export const ApplicationReviewModalManager = {

    /**
     * Инициализация менеджера.
     * Точка входа, вызывается при загрузке страницы.
     */
    init() {
        // Инициализируем сам UI модального окна
        ApplicationReviewModal.init();

        // Защита от повторной инициализации.
        // Если модальное окно уже было инициализировано (например, скрипт подгрузился дважды),
        // мы не будем заново навешивать обработчики событий — это предотвратит
        // дублирование событий и утечки памяти.
        if ( ! ApplicationReviewModal._initialized ) return;

        this._bindEvents();
    },

    /**
     * Привязка обработчиков событий.
     * Используется делегирование событий через $(document).on(...) для элементов,
     * которые могут быть добавлены в DOM динамически (после AJAX-подгрузки или рендера).
     * Если написать $('.js-review-application').on(...), то на новые элементы событие не навесится.
     */
    _bindEvents() {
        $( document ).on( 'click', '.js-review-application', ( e ) => {
            e.preventDefault(); // Отменяет стандартное поведение браузера (переход по ссылке, прокрутка)
            this._handleOpen( $( e.currentTarget ) );
        } );

        // Подписка на событие сохранения внутри модального окна.
        // Когда пользователь нажимает "Сохранить" в модалке, она вызывает этот колбэк с данными формы.
        ApplicationReviewModal.onSave( ( data ) => this._handleSave( data ) );
    },

    /**
     * Открытие модального окна с данными заявки.
     * Считывает информацию о студенте и родителе из data-* атрибутов нажатой кнопки.
     * @param {jQuery} $btn - jQuery-объект нажатой кнопки проверки.
     */
    _handleOpen( $btn ) {
        // jQuery .data() автоматически считывает атрибуты data-* и приводит типы.
        // Дефис в HTML (data-s-last-name) автоматически превращается в camelCase при обращении.
        ApplicationReviewModal.open( {
            id:                    $btn.data( 'id' ),
            // Данные студента (префикс s-)
            student_last_name:     $btn.data( 's-last-name' ),
            student_first_name:    $btn.data( 's-first-name' ),
            student_middle_name:   $btn.data( 's-middle-name' ),
            student_birth_date:    $btn.data( 's-birth-date' ),
            student_doc_type:      $btn.data( 's-doc-type' ),
            student_doc_number:    $btn.data( 's-doc-number' ),
            student_inn:           $btn.data( 's-inn' ),
            // Данные родителя (префикс p-)
            parent_last_name:      $btn.data( 'p-last-name' ),
            parent_first_name:     $btn.data( 'p-first-name' ),
            parent_middle_name:    $btn.data( 'p-middle-name' ),
            parent_birth_date:     $btn.data( 'p-birth-date' ),
            parent_email:          $btn.data( 'p-email' ),
            parent_phone:          $btn.data( 'p-phone' ),
            parent_doc_type:       $btn.data( 'p-doc-type' ),
            parent_doc_number:     $btn.data( 'p-doc-number' ),
            parent_doc_issued_by:  $btn.data( 'p-doc-issued-by' ),
            parent_doc_issued_date: $btn.data( 'p-doc-issued-date' ),
            parent_inn:            $btn.data( 'p-inn' ),
            parent_address:        $btn.data( 'p-address' ),
        } );
    },

    /**
     * Сохранение изменений данных заявки (студент и родитель).
     * Отправляет AJAX-запрос на сервер с блокировкой кнопки для защиты от двойного клика.
     * @param {Object} data - Данные формы из модального окна.
     */
    _handleSave( data ) {
        // Блокируем кнопку сохранения и показываем спиннер, чтобы пользователь не нажал её дважды.
        // Это защищает от создания дубликатов или конфликтов при быстром повторном клике.
        ApplicationReviewModal.setSaveState( true );

        $.post( fs_lms_vars.ajaxurl, {
            action:                 fs_lms_vars.ajax_actions.updateReviewData,
            security:               appVars.nonces.review, // Nonce защищает от CSRF-атак
            application_id:         data.application_id,
            // Данные студента
            student_last_name:      data.student_last_name,
            student_first_name:     data.student_first_name,
            student_middle_name:    data.student_middle_name,
            student_birth_date:     data.student_birth_date,
            student_doc_type:       data.student_doc_type,
            student_doc_number:     data.student_doc_number,
            student_inn:            data.student_inn,
            // Данные родителя
            parent_last_name:       data.parent_last_name,
            parent_first_name:      data.parent_first_name,
            parent_middle_name:     data.parent_middle_name,
            parent_birth_date:      data.parent_birth_date,
            parent_email:           data.parent_email,
            parent_phone:           data.parent_phone,
            parent_doc_type:        data.parent_doc_type,
            parent_doc_number:      data.parent_doc_number,
            parent_doc_issued_by:   data.parent_doc_issued_by,
            parent_doc_issued_date: data.parent_doc_issued_date,
            parent_inn:             data.parent_inn,
            parent_address:         data.parent_address,
        } )
            .done( ( res ) => {
                // res - это ответ от сервера в формате { success: true/false, data: {...} }
                if ( res.success ) {
                    // Закрываем модалку и перезагружаем страницу, чтобы обновить данные в списке заявок
                    ApplicationReviewModal.close();
                    location.reload();
                } else {
                    // Показываем ошибку внутри модального окна
                    // Оператор ?. (опциональная цепочка) предотвращает ошибку, если res.data равен undefined
                    showModalError( res.data?.message || res.data || 'Ошибка сохранения.', ApplicationReviewModal.$modal );
                    // Разблокируем кнопку, чтобы пользователь мог исправить данные и попробовать снова
                    ApplicationReviewModal.setSaveState( false );
                }
            } )
            .fail( () => {
                // Сетевая ошибка (потеря интернета, ошибка 500 на сервере, таймаут)
                showModalError( 'Ошибка соединения.', ApplicationReviewModal.$modal );
                ApplicationReviewModal.setSaveState( false );
            } );
    },
};