/**
 * @module ApplicationModalManager
 * @description Менеджер для управления модальным окном редактирования заявки.
 *              Отвечает за:
 *              - Инициализацию модального окна
 *              - Открытие модалки с данными заявки из data-атрибутов кнопки
 *              - Сохранение изменений через AJAX
 *              - Обработку ошибок и блокировку кнопки во время запроса
 *
 * @requires jQuery
 * @requires ApplicationModal - UI-компонент модального окна
 * @requires showModalError - утилита для отображения ошибок в модалке
 */

import { ApplicationModal } from '../modals/application-modal.js';
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
export const ApplicationModalManager = {

    /**
     * Инициализация менеджера.
     * Точка входа, вызывается при загрузке страницы.
     */
    init() {
        // Инициализируем сам UI модального окна
        ApplicationModal.init();

        // Защита от повторной инициализации.
        // Если модальное окно уже было инициализировано (например, скрипт подгрузился дважды),
        // мы не будем заново навешивать обработчики событий — это предотвратит
        // дублирование событий и утечки памяти.
        if ( ! ApplicationModal._initialized ) return;

        this._bindEvents();
    },

    /**
     * Привязка обработчиков событий.
     * Используется делегирование событий через $(document).on(...) для элементов,
     * которые могут быть добавлены в DOM динамически (после AJAX-подгрузки или рендера).
     * Если написать $('.js-edit-application').on(...), то на новые элементы событие не навесится.
     */
    _bindEvents() {
        $( document ).on( 'click', '.js-edit-application', ( e ) => {
            e.preventDefault(); // Отменяет стандартное поведение браузера (переход по ссылке, прокрутка)
            this._handleOpen( $( e.currentTarget ) );
        } );

        // Подписка на событие сохранения внутри модального окна.
        // Когда пользователь нажимает "Сохранить" в модалке, она вызывает этот колбэк с данными формы.
        ApplicationModal.onSave( ( data ) => this._handleSave( data ) );
    },

    /**
     * Открытие модального окна с данными заявки.
     * Считывает информацию из data-* атрибутов нажатой кнопки и передает её в модалку.
     * @param {jQuery} $btn - jQuery-объект нажатой кнопки редактирования.
     */
    _handleOpen( $btn ) {
        // jQuery .data() автоматически считывает атрибуты data-* и приводит типы.
        // Дефис в HTML (data-last-name) автоматически превращается в camelCase при обращении.
        ApplicationModal.open( {
            id:          $btn.data( 'id' ),
            last_name:   $btn.data( 'last-name' ),
            first_name:  $btn.data( 'first-name' ),
            middle_name: $btn.data( 'middle-name' ),
            birth_date:  $btn.data( 'birth-date' ),
            email:       $btn.data( 'email' ),
            phone:       $btn.data( 'phone' ),
            school:      $btn.data( 'school' ),
            // data-атрибуты всегда строки. Явно преобразуем grade в строку,
            // чтобы избежать проблем с типами при отправке на сервер.
            grade:       String( $btn.data( 'grade' ) ),
        } );
    },

    /**
     * Сохранение изменений данных заявки.
     * Отправляет AJAX-запрос на сервер с блокировкой кнопки для защиты от двойного клика.
     * @param {Object} data - Данные формы из модального окна.
     */
    _handleSave( data ) {
        // Блокируем кнопку сохранения и показываем спиннер, чтобы пользователь не нажал её дважды.
        // Это защищает от создания дубликатов или конфликтов при быстром повторном клике.
        ApplicationModal.setSaveState( true );

        $.post( fs_lms_vars.ajaxurl, {
            action:         fs_lms_vars.ajax_actions.updateApplicationData,
            security:       appVars.nonces.edit, // Nonce защищает от CSRF-атак
            application_id: data.application_id,
            last_name:      data.last_name,
            first_name:     data.first_name,
            middle_name:    data.middle_name,
            birth_date:     data.birth_date,
            email:          data.email,
            phone:          data.phone,
            school:         data.school,
            grade:          data.grade,
        } )
            .done( ( res ) => {
                // res - это ответ от сервера в формате { success: true/false, data: {...} }
                if ( res.success ) {
                    // Закрываем модалку и перезагружаем страницу, чтобы обновить данные в списке заявок
                    ApplicationModal.close();
                    location.reload();
                } else {
                    // Показываем ошибку внутри модального окна
                    // Оператор ?. (опциональная цепочка) предотвращает ошибку, если res.data равен undefined
                    showModalError( res.data?.message || res.data || 'Ошибка сохранения.', ApplicationModal.$modal );
                    // Разблокируем кнопку, чтобы пользователь мог исправить данные и попробовать снова
                    ApplicationModal.setSaveState( false );
                }
            } )
            .fail( () => {
                // Сетевая ошибка (потеря интернета, ошибка 500 на сервере, таймаут)
                showModalError( 'Ошибка соединения.', ApplicationModal.$modal );
                ApplicationModal.setSaveState( false );
            } );
    },
};