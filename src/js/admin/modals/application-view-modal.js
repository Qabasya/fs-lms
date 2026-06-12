/**
 * @module ApplicationViewModal
 * @description UI-компонент модального окна просмотра заявки (read-only).
 *              Отвечает за:
 *              - Отображение детальной информации о студенте и родителе в режиме "только чтение"
 *              - Управление аккордеоном для организации контента в логические секции
 *              - Заполнение полей через map-объект и итерацию Object.entries
 *              - Чтение данных из data-атрибутов кнопки-триггера
 *
 *              В отличие от ApplicationModal и ApplicationReviewModal, это окно НЕ поддерживает
 *              редактирование. Оно используется для быстрого просмотра заявки без возможности изменения.
 *
 * @requires jQuery
 * @requires openModal, closeModal, bindEsc, unbindEsc - базовые утилиты управления модальными окнами
 */

import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

/**
 * UI-компонент модального окна просмотра заявки.
 * Эта модалка полностью read-only: пользователь может только просматривать данные,
 * но не редактировать их. Для редактирования используются ApplicationModal
 * и ApplicationReviewModal.
 */
export const ApplicationViewModal = {
    /** @type {jQuery|null} Ссылка на основной контейнер модального окна */
    $modal: null,

    /** @type {boolean} Флаг для предотвращения повторной инициализации */
    _initialized: false,

    /**
     * Инициализация компонента.
     * Выполняет проверку существования модалки и навешивает события.
     * В отличие от редактируемых модалок, не кэширует элементы формы,
     * так как здесь нет полей ввода — только текстовые отображения.
     */
    init() {
        if ( this._initialized ) return;

        this.$modal = $( '#fs-application-view-modal' );
        if ( ! this.$modal.length ) return; // Защита: если модалки нет в DOM, прекращаем инициализацию

        this._initialized = true;
        this._bindEvents();
    },

    /**
     * Привязка обработчиков событий.
     * @private
     */
    _bindEvents() {
        // ДЕЛЕГИРОВАНИЕ СОБЫТИЙ С НЕЙМСПЕЙСИНГОМ:
        // Обработчик навешан на document, но срабатывает только для кнопок .js-view-application.
        // Неймспейс 'click.avm' позволяет точечно удалить этот обработчик через 
        // $(document).off('click.avm'), не затрагивая другие обработчики клика.
        $( document ).on( 'click.avm', '.js-view-application', ( e ) => {
            e.preventDefault();
            // Передаем jQuery-объект кнопки-триггера в метод open().
            // Это позволяет извлечь все data-атрибуты кнопки через .data().
            this.open( $( e.currentTarget ) );
        } );

        // Закрытие модалки при клике на фон, кнопку отмены или крестик
        this.$modal.on( 'click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .js-modal-close, .fs-close', ( e ) => {
            e.preventDefault();
            this.close();
        } );

        // Обработчик кликов по заголовкам аккордеона
        this.$modal.on( 'click', '.fs-modal-accordion__header', ( e ) => {
            e.preventDefault();
            this._toggleAccordion( $( e.currentTarget ) );
        } );
    },

    /**
     * Открытие модального окна с заполнением данными заявки.
     * @param {jQuery} $trigger - jQuery-объект кнопки, на которую нажали.
     *                           Содержит все данные заявки в data-атрибутах.
     */
    open( $trigger ) {
        // ЧТЕНИЕ ВСЕХ DATA-АТРИБУТОВ КНОПКИ:
        // Метод .data() без параметров возвращает объект со всеми data-* атрибутами элемента.
        // Например, если кнопка имеет data-s-last-name="Иванов" data-s-first-name="Иван",
        // то d будет равен { sLastName: "Иванов", sFirstName: "Иван" }.
        // jQuery автоматически преобразует kebab-case (s-last-name) в camelCase (sLastName).
        const d = $trigger.data();

        this._fill( d );
        bindEsc( 'app_view', () => this.close() );
        openModal( this.$modal );
    },

    /**
     * Закрытие модального окна и отвязка глобальных обработчиков.
     */
    close() {
        unbindEsc( 'app_view' );
        closeModal( this.$modal );
    },

    /**
     * Переключение состояния аккордеона (раскрытие/скрытие секции).
     * Реализует паттерн "только одна секция открыта одновременно".
     * Использует ARIA-атрибуты для обеспечения доступности (a11y).
     *
     * @private
     * @param {jQuery} $header - jQuery-объект заголовка секции, по которому кликнули.
     */
    _toggleAccordion( $header ) {
        // aria-controls содержит ID тела секции, которой управляет этот заголовок
        const $body  = $( '#' + $header.attr( 'aria-controls' ) );

        // aria-expanded="true" означает, что секция раскрыта
        const isOpen = $header.attr( 'aria-expanded' ) === 'true';

        // Сворачиваем все секции аккордеона
        this.$modal.find( '.fs-modal-accordion__header' ).attr( 'aria-expanded', 'false' );
        this.$modal.find( '.fs-modal-accordion__body' ).prop( 'hidden', true );

        // Если кликнули по закрытой секции — раскрываем её
        if ( ! isOpen ) {
            $header.attr( 'aria-expanded', 'true' );
            $body.prop( 'hidden', false );
        }
    },

    /**
     * Заполнение полей модалки данными заявки.
     * Реализует паттерн map-объекта для массового заполнения полей.
     *
     * @private
     * @param {Object} d - Объект с данными заявки (из data-атрибутов кнопки).
     */
    _fill( d ) {
        // Fallback-значение для пустых полей.
        // Символ '—' (тире) используется вместо пустой строки для улучшения UX:
        // пользователь видит "поле не заполнено", а не "здесь что-то сломалось".
        const empty = '—';

        // Утилитарная функция для применения fallback.
        // Если значение falsy (null, undefined, '', 0, false), возвращает '—'.
        const f = ( v ) => v || empty;

        // MAP-ОБЪЕКТ: Маппинг ключей полей на значения из данных.
        // Это ключевой паттерн для массового заполнения полей:
        // вместо 23 отдельных вызовов this.$modal.find(...).text(...),
        // мы создаем объект, где ключи — это data-атрибуты полей в HTML,
        // а значения — извлеченные данные из кнопки-триггера.
        // 
        // Имена полей в camelCase (sLastName, pFirstName), так как jQuery 
        // автоматически преобразует kebab-case из HTML в camelCase при чтении через .data().
        const map = {
            // ДАННЫЕ СТУДЕНТА (префикс s_)
            s_last_name:      d.sLastName,
            s_first_name:     d.sFirstName,
            s_middle_name:    d.sMiddleName,
            s_birth_date:     d.sBirthDate,
            s_email:          d.sEmail,
            s_phone:          d.sPhone,
            s_school:         d.sSchool,
            s_grade:          d.sGrade,
            s_doc_type:       d.sDocType,
            s_doc_number:     d.sDocNumber,
            s_inn:            d.sInn,

            // ДАННЫЕ РОДИТЕЛЯ (префикс p_)
            p_last_name:      d.pLastName,
            p_first_name:     d.pFirstName,
            p_middle_name:    d.pMiddleName,
            p_birth_date:     d.pBirthDate,
            p_email:          d.pEmail,
            p_phone:          d.pPhone,
            p_doc_type:       d.pDocType,
            p_doc_number:     d.pDocNumber,
            p_doc_issued_by:  d.pDocIssuedBy,
            p_doc_issued_date: d.pDocIssuedDate,
            p_inn:            d.pInn,
            p_address:        d.pAddress,
        };

        // ИТЕРАЦИЯ ПО MAP-ОБЪЕКТУ:
        // Object.entries(map) возвращает массив пар [ключ, значение] из объекта.
        // Например, { a: 1, b: 2 } → [['a', 1], ['b', 2]].
        // Деструктуризация [key, value] извлекает ключ и значение из каждой пары.
        // Это элегантный способ пройтись по всем полям и заполнить их без дублирования кода.
        Object.entries( map ).forEach( ( [ key, value ] ) => {
            // Находим элемент по data-атрибуту data-avm="ключ" и устанавливаем текст.
            // Функция f(value) применяет fallback на '—' для пустых значений.
            this.$modal.find( `[data-avm="${ key }"]` ).text( f( value ) );
        } );
    },
};