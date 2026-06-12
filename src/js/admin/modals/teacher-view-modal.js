/**
 * @module TeacherViewModal
 * @description UI-компонент модального окна для просмотра информации о преподавателе.
 *              Отвечает за:
 *              - Чтение данных преподавателя из data-атрибутов строки таблицы
 *              - Отображение ФИО, email и списка предметов/групп в режиме "только чтение"
 *              - Безопасное экранирование HTML при рендеринге списков (защита от XSS)
 *              - Обработку пустых состояний (fallback на символ '—')
 *
 *              В отличие от других модалок, этот компонент полностью read-only
 *              и не требует сложной валидации или отправки данных на сервер.
 *
 * @requires jQuery
 * @requires openModal, closeModal, bindEsc, unbindEsc - базовые утилиты управления модальными окнами
 */

import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

/**
 * UI-компонент модального окна просмотра данных преподавателя.
 */
export const TeacherViewModal = {
    /** @type {jQuery|null} Ссылка на основной контейнер модального окна */
    $modal: null,

    /** @type {boolean} Флаг для предотвращения повторной инициализации */
    _initialized: false,

    /**
     * Инициализация компонента.
     * Выполняет проверку существования модалки и навешивает события.
     */
    init() {
        // Защита от повторной инициализации (паттерн Singleton)
        if ( this._initialized ) return;

        this.$modal = $( '#fs-teacher-view-modal' );
        if ( ! this.$modal.length ) return; // Если модалки нет в DOM, прекращаем инициализацию

        this._initialized = true;
        this._bindEvents();
    },

    /**
     * Привязка обработчиков событий.
     * @private
     */
    _bindEvents() {
        // ДЕЛЕГИРОВАНИЕ СОБЫТИЙ С НЕЙМСПЕЙСИНГОМ:
        // Обработчик навешан на document, но срабатывает только для кнопок .js-view-teacher.
        // Неймспейс 'click.tvm' позволяет точечно удалить этот обработчик через 
        // $(document).off('click.tvm'), не затрагивая другие обработчики клика.
        // Это необходимо, так как таблица преподавателей может обновляться динамически.
        $( document ).on( 'click.tvm', '.js-view-teacher', ( e ) => {
            e.preventDefault();

            // ЧТЕНИЕ ДАННЫХ ИЗ СТРОКИ ТАБЛИЦЫ:
            // Вместо передачи каждого поля через отдельные data-атрибуты кнопки,
            // мы считываем весь объект данных из data-teacher строки таблицы.
            // jQuery .data() автоматически парсит JSON из data-атрибута в JavaScript объект.
            const data = $( e.currentTarget ).closest( 'tr' ).data( 'teacher' );
            if ( data ) this.open( data );
        } );

        // Закрытие модалки при клике на фон, кнопку отмены или крестик
        this.$modal.on( 'click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .js-modal-close, .fs-close', ( e ) => {
            e.preventDefault();
            this.close();
        } );
    },

    /**
     * Открытие модального окна с заполнением данными преподавателя.
     * @param {Object} data - Объект с данными преподавателя (full_name, email, subjects_groups).
     */
    open( data ) {
        this._fill( data );
        bindEsc( 'teacher_view', () => this.close() );
        openModal( this.$modal );
    },

    /**
     * Закрытие модального окна и отвязка глобальных обработчиков.
     */
    close() {
        unbindEsc( 'teacher_view' );
        closeModal( this.$modal );
    },

    /**
     * Заполнение полей модалки данными преподавателя.
     * Реализует безопасный рендеринг сложных структур данных (предметы и группы).
     *
     * @private
     * @param {Object} data - Объект с данными преподавателя.
     * @param {string} data.full_name - ФИО преподавателя.
     * @param {string} data.email - Email преподавателя.
     * @param {Array<{subject_name: string, groups: string[]}>} [data.subjects_groups] - Массив предметов и групп.
     */
    _fill( data ) {
        // Fallback-значение для пустых полей.
        // Символ '—' (тире) используется вместо пустой строки для улучшения UX.
        const empty = '—';

        // Заполнение простых текстовых полей с fallback на '—'
        this.$modal.find( '[data-tvm="full_name"]' ).text( data.full_name || empty );
        this.$modal.find( '[data-tvm="email"]' ).text( data.email || empty );

        // УТИЛИТА ДЛЯ БЕЗОПАСНОГО ЭКРАНИРОВАНИЯ HTML (XSS-защита):
        // Создает временный div, устанавливает текст через .text() (что автоматически экранирует спецсимволы),
        // а затем возвращает HTML-представление этого текста через .html().
        // Это гарантирует, что если в названии предмета есть символы вроде '<' или '>', 
        // они будут отображены как текст, а не исполнены как HTML-код.
        const esc = ( str ) => $( '<div>' ).text( String( str ) ).html();

        // Получаем массив предметов и групп с fallback на пустой массив
        const subjectsGroups = data.subjects_groups || [];

        // ОБРАБОТКА ПУСТОГО СОСТОЯНИЯ:
        // Если у преподавателя нет назначенных предметов или групп, 
        // сразу заполняем поля символом '—' и прерываем выполнение.
        if ( ! subjectsGroups.length ) {
            this.$modal.find( '[data-tvm="subjects"]' ).text( empty );
            this.$modal.find( '[data-tvm="groups"]' ).text( empty );
            return;
        }

        // Массивы для накопления отформатированных строк предметов и групп
        const subjectParts = [];
        const groupParts   = [];

        // ИТЕРАЦИЯ ПО СТРУКТУРЕ ДАННЫХ:
        // Преобразуем массив объектов в массивы строк для последующего объединения.
        subjectsGroups.forEach( ( sg ) => {
            // Экранируем и добавляем название предмета
            subjectParts.push( esc( sg.subject_name || '' ) );

            // Для каждого предмета получаем массив групп, экранируем каждую группу 
            // и объединяем их через <br> (перенос строки в HTML).
            // Если групп нет, используем fallback '—'.
            const groupLines = ( sg.groups || [] ).map( ( g ) => esc( g ) ).join( '<br>' );
            groupParts.push( groupLines || empty );
        } );

        // РЕНДЕРИНГ В DOM:
        // Объединяем массивы строк через '<br><br>' (двойной перенос строки для визуального разделения предметов).
        // ВАЖНО: используем .html(), а не .text(), так как мы намеренно вставляем теги <br>, 
        // которые были безопасно сгенерированы через функцию esc().
        this.$modal.find( '[data-tvm="subjects"]' ).html( subjectParts.join( '<br><br>' ) );
        this.$modal.find( '[data-tvm="groups"]' ).html( groupParts.join( '<br><br>' ) );
    },
};