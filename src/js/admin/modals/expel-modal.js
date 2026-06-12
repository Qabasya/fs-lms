/**
 * @module ExpelModal
 * @description UI-компонент модального окна отчисления студентов.
 *              Поддерживает два режима работы:
 *              1. Одиночное отчисление — отчисление одного студента с выбором конкретного зачисления
 *              2. Массовое отчисление (bulk) — одновременное отчисление нескольких студентов
 *
 *              Отвечает за:
 *              - Переключение между режимами одиночного и массового отчисления
 *              - Динамическое отображение списка зачислений (скрытие при одном зачислении)
 *              - Обработку выбора причины отчисления с опцией "другое" (кастомный текст)
 *              - Сохранение и восстановление оригинальных текстов модалки при смене режимов
 *              - Сбор данных формы с учетом текущего режима (single/bulk)
 *              - Уведомление внешних менеджеров через паттерн Pub/Sub
 *
 * @requires jQuery
 * @requires openModal, closeModal, bindEsc, unbindEsc - базовые утилиты управления модальными окнами
 */

import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

/**
 * UI-компонент модального окна отчисления студентов.
 * Работает в связке с ExpelModalManager, который обрабатывает AJAX-запросы.
 */
export const ExpelModal = {
    /** @type {jQuery|null} Ссылка на основной контейнер модального окна */
    $modal:           null,

    /** @type {Function[]} Массив колбэков, вызываемых при подтверждении отчисления */
    _confirmCbs:      [],

    /** @type {boolean} Флаг для предотвращения повторной инициализации */
    _initialized:     false,

    /**
     * Массив студентов для массового отчисления.
     * Если null — режим одиночного отчисления.
     * @private
     * @type {Array|null}
     */
    _bulkStudents:    null,

    /**
     * Колбэк, вызываемый после успешного массового отчисления.
     * Используется менеджером для обновления таблицы или счетчиков.
     * @private
     * @type {Function|null}
     */
    _afterExpel:      null,

    /**
     * Колбэк, вызываемый после закрытия модалки.
     * Может быть установлен извне через setAfterClose().
     * @private
     * @type {Function|null}
     */
    _afterClose:      null,

    /**
     * Оригинальный заголовок модалки (сохраняется при инициализации).
     * Используется для восстановления после массового отчисления.
     * @private
     * @type {string}
     */
    _originalTitle:   '',

    /**
     * Оригинальный текст предупреждения (сохраняется при инициализации).
     * Используется для восстановления после массового отчисления.
     * @private
     * @type {string}
     */
    _originalWarning: '',

    /**
     * Инициализация компонента.
     * Выполняет проверку существования модалки, сохраняет оригинальные тексты
     * и навешивает события.
     */
    init() {
        if ( this._initialized ) return;

        this.$modal = $( '#fs-expel-modal' );
        if ( ! this.$modal.length ) return; // Защита: если модалки нет в DOM, прекращаем инициализацию

        this._initialized = true;

        // СОХРАНЕНИЕ ОРИГИНАЛЬНЫХ ТЕКСТОВ:
        // Критически важно для режима массового отчисления.
        // При открытии bulk-режима мы меняем заголовок и предупреждение на специфичные.
        // При закрытии нужно восстановить оригинальные значения, чтобы при следующем 
        // открытии одиночного режима модалка выглядела правильно.
        this._originalTitle   = this.$modal.find( '.fs-lms-modal-title' ).text();
        this._originalWarning = this.$modal.find( '.fs-expel-warning' ).html();

        this._bindReasonEvents();
        this._bindEvents();
    },

    /**
     * Привязка обработчиков событий.
     * @private
     */
    _bindEvents() {
        // Закрытие модалки при клике на фон, кнопку отмены или крестик
        this.$modal.on(
            'click',
            '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .js-modal-close, .fs-close',
            ( e ) => { e.preventDefault(); this.close(); }
        );

        // Перехват отправки формы с уведомлением подписчиков
        $( '#fs-expel-form' ).on( 'submit.fs', ( e ) => {
            e.preventDefault();
            // Собираем данные формы и передаем их всем подписчикам (менеджерам)
            this._confirmCbs.forEach( cb => cb( this._collectFormData() ) );
        } );
    },

    /**
     * Регистрация колбэка, вызываемого при подтверждении отчисления.
     * Реализует паттерн Pub/Sub (Издатель-Подписчик).
     * @param {Function} callback - Функция, принимающая объект с данными формы.
     */
    onConfirm( callback ) {
        if ( typeof callback === 'function' ) this._confirmCbs.push( callback );
    },

    /**
     * Открытие модального окна для одиночного отчисления студента.
     *
     * @param {string|number} studentId - ID студента.
     * @param {string} studentName - Имя студента для отображения.
     * @param {Array<{record_id: number, subject_name: string, group_title: string}>} [enrollments=[]]
     *        Массив зачислений студента. Если одно — select скрыт, если несколько — показан.
     */
    open( studentId, studentName, enrollments = [] ) {
        if ( ! this._initialized ) return;

        // Сбрасываем флаг bulk-режима — переходим в одиночный режим
        this._bulkStudents = null;

        // Устанавливаем ID студента в скрытое поле формы
        this.$modal.find( 'input[name="student_id"]' ).val( studentId );

        // Отображаем имя студента с префиксом
        this.$modal.find( '.fs-expel-student-name' ).text( studentName ? `Ученик: ${ studentName }` : '' );

        // Настраиваем select зачислений (скрывается, если зачисление одно)
        this.setEnrollments( enrollments );

        // Сбрасываем поля формы, но НЕ очищаем select зачислений (clearGroup = false),
        // так как он уже заполнен методом setEnrollments()
        this._resetFormFields( false );
        this._setSaving( false );

        openModal( this.$modal );
        bindEsc( 'expel', () => this.close() );
    },

    /**
     * Открытие модального окна для массового отчисления нескольких студентов.
     * Изменяет заголовок, предупреждение и отображает список студентов.
     *
     * @param {Array<{id: string|number, name: string}>} students - Массив студентов для отчисления.
     * @param {Object} [options={}] - Дополнительные опции.
     * @param {Function} [options.afterExpel] - Колбэк, вызываемый после успешного отчисления.
     */
    openBulk( students, options = {} ) {
        if ( ! this._initialized ) return;

        // Устанавливаем флаг bulk-режима и сохраняем колбэк afterExpel
        this._bulkStudents = students;
        this._afterExpel   = options.afterExpel || null;

        // ИЗМЕНЕНИЕ UI ДЛЯ BULK-РЕЖИМА:
        // Меняем заголовок на специфичный для массового отчисления
        this.$modal.find( '.fs-lms-modal-title' ).text( 'Массовое отчисление' );

        // Меняем предупреждение на более строгое, с иконкой предупреждения
        this.$modal.find( '.fs-expel-warning' ).html(
            '<span class="dashicons dashicons-warning"></span>' +
            ' Будут удалены профили учеников и родителей. Данные сохранятся в архиве.'
        );

        // Отображение списка студентов
        const $nameEl = this.$modal.find( '.fs-expel-student-name' );
        $nameEl.empty();

        if ( students.length ) {
            // Создаем маркированный список студентов динамически
            const $list = $( '<ul class="fs-expel-bulk-list"></ul>' );
            students.forEach( s => {
                // Используем .text() для безопасности (защита от XSS)
                $( '<li></li>' ).text( s.name || `#${ s.id }` ).appendTo( $list );
            } );
            $nameEl.append( $( '<span>Ученики:</span>' ) ).append( $list );
        }

        // В режиме bulk скрываем select зачислений, так как отчисляются все зачисления сразу
        this.$modal.find( '#fs-expel-group-wrap' ).prop( 'hidden', true );

        // Очищаем ID студента (в bulk-режиме он не нужен)
        this.$modal.find( 'input[name="student_id"]' ).val( '' );

        this._resetFormFields( false );
        this._setSaving( false );

        openModal( this.$modal );
        bindEsc( 'expel', () => this.close() );
    },

    /**
     * Заполняет select для выбора группы (зачисления) при отчислении.
     * Реализует адаптивное поведение:
     * - Если зачислений 0 — select скрыт
     * - Если зачисление 1 — select скрыт, значение проставляется автоматически
     * - Если зачислений >1 — select показан, пользователь выбирает вручную
     *
     * @param {Array<{record_id: number, subject_name: string, group_title: string}>} enrollments
     *        Массив зачислений студента.
     */
    setEnrollments( enrollments ) {
        const $wrap   = this.$modal.find( '#fs-expel-group-wrap' );
        const $select = this.$modal.find( '#expel-record' );

        // Очищаем все option, кроме первого (пустого placeholder)
        $select.find( 'option:not(:first)' ).remove();
        $select.val( '' );

        if ( enrollments.length === 1 ) {
            // ОДНО ЗАЧИСЛЕНИЕ: Автоматически выбираем его и скрываем select.
            // Пользователю не нужно выбирать, если вариант только один.
            const e = enrollments[0];
            $select.append(
                $( '<option>' ).val( e.record_id ).text( `${ e.subject_name } — ${ e.group_title }` ).prop( 'selected', true )
            );
            $wrap.prop( 'hidden', true );
        } else if ( enrollments.length > 1 ) {
            // НЕСКОЛЬКО ЗАЧИСЛЕНИЙ: Показываем select и заполняем его всеми вариантами.
            // Пользователь должен выбрать, какое именно зачисление прекратить.
            enrollments.forEach( e => {
                $select.append(
                    $( '<option>' ).val( e.record_id ).text( `${ e.subject_name } — ${ e.group_title }` )
                );
            } );
            $wrap.prop( 'hidden', false );
        } else {
            // НОЛЬ ЗАЧИСЛЕНИЙ: Скрываем select (нечего выбирать).
            // Это edge case: студент может быть уже отчислен или данные повреждены.
            $wrap.prop( 'hidden', true );
        }
    },

    /**
     * Установка колбэка, вызываемого после закрытия модалки.
     * Может быть использован менеджером для выполнения действий после завершения процесса.
     * @param {Function} cb - Функция-колбэк.
     */
    setAfterClose( cb ) {
        this._afterClose = typeof cb === 'function' ? cb : null;
    },

    /**
     * Закрытие модального окна с полной очисткой состояния.
     * Восстанавливает оригинальные тексты, если был в bulk-режиме.
     */
    close() {
        // closeModal принимает колбэк, который вызывается после завершения анимации закрытия.
        // Это гарантирует, что очистка состояния произойдет после визуального исчезновения модалки.
        closeModal( this.$modal, () => {
            // Сохраняем ссылку на колбэк и сразу очищаем свойство,
            // чтобы предотвратить повторный вызов при следующих открытиях
            const cb = this._afterClose;
            this._afterClose = null;

            // Восстанавливаем оригинальные тексты, если были в bulk-режиме
            this._restoreDefaults();

            // Полностью сбрасываем все поля формы, включая select зачислений (clearGroup = true)
            this._resetFormFields( true );

            // Вызываем колбэк afterClose, если он был установлен
            if ( cb ) cb();
        } );

        // Обязательно отвязываем обработчик ESC
        unbindEsc( 'expel' );
    },

    /**
     * Публичный метод для управления состоянием кнопки подтверждения.
     * @param {boolean} loading - Флаг состояния загрузки.
     */
    setSaving( loading ) {
        this._setSaving( loading );
    },

    /**
     * Внутренний метод управления состоянием кнопки подтверждения.
     * @private
     * @param {boolean} loading - Флаг состояния загрузки.
     */
    _setSaving( loading ) {
        this.$modal.find( '.js-expel-confirm' )
            .prop( 'disabled', loading )
            .text( loading ? 'Отчисление...' : 'Отчислить' );
    },

    /**
     * Сбор данных из полей формы в единый объект.
     * Учитывает текущий режим (single/bulk) и формирует соответствующий payload.
     *
     * @private
     * @returns {Object} Объект с подготовленными данными для отправки на сервер.
     */
    _collectFormData() {
        const $select      = this.$modal.find( '#expel-reason' );

        // Читаем специальное значение для опции "другое" из data-атрибута.
        // Это позволяет динамически определять, какая опция является "другой",
        // без хардкода значения в JavaScript.
        const otherValue   = $select.data( 'other-value' );
        const reason       = $select.val();
        const customReason = this.$modal.find( '#expel-custom-reason' ).val().trim();

        // Формируем итоговую причину отчисления.
        // Если выбрана опция "другое", добавляем кастомный текст через двоеточие.
        const finalReason = reason === otherValue
            ? `${ otherValue }: ${ customReason }`
            : reason;

        // Получаем ID выбранного зачисления (может быть null, если в bulk-режиме)
        const recordId = this.$modal.find( '#expel-record' ).val() || null;

        return {
            // В одиночном режиме передаем student_id, в bulk — null
            student_id:    this._bulkStudents === null
                ? this.$modal.find( 'input[name="student_id"]' ).val()
                : null,

            // В bulk-режиме передаем массив ID студентов, в одиночном — null
            student_ids:   this._bulkStudents
                ? this._bulkStudents.map( s => s.id )
                : null,

            reason:         finalReason,

            // record_id передается только в одиночном режиме
            record_id:      this._bulkStudents === null ? recordId : null,

            // Флаг валидации: если выбрана "другое", но кастомный текст пустой
            is_other_empty: reason === otherValue && ! customReason,

            // Передаем колбэк afterExpel, чтобы менеджер мог вызвать его после успеха
            afterExpel:     this._afterExpel,
        };
    },

    /**
     * Восстановление оригинальных текстов модалки после bulk-режима.
     * Вызывается при закрытии, если был активен bulk-режим.
     * @private
     */
    _restoreDefaults() {
        // Если не в bulk-режиме, ничего не делаем
        if ( this._bulkStudents === null ) return;

        // Восстанавливаем оригинальные тексты, сохраненные при инициализации
        this.$modal.find( '.fs-lms-modal-title' ).text( this._originalTitle );
        this.$modal.find( '.fs-expel-warning' ).html( this._originalWarning );

        // Очищаем список студентов
        this.$modal.find( '.fs-expel-student-name' ).empty();

        // Сбрасываем флаги bulk-режима
        this._bulkStudents = null;
        this._afterExpel   = null;
    },

    /**
     * Привязка обработчиков для select причины отчисления.
     * Управляет видимостью поля для ввода кастомной причины.
     * @private
     */
    _bindReasonEvents() {
        this.$modal.find( '#expel-reason' ).on( 'change', ( e ) => {
            // Читаем значение опции "другое" из data-атрибута
            const otherValue = $( e.target ).data( 'other-value' );
            const isOther = e.target.value === otherValue;

            // Показываем или скрываем поле для кастомной причины
            this.$modal.find( '#fs-expel-custom-reason-wrap' ).prop( 'hidden', ! isOther );
        } );
    },

    /**
     * Сброс всех полей формы к исходному состоянию.
     *
     * @private
     * @param {boolean} clearGroup - Флаг, нужно ли очищать select зачислений.
     *                               false — при открытии (select заполняется отдельно),
     *                               true — при закрытии (полный сброс).
     */
    _resetFormFields( clearGroup = true ) {
        // Сброс причины отчисления
        this.$modal.find( '#expel-reason' ).val( '' );

        // Сброс кастомной причины
        this.$modal.find( '#expel-custom-reason' ).val( '' );

        // Скрытие поля кастомной причины
        this.$modal.find( '#fs-expel-custom-reason-wrap' ).prop( 'hidden', true );

        // Условная очистка select зачислений
        if ( clearGroup ) {
            // Удаляем все option, кроме первого, и сбрасываем значение
            this.$modal.find( '#expel-record' ).find( 'option:not(:first)' ).remove().end().val( '' );
            // Скрываем обертку select
            this.$modal.find( '#fs-expel-group-wrap' ).prop( 'hidden', true );
        }
    },
};