/**
 * @module ApplicationModal
 * @description UI-компонент модального окна редактирования заявки.
 *              Отвечает за:
 *              - Отображение данных заявки в режиме "только чтение" с возможностью inline-редактирования
 *              - Переключение отдельных полей между режимами просмотра и редактирования
 *              - Сбор данных формы и уведомление внешних менеджеров через паттерн Pub/Sub
 *              - Управление жизненным циклом модалки (открытие, закрытие, блокировка кнопки)
 *
 * @requires jQuery
 * @requires openModal, closeModal, bindEsc, unbindEsc - базовые утилиты управления модальными окнами
 */

import { openModal, closeModal, bindEsc, unbindEsc } from '../../../modules/modal-base.js';

const $ = jQuery;

/**
 * UI-компонент модального окна редактирования заявки.
 * Работает в связке с ApplicationModalManager, который обрабатывает AJAX-запросы.
 */
export const ApplicationModal = {
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

        this.$modal = $( '#fs-application-modal' );
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
        this.$saveBtn = $( '#app-modal-save-btn' );
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

        // Обработчик клика по кнопке редактирования отдельного поля.
        // Используется делегирование событий внутри модалки для работы с динамическими элементами.
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
     * Переключение отдельного поля между режимами "просмотр" и "редактирование".
     * Реализует паттерн inline-editing: пользователь редактирует только те поля,
     * которые действительно нужно изменить, не видя всю форму в режиме ввода.
     *
     * @private
     * @param {jQuery} $field - jQuery-объект контейнера редактируемого поля.
     */
    _toggleField( $field ) {
        const $display = $field.find( '.fs-editable-field__display' ); // Span с текстовым отображением значения
        const $input   = $field.find( 'input, select' );              // Само поле ввода
        const $icon    = $field.find( '.dashicons' );                 // Иконка кнопки (карандаш/стрелка назад)

        // Определяем текущее состояние: если input скрыт, значит мы в режиме просмотра
        const isEditing = ! $input[0].hidden;

        if ( isEditing ) {
            // ВОЗВРАТ В РЕЖИМ ПРОСМОТРА:
            // 1. Показываем текстовое отображение значения
            $display[0].hidden = false;
            // 2. Скрываем поле ввода
            $input[0].hidden = true;
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
            $input[0].hidden = false;
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
     * @param {Object} data - Объект с данными заявки.
     */
    open( data ) {
        // Обновляем заголовок с номером заявки и скрытое поле с ID
        $( '#app-modal-id' ).text( '#' + data.id );
        this.$form.find( '[name="application_id"]' ).val( data.id );

        // Массив имен полей для итерации.
        // Это реализация DRY-принципа: вместо дублирования кода для каждого из 8 полей,
        // мы описываем логику один раз и применяем её ко всем полям через цикл.
        const fields = [ 'last_name', 'first_name', 'middle_name', 'birth_date', 'email', 'phone', 'school', 'grade' ];

        fields.forEach( field => {
            const $field   = this.$modal.find( `.fs-editable-field[data-field="${ field }"]` );
            const value    = data[ field ] ?? ''; // Оператор ?? подставляет пустую строку, если значение null/undefined
            const $display = $field.find( '.fs-editable-field__display' );
            const $input   = $field.find( 'input, select' );

            // Устанавливаем значение в поле ввода (приводим к строке для безопасности типов)
            $input.val( String( value ) );

            // Синхронизируем текстовое отображение.
            // Для select важно взять текст выбранной опции, а не её value,
            // так как value — это ключ (например, "5"), а пользователю нужно "5 класс".
            $display.text( $input.is( 'select' ) ? ( $input.find( 'option:selected' ).text() || '—' ) : ( value || '—' ) );

            // Устанавливаем начальное состояние: режим просмотра
            $display[0].hidden = false;
            $input[0].hidden = true;
            $field.find( '.dashicons' ).removeClass( 'dashicons-undo' ).addClass( 'dashicons-edit' );
        } );

        openModal( this.$modal );
        bindEsc( 'application', () => this.close() );
    },

    /**
     * Закрытие модального окна и отвязка глобальных обработчиков.
     */
    close() {
        closeModal( this.$modal );
        unbindEsc( 'application' );
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
        // Оператор ?? '' гарантирует, что в объект всегда попадет строка,
        // даже если поле не найдено в DOM (защита от undefined).
        const fields = [ 'last_name', 'first_name', 'middle_name', 'birth_date', 'email', 'phone', 'school', 'grade' ];
        fields.forEach( field => {
            data[ field ] = this.$form.find( `[name="${ field }"]` ).val() ?? '';
        } );

        return data;
    },
};