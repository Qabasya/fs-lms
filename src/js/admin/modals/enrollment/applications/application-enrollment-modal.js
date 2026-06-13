/**
 * @module ApplicationEnrollmentModal
 * @description UI-компонент модального окна зачисления студента по заявке.
 *              Отвечает за:
 *              - Отображение детальной информации о студенте и родителе (read-only поля)
 *              - Управление аккордеоном (accordion) для организации контента в секции
 *              - Заполнение каскадных dropdowns (период → предмет → группа)
 *              - Сбор и валидацию данных формы перед зачислением
 *              - Отслеживание состояния "завершено" для корректной обработки закрытия
 *              - Уведомление внешних менеджеров о событиях через паттерн Pub/Sub
 *
 * @requires jQuery
 * @requires openModal, closeModal, bindEsc, unbindEsc - базовые утилиты управления модальными окнами
 * @requires showModalError - утилита для отображения ошибок внутри модалки
 */

import { openModal, closeModal, bindEsc, unbindEsc } from '../../../modules/modal-base.js';
import { showModalError } from '../../../modules/utils.js';

const $ = jQuery;

/**
 * UI-компонент модального окна зачисления.
 * Работает в тесной связке с ApplicationEnrollmentModalManager,
 * который обрабатывает бизнес-логику (AJAX-запросы, откат статусов).
 */
export const ApplicationEnrollmentModal = {
    /** @type {jQuery|null} Ссылка на основной контейнер модального окна */
    $modal: null,

    /** @type {Function[]} Колбэки, вызываемые при отправке формы зачисления */
    _enrollCallbacks: [],

    /** @type {Function[]} Колбэки, вызываемые при закрытии модалки без завершения зачисления */
    _closeCallbacks: [],

    /**
     * Флаг состояния "зачисление успешно завершено".
     * Критически важен для логики отката статуса заявки:
     * если пользователь закрыл модалку БЕЗ успешного зачисления,
     * менеджер должен откатить статус заявки обратно в "ready_for_review".
     * Если зачисление прошло успешно, откат не нужен.
     * @private
     * @type {boolean}
     */
    _completed: false,

    /** @type {boolean} Флаг для предотвращения повторной инициализации */
    _initialized: false,

    /** @type {jQuery} Кэшированная ссылка на форму */
    $form: null,
    /** @type {jQuery} Кэшированная ссылка на кнопку "Зачислить" */
    $enrollBtn: null,

    /**
     * Инициализация компонента.
     * Выполняет проверку существования модалки, кэширует элементы и навешивает события.
     */
    init() {
        if ( this._initialized ) return;

        this.$modal = $( '#fs-application-enrollment-modal' );
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
        this.$form      = $( '#fs-application-enrollment-form' );
        this.$enrollBtn = $( '#enrollment-modal-enroll-btn' );
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
        // Делегирование событий внутри модалки для динамически существующих элементов.
        this.$modal.on( 'click', '.fs-modal-accordion__header', ( e ) => {
            e.preventDefault();
            this._toggleAccordion( $( e.currentTarget ) );
        } );

        // Перехват отправки формы с валидацией и уведомлением подписчиков
        this.$form.on( 'submit.fs', ( e ) => {
            e.preventDefault();
            if ( ! this._validateEnrollment() ) return;
            const data = this._collectEnrollmentData();
            this._enrollCallbacks.forEach( cb => cb( data ) );
        } );

        // Обработка клавиши Enter для отправки формы.
        // ВАЖНО: Исключаем textarea, select и input[type="date"],
        // так как в этих элементах Enter имеет стандартное поведение
        // (перенос строки в textarea, открытие списка в select и т.д.).
        // Без этой проверки пользователь не смог бы нормально работать с многострочными полями.
        this.$modal.on( 'keydown', ( e ) => {
            if ( e.key === 'Enter' && ! $( e.target ).is( 'textarea, select, input[type="date"]' ) ) {
                e.preventDefault();
                this.$form.trigger( 'submit' );
            }
        } );
    },

    /**
     * Открытие модального окна для конкретной заявки.
     * @param {string|number} appId - ID заявки.
     */
    open( appId ) {
        $( '#enrollment-modal-id' ).text( '#' + appId );
        this.$form.find( '[name="application_id"]' ).val( appId );

        // Сбрасываем флаг завершения — модалка открыта для нового процесса зачисления
        this._completed = false;

        this._resetDetailFields();
        this._resetAccordion();

        openModal( this.$modal );
        bindEsc( 'application_enrollment', () => this.close() );
    },

    /**
     * Закрытие модального окна.
     * Если зачисление НЕ было завершено успешно, уведомляет подписчиков
     * (менеджер использует это для отката статуса заявки).
     */
    close() {
        // КЛЮЧЕВАЯ ЛОГИКА: Если зачисление не завершено, значит пользователь отменил процесс.
        // Уведомляем подписчиков, чтобы менеджер мог откатить статус заявки на сервере.
        if ( ! this._completed ) {
            const appId = this.$form.find( '[name="application_id"]' ).val();
            if ( appId ) {
                this._closeCallbacks.forEach( cb => cb( { application_id: appId } ) );
            }
        }
        closeModal( this.$modal );
        unbindEsc( 'application_enrollment' );
    },

    /**
     * Пометка процесса зачисления как успешно завершенного.
     * Вызывается менеджером после успешного AJAX-запроса на зачисление.
     * Предотвращает срабатывание колбэков закрытия (откат статуса).
     */
    markCompleted() {
        this._completed = true;
    },

    /**
     * Регистрация колбэка, вызываемого при закрытии модалки без завершения зачисления.
     * @param {Function} callback - Функция, принимающая объект { application_id }.
     */
    onClose( callback ) {
        if ( typeof callback === 'function' ) { this._closeCallbacks.push( callback ); }
    },

    /**
     * Переключение состояния аккордеона (раскрытие/скрытие секции).
     * Реализует паттерн "только одна секция открыта одновременно".
     * Использует ARIA-атрибуты для обеспечения доступности (a11y).
     * @private
     * @param {jQuery} $header - jQuery-объект заголовка секции, по которому кликнули.
     */
    _toggleAccordion( $header ) {
        // Считываем текущее состояние из ARIA-атрибута.
        // aria-expanded="true" означает, что секция раскрыта.
        const isOpen = $header.attr( 'aria-expanded' ) === 'true';

        // aria-controls содержит ID тела секции, которой управляет этот заголовок.
        // Это стандарт WAI-ARIA для связи элементов управления с управляемым контентом.
        const bodyId = $header.attr( 'aria-controls' );

        // Сворачиваем все секции аккордеона
        this.$modal.find( '.fs-modal-accordion__header' ).attr( 'aria-expanded', 'false' );
        this.$modal.find( '.fs-modal-accordion__body' ).prop( 'hidden', true );

        // Если кликнули по закрытой секции — раскрываем её
        if ( ! isOpen ) {
            $header.attr( 'aria-expanded', 'true' );
            $( '#' + bodyId ).prop( 'hidden', false );
        }
    },

    /**
     * Регистрация колбэка, вызываемого при отправке формы зачисления.
     * @param {Function} callback - Функция, принимающая объект с данными формы.
     */
    onEnroll( callback ) {
        if ( typeof callback === 'function' ) { this._enrollCallbacks.push( callback ); }
    },

    /**
     * Заполнение полей формы данными студента.
     * @param {Object} student - Объект с данными студента.
     */
    populateStudentData( student ) {
        if ( ! student ) return;
        this._setField( 's_last_name',  student.last_name );
        this._setField( 's_first_name', student.first_name );
        this._setField( 's_middle_name', student.middle_name );
        this._setField( 's_birth_date', student.birth_date );
        this._setField( 's_email',      student.email );
        this._setField( 's_phone',      student.phone );
        this._setField( 's_school',     student.school );

        // Форматирование класса с суффиксом или прочерком, если данных нет
        this._setField( 's_grade',      student.grade ? student.grade + ' класс' : '—' );

        // ПАТТЕРН: Фильтрация пустых значений через filter(Boolean).
        // Массив создается из всех возможных частей документа,
        // затем filter(Boolean) удаляет все falsy-значения (null, undefined, '', 0, false).
        // Это позволяет собрать строку документа только из реально заполненных частей.
        this._setField( 's_doc',        [ student.doc_type, student.doc_number ].filter( Boolean ).join( ' ' ) );
        this._setField( 's_inn',        student.inn );
    },

    /**
     * Заполнение полей формы данными родителя.
     * @param {Object} parent - Объект с данными родителя.
     */
    populateParentData( parent ) {
        if ( ! parent ) return;
        this._setField( 'p_last_name',     parent.last_name );
        this._setField( 'p_first_name',    parent.first_name );
        this._setField( 'p_middle_name',   parent.middle_name );
        this._setField( 'p_birth_date',    parent.birth_date );
        this._setField( 'p_email',         parent.email );
        this._setField( 'p_phone',         parent.phone );

        // Сборка полной строки документа родителя из 4 возможных частей
        this._setField( 'p_doc',           [ parent.doc_type, parent.doc_number, parent.doc_issued_by, parent.doc_issued_date ].filter( Boolean ).join( ', ' ) );
        this._setField( 'p_inn',           parent.inn );

        // Оператор ?? (nullish coalescing) подставляет '—', если student_inn равен null или undefined
        this._setField( 's_inn_p',         parent.student_inn ?? '—' );
        this._setField( 'p_address',       parent.address );
    },

    /**
     * Заполнение dropdown периодов обучения.
     * @param {Array} periods - Массив объектов периодов { id, name }.
     * @param {string|number} currentId - ID текущего (активного) периода для预选 (pre-selection).
     */
    populatePeriods( periods, currentId ) {
        const $select = this.$modal.find( '[name="period_key"]' );
        $select.empty().append( '<option value="">— Выберите период —</option>' );
        periods.forEach( p => {
            // Тернарный оператор для установки атрибута selected у текущего периода
            const selected = p.id === currentId ? ' selected' : '';
            $select.append( `<option value="${ p.id }"${ selected }>${ p.name }</option>` );
        } );
    },

    /**
     * Заполнение dropdown предметов.
     * @param {Array} subjects - Массив объектов предметов { key, name }.
     */
    populateSubjects( subjects ) {
        const $select = this.$modal.find( '[name="subject_key"]' );
        $select.empty().append( '<option value="">— Выберите предмет —</option>' );
        subjects.forEach( s => {
            $select.append( `<option value="${ s.key }">${ s.name }</option>` );
        } );
    },

    /**
     * Заполнение dropdown групп (каскадный, зависит от периода и предмета).
     * Обрабатывает случай отсутствия доступных групп: отключает dropdown и показывает подсказку.
     * @param {Array} groups - Массив объектов групп { id, title }.
     */
    populateGroups( groups ) {
        const $select = this.$modal.find( '[name="group_id"]' );
        $select.empty();

        // Обработка edge case: если групп нет, отключаем dropdown,
        // чтобы пользователь не мог выбрать несуществующую группу
        if ( ! groups.length ) {
            $select.append( '<option value="">— Нет доступных групп —</option>' ).prop( 'disabled', true );
        } else {
            $select.append( '<option value="">— Выберите группу —</option>' );
            groups.forEach( g => {
                $select.append( `<option value="${ g.id }">${ g.title }</option>` );
            } );
            $select.prop( 'disabled', false );
        }
    },

    /**
     * Управление состоянием кнопки "Зачислить" (блокировка и изменение текста).
     * @param {boolean} loading - Флаг состояния загрузки.
     */
    setEnrollState( loading ) {
        this.$enrollBtn.prop( 'disabled', loading ).text( loading ? 'Зачисление...' : 'Зачислить' );
    },

    /**
     * Установка значения в поле формы по ключу data-атрибута.
     * @private
     * @param {string} key - Значение data-field атрибута.
     * @param {*} value - Значение для установки (приводится к строке или пустой строке).
     */
    _setField( key, value ) {
        this.$modal.find( `[data-field="${ key }"]` ).val( value || '' );
    },

    /**
     * Сброс всех полей с детальной информацией (read-only поля студента и родителя).
     * @private
     */
    _resetDetailFields() {
        this.$modal.find( '.fs-enr-field' ).val( '' );
    },

    /**
     * Сброс аккордеона к начальному состоянию: первая секция раскрыта, остальные скрыты.
     * @private
     */
    _resetAccordion() {
        this.$modal.find( '.fs-modal-accordion__header' ).first().attr( 'aria-expanded', 'true' );
        this.$modal.find( '.fs-modal-accordion__header' ).not( ':first' ).attr( 'aria-expanded', 'false' );
        this.$modal.find( '.fs-modal-accordion__body' ).first().prop( 'hidden', false );
        this.$modal.find( '.fs-modal-accordion__body' ).not( ':first' ).prop( 'hidden', true );
    },

    /**
     * Валидация формы перед зачислением.
     * Проверяет обязательные поля и автоматически раскрывает секцию с формой,
     * если она скрыта, чтобы пользователь сразу увидел проблему.
     * @private
     * @returns {boolean} true, если форма валидна; false, если есть ошибки.
     */
    _validateEnrollment() {
        const period  = this.$form.find( '[name="period_key"]' ).val();
        const subject = this.$form.find( '[name="subject_key"]' ).val();
        const group   = this.$form.find( '[name="group_id"]' ).val();

        if ( ! period || ! subject || ! group ) {
            // UX-ПАТТЕРН: Автоматическое раскрытие секции с ошибкой.
            // Если форма зачисления скрыта в аккордеоне, пользователь не увидит ошибку.
            // Находим заголовок секции и программно кликаем по нему, чтобы раскрыть секцию.
            const $header = this.$modal.find( '[aria-controls="enroll-acc-form"]' );
            if ( $header.attr( 'aria-expanded' ) !== 'true' ) {
                $header.trigger( 'click' );
            }
            showModalError( 'Выберите период, предмет и группу.', this.$modal );
            return false;
        }
        return true;
    },

    /**
     * Сбор данных из полей формы в единый объект для отправки на сервер.
     * @private
     * @returns {Object} Объект с подготовленными данными для AJAX-запроса.
     */
    _collectEnrollmentData() {
        const f          = this.$form;
        const order_date = f.find( '[name="order_date"]' ).val();
        return {
            application_id: f.find( '[name="application_id"]' ).val(),
            // Fallback на 'б/н' (без номера), если номер не указан.
            // Это стандартная практика для документов: если номер не присвоен, используется 'б/н'.
            contract_no:    f.find( '[name="contract_no"]' ).val() || 'б/н',
            contract_date:  f.find( '[name="contract_date"]' ).val(),
            order_no:       f.find( '[name="order_no"]' ).val() || 'б/н',
            order_date,
            // Дата зачисления совпадает с датой приказа — это бизнес-логика:
            // студент считается зачисленным с даты издания приказа о зачислении.
            enrolled_at:    order_date,
            period_key:     f.find( '[name="period_key"]' ).val(),
            subject_key:    f.find( '[name="subject_key"]' ).val(),
            group_id:       f.find( '[name="group_id"]' ).val(),
        };
    },
};