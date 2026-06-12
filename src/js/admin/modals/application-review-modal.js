/**
 * @module ApplicationReviewModal
 * @description UI-компонент модального окна проверки заявки.
 *              Отвечает за:
 *              - Отображение детальной информации о студенте и родителе с возможностью inline-редактирования
 *              - Управление аккордеоном для организации контента в логические секции
 *              - Переключение отдельных полей между режимами просмотра и редактирования
 *              - Сбор данных формы и уведомление внешних менеджеров через паттерн Pub/Sub
 *              - Управление жизненным циклом модалки (открытие, закрытие, блокировка кнопки)
 *
 * @requires jQuery
 * @requires openModal, closeModal, bindEsc, unbindEsc - базовые утилиты управления модальными окнами
 */

import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

/**
 * UI-компонент модального окна проверки заявки.
 * Работает в связке с ApplicationReviewModalManager, который обрабатывает AJAX-запросы.
 * В отличие от ApplicationModal, содержит больше полей (данные студента + родителя)
 * и использует аккордеон для организации контента.
 */
export const ApplicationReviewModal = {
    /** @type {jQuery|null} Ссылка на основной контейнер модального окна */
    $modal: null,

    /** @type {Function[]} Массив колбэков, вызываемых при отправке формы */
    _saveCallbacks: [],

    /** @type {boolean} Флаг для предотвращения повторной инициализации */
    _initialized: false,

    /** @type {jQuery} Кэшированная ссылка на форму */
    $form: null,
    /** @type {jQuery} Кэшированная ссылка на кнопку сохранения */
    $saveBtn: null,

    /**
     * Инициализация компонента.
     * Выполняет проверку существования модалки, кэширует элементы и навешивает события.
     */
    init() {
        if ( this._initialized ) return;

        this.$modal = $( '#fs-application-review-modal' );
        if ( ! this.$modal.length ) return; // Защита: если модалки нет в DOM, прекращаем инициализацию

        this._initialized = true;
        this._cacheElements();
        this._bindEvents();
    },

    /**
     * Кэширование DOM-элементов для оптимизации производительности.
     * @private
     */
    _cacheElements() {
        this.$form    = this.$modal.find( 'form' );
        this.$saveBtn = $( '#review-modal-save-btn' );
    },

    /**
     * Привязка обработчиков событий.
     * @private
     */
    _bindEvents() {
        // Закрытие модалки при клике на фон, кнопку отмены или крестик
        this.$modal.on( 'click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .js-modal-close, .fs-close', ( e ) => {
            e.preventDefault();
            this.close();
        } );

        // Обработчик кликов по заголовкам аккордеона.
        // Используется делегирование событий внутри модалки для работы с динамическими элементами.
        this.$modal.on( 'click', '.fs-modal-accordion__header', ( e ) => {
            e.preventDefault();
            this._toggleAccordion( $( e.currentTarget ) );
        } );

        // Обработчик клика по кнопке редактирования отдельного поля.
        // Делегирование событий для работы с динамическими элементами.
        this.$modal.on( 'click', '.fs-editable-field__btn', ( e ) => {
            e.preventDefault();
            this._toggleField( $( e.currentTarget ).closest( '.fs-editable-field' ) );
        } );

        // Перехват отправки формы с уведомлением подписчиков
        this.$form.on( 'submit.fs', ( e ) => {
            e.preventDefault();
            const data = this._collectFormData();
            this._saveCallbacks.forEach( cb => cb( data ) );
        } );
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
        // Считываем текущее состояние из ARIA-атрибута.
        // aria-expanded="true" означает, что секция раскрыта.
        const isOpen     = $header.attr( 'aria-expanded' ) === 'true';

        // aria-controls содержит ID тела секции, которой управляет этот заголовок.
        // Это стандарт WAI-ARIA для связи элементов управления с управляемым контентом.
        const bodyId     = $header.attr( 'aria-controls' );
        const $body      = $( '#' + bodyId );

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
     * Переключение отдельного поля между режимами "просмотр" и "редактирование".
     * Реализует паттерн inline-editing: пользователь редактирует только те поля,
     * которые действительно нужно изменить, не видя всю форму в режиме ввода.
     *
     * @private
     * @param {jQuery} $field - jQuery-объект контейнера редактируемого поля.
     */
    _toggleField( $field ) {
        const $display  = $field.find( '.fs-editable-field__display' ); // Span с текстовым отображением значения
        const $input    = $field.find( 'input, select' );              // Само поле ввода
        const $icon     = $field.find( '.dashicons' );                 // Иконка кнопки (карандаш/стрелка назад)

        // Определяем текущее состояние: если input скрыт, значит мы в режиме просмотра
        const isEditing = ! $input[0].hidden;

        if ( isEditing ) {
            // ВОЗВРАТ В РЕЖИМ ПРОСМОТРА:
            // 1. Показываем текстовое отображение значения
            $display[0].hidden = false;
            // 2. Скрываем поле ввода
            $input[0].hidden   = true;
            // 3. Синхронизируем отображаемый текст с введенным значением.
            //    Для select берем текст выбранной опции, для input — значение.
            //    Fallback на '—', если поле пустое, чтобы интерфейс не выглядел "сломанным".
            $display.text( $input.is( 'select' ) ? ( $input.find( 'option:selected' ).text() || '—' ) : ( $input.val() || '—' ) );
            // 4. Меняем иконку с "отмены" (undo) обратно на "редактирование" (карандаш)
            $icon.removeClass( 'dashicons-undo' ).addClass( 'dashicons-edit' );
        } else {
            // ПЕРЕХОД В РЕЖИМ РЕДАКТИРОВАНИЯ:
            // 1. Скрываем текстовое отображение
            $display[0].hidden = true;
            // 2. Показываем поле ввода
            $input[0].hidden   = false;
            // 3. Устанавливаем фокус на поле для удобства пользователя
            $input.trigger( 'focus' );
            // 4. Меняем иконку на "отмену", чтобы пользователь мог вернуть значение обратно
            $icon.removeClass( 'dashicons-edit' ).addClass( 'dashicons-undo' );
        }
    },

    /**
     * Регистрация колбэка, вызываемого при отправке формы.
     * Реализует паттерн Pub/Sub (Издатель-Подписчик).
     * @param {Function} callback - Функция, принимающая объект с данными формы.
     */
    onSave( callback ) {
        if ( typeof callback === 'function' ) {
            this._saveCallbacks.push( callback );
        }
    },

    /**
     * Открытие модального окна с заполнением данными заявки.
     * Все поля открываются в режиме "только чтение", редактирование доступно по клику на иконку.
     * Аккордеон сбрасывается к начальному состоянию: первая секция раскрыта, остальные скрыты.
     * @param {Object} data - Объект с данными заявки (студент + родитель).
     */
    open( data ) {
        // Обновляем заголовок с номером заявки и скрытое поле с ID
        $( '#review-modal-id' ).text( '#' + data.id );
        this.$form.find( '[name="application_id"]' ).val( data.id );

        // Массив имен полей для итерации.
        // Поля разделены на две логические группы: данные студента (student_*) и данные родителя (parent_*).
        // Это реализация DRY-принципа: вместо дублирования кода для каждого из 19 полей,
        // мы описываем логику один раз и применяем её ко всем полям через цикл.
        const fields = [
            // Данные студента
            'student_last_name', 'student_first_name', 'student_middle_name',
            'student_birth_date', 'student_doc_type', 'student_doc_number', 'student_inn',
            // Данные родителя
            'parent_last_name', 'parent_first_name', 'parent_middle_name',
            'parent_birth_date',
            'parent_email', 'parent_phone',
            'parent_doc_type', 'parent_doc_number', 'parent_doc_issued_by', 'parent_doc_issued_date',
            'parent_inn', 'parent_address',
        ];

        fields.forEach( field => {
            const $field   = this.$modal.find( `.fs-editable-field[data-field="${ field }"]` );
            const value    = data[ field ] ?? ''; // Оператор ?? подставляет пустую строку, если значение null/undefined
            const $display = $field.find( '.fs-editable-field__display' );
            const $input   = $field.find( 'input, select' );

            // Устанавливаем значение в поле ввода (приводим к строке для безопасности типов)
            $input.val( String( value ) );

            // Синхронизируем текстовое отображение.
            // Для select важно взять текст выбранной опции, а не её value,
            // так как value — это ключ (например, "passport"), а пользователю нужно "Паспорт".
            $display.text( $input.is( 'select' ) ? ( $input.find( 'option:selected' ).text() || '—' ) : ( value || '—' ) );

            // Устанавливаем начальное состояние: режим просмотра
            $display[0].hidden = false;
            $input[0].hidden   = true;
            $field.find( '.dashicons' ).removeClass( 'dashicons-undo' ).addClass( 'dashicons-edit' );
        } );

        // Сброс аккордеона к начальному состоянию: первая секция раскрыта, остальные скрыты.
        // Это гарантирует предсказуемое поведение при повторном открытии модалки.
        this.$modal.find( '.fs-modal-accordion__header' ).first().attr( 'aria-expanded', 'true' );
        this.$modal.find( '.fs-modal-accordion__header' ).not( ':first' ).attr( 'aria-expanded', 'false' );
        this.$modal.find( '.fs-modal-accordion__body' ).first().prop( 'hidden', false );
        this.$modal.find( '.fs-modal-accordion__body' ).not( ':first' ).prop( 'hidden', true );

        openModal( this.$modal );
        bindEsc( 'application_review', () => this.close() );
    },

    /**
     * Закрытие модального окна и отвязка глобальных обработчиков.
     */
    close() {
        closeModal( this.$modal );
        unbindEsc( 'application_review' );
    },

    /**
     * Управление состоянием кнопки сохранения (блокировка и изменение текста).
     * @param {boolean} loading - Флаг состояния загрузки.
     */
    setSaveState( loading ) {
        this.$saveBtn
            .prop( 'disabled', loading )
            .text( loading ? 'Сохранение...' : 'Сохранить' );
    },

    /**
     * Сбор данных из всех полей формы в единый объект.
     * @private
     * @returns {Object} Объект с подготовленными данными для отправки на сервер.
     */
    _collectFormData() {
        const data = { application_id: this.$form.find( '[name="application_id"]' ).val() };

        // Используем тот же массив полей, что и в open(), для консистентности.
        // Это гарантирует, что все поля будут собраны и отправлены на сервер.
        const fields = [
            'student_last_name', 'student_first_name', 'student_middle_name',
            'student_birth_date', 'student_doc_type', 'student_doc_number', 'student_inn',
            'parent_last_name', 'parent_first_name', 'parent_middle_name',
            'parent_birth_date',
            'parent_email', 'parent_phone',
            'parent_doc_type', 'parent_doc_number', 'parent_doc_issued_by', 'parent_doc_issued_date',
            'parent_inn', 'parent_address',
        ];

        fields.forEach( field => {
            // Оператор ?? '' гарантирует, что в объект всегда попадет строка,
            // даже если поле не найдено в DOM (защита от undefined).
            data[ field ] = this.$form.find( `[name="${ field }"]` ).val() ?? '';
        } );

        return data;
    },
};