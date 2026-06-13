/**
 * @module ArchiveViewModal
 * @description UI-компонент модального окна просмотра архивных записей о зачислении.
 *              Отвечает за:
 *              - Отображение детальной информации о студенте, родителе и договоре в режиме "только чтение"
 *              - Управление аккордеоном для организации контента в логические секции
 *              - Парсинг и нормализацию данных с fallback на составное имя (full_name)
 *              - Заполнение полей через map-объект и итерацию Object.entries
 *
 *              В отличие от ApplicationReviewModal, это окно НЕ поддерживает редактирование.
 *              Оно используется для просмотра исторических данных после отчисления студента.
 *
 * @requires jQuery
 * @requires openModal, closeModal, bindEsc, unbindEsc - базовые утилиты управления модальными окнами
 */

import { openModal, closeModal, bindEsc, unbindEsc } from '../../modules/modal-base.js';

const $ = jQuery;

/**
 * UI-компонент модального окна просмотра архива.
 * Работает в связке с ArchiveViewModalManager, который обрабатывает восстановление из архива.
 * Эта модалка полностью read-only: пользователь может только просматривать данные,
 * но не редактировать их. Для редактирования используются другие модалки.
 */
export const ArchiveViewModal = {
    /** @type {jQuery|null} Ссылка на основной контейнер модального окна */
    $modal:       null,

    /** @type {boolean} Флаг для предотвращения повторной инициализации */
    _initialized: false,

    /**
     * Инициализация компонента.
     * Выполняет проверку существования модалки и навешивает события.
     * В отличие от других модалок, не кэширует элементы формы,
     * так как здесь нет полей ввода — только текстовые отображения.
     */
    init() {
        if ( this._initialized ) { return; }

        this.$modal = $( '#fs-archive-view-modal' );
        if ( ! this.$modal.length ) { return; } // Защита: если модалки нет в DOM, прекращаем инициализацию

        this._initialized = true;
        this._bindEvents();
    },

    /**
     * Привязка обработчиков событий.
     * @private
     */
    _bindEvents() {
        // ДЕЛЕГИРОВАНИЕ СОБЫТИЙ ДЛЯ ОТКРЫТИЯ МОДАЛКИ:
        // Обработчик навешан на document, но срабатывает только для кнопок .js-view-archive.
        // Это необходимо, так как таблица архивных записей может обновляться динамически 
        // (например, после восстановления или удаления записей).
        $( document ).on( 'click.arc', '.js-view-archive', ( e ) => {
            e.preventDefault();

            // ЧТЕНИЕ ДАННЫХ ИЗ СТРОКИ ТАБЛИЦЫ:
            // Вместо того чтобы передавать каждое поле через отдельные data-атрибуты кнопки,
            // мы храним весь объект данных в data-enrollment строки таблицы.
            // Это упрощает HTML-разметку и позволяет передавать сложные структуры данных.
            // jQuery .data() автоматически парсит JSON из data-атрибута в JavaScript объект.
            const raw = $( e.currentTarget ).closest( 'tr' ).data( 'enrollment' );
            if ( raw ) { this.open( raw ); }
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
     * Открытие модального окна с заполнением данными архивной записи.
     * @param {Object} data - Объект с данными архивной записи (студент, родитель, договор и т.д.).
     */
    open( data ) {
        this._fill( data );
        bindEsc( 'archive_view', () => this.close() );
        openModal( this.$modal );
    },

    /**
     * Закрытие модального окна и отвязка глобальных обработчиков.
     */
    close() {
        unbindEsc( 'archive_view' );
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
     * Заполнение полей модалки данными архивной записи.
     * Реализует сложную логику нормализации данных с fallback на составное имя.
     *
     * @private
     * @param {Object} d - Объект с данными архивной записи.
     */
    _fill( d ) {
        // Fallback-значение для пустых полей.
        // Символ '—' (тире) используется вместо пустой строки для улучшения UX:
        // пользователь видит "поле не заполнено", а не "здесь что-то сломалось".
        const empty = '—';

        // Утилитарная функция для применения fallback.
        // Если значение falsy (null, undefined, '', 0, false), возвращает '—'.
        // Это стрелочная функция для компактности записи.
        const f     = ( v ) => v || empty;

        // Извлекаем объекты студента и родителя с fallback на пустые объекты.
        // Оператор ?? (nullish coalescing) подставляет {}, если свойство равно null или undefined.
        // Это защищает от ошибок при обращении к свойствам несуществующего объекта.
        const sd = d.student  ?? {};
        const gd = d.guardian ?? {};

        // MAP-ОБЪЕКТ: Маппинг ключей полей на значения из данных.
        // Это ключевой паттерн для массового заполнения полей:
        // вместо 30 отдельных вызовов this.$modal.find(...).text(...),
        // мы создаем объект, где ключи — это data-атрибуты полей в HTML,
        // а значения — извлеченные и нормализованные данные.
        const map = {
            // ДАННЫЕ СТУДЕНТА:
            // ПАТТЕРН: Fallback на парсинг full_name.
            // Если сервер не вернул отдельные поля last_name, first_name, middle_name,
            // но вернул составное full_name (например, "Иванов Иван Иванович"),
            // мы разбиваем его по пробелу и извлекаем нужную часть.
            // Опциональная цепочка ?.split() предотвращает ошибку, если full_name равен undefined.
            // Оператор ?? '' подставляет пустую строку, если split вернул undefined (например, индекс вышел за пределы массива).
            s_last_name:       sd.last_name   ?? sd.full_name?.split( ' ' )[ 0 ] ?? '',
            s_first_name:      sd.first_name  ?? sd.full_name?.split( ' ' )[ 1 ] ?? '',
            s_middle_name:     sd.middle_name ?? sd.full_name?.split( ' ' )[ 2 ] ?? '',

            // Остальные поля студента — простое извлечение с fallback на пустую строку
            s_birth_date:      sd.birth_date  ?? '',
            s_email:           sd.email       ?? '',
            s_phone:           sd.phone       ?? '',
            s_school:          sd.school      ?? '',
            s_grade:           sd.grade       ?? '',
            s_doc_type:        sd.doc_type    ?? '',
            s_doc_number:      sd.doc_number  ?? '',
            s_inn:             sd.inn         ?? '',

            // ДАННЫЕ РОДИТЕЛЯ (guardian):
            // Та же логика парсинга full_name, что и для студента
            g_last_name:       gd.last_name       ?? gd.full_name?.split( ' ' )[ 0 ] ?? '',
            g_first_name:      gd.first_name      ?? gd.full_name?.split( ' ' )[ 1 ] ?? '',
            g_middle_name:     gd.middle_name     ?? gd.full_name?.split( ' ' )[ 2 ] ?? '',

            g_birth_date:      gd.birth_date      ?? '',
            g_email:           gd.email           ?? '',
            g_phone:           gd.phone           ?? '',
            g_doc_type:        gd.doc_type        ?? '',
            g_doc_number:      gd.doc_number      ?? '',
            g_doc_issued_by:   gd.doc_issued_by   ?? '',
            g_doc_issued_date: gd.doc_issued_date ?? '',
            g_inn:             gd.inn             ?? '',
            g_address:         gd.address         ?? '',

            // ДАННЫЕ ДОГОВОРА И ПРИКАЗА:
            contract_no:       d.contract_no       ?? '',
            contract_date:     d.contract_date     ?? '',
            order_no:          d.order_no          ?? '',
            order_date:        d.order_date        ?? '',

            // ДОПОЛНИТЕЛЬНАЯ ИНФОРМАЦИЯ:
            subject:           d.subject           ?? '',
            group:             d.group             ?? '',
            status:            d.status_label      ?? '',
            terminated_at:     d.terminated_at     ?? '',
            terminated_reason: d.terminated_reason ?? '',
        };

        // ИТЕРАЦИЯ ПО MAP-ОБЪЕКТУ:
        // Object.entries(map) возвращает массив пар [ключ, значение] из объекта.
        // Например, { a: 1, b: 2 } → [['a', 1], ['b', 2]].
        // Деструктуризация [key, value] извлекает ключ и значение из каждой пары.
        // Это элегантный способ пройтись по всем полям и заполнить их без дублирования кода.
        Object.entries( map ).forEach( ( [ key, value ] ) => {
            // Находим элемент по data-атрибуту data-arc="ключ" и устанавливаем текст.
            // Функция f(value) применяет fallback на '—' для пустых значений.
            this.$modal.find( `[data-arc="${ key }"]` ).text( f( value ) );
        } );
    },
};