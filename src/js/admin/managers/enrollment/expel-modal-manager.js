/**
 * @module ExpelModalManager
 * @description Менеджер для управления процессом отчисления студентов (одиночное и массовое).
 *              Отвечает за:
 *              - Инициализацию модального окна отчисления
 *              - Обработку кликов по кнопкам с безопасным парсингом данных из DOM
 *              - Клиентскую валидацию формы перед отправкой
 *              - Выполнение одиночных или массовых параллельных AJAX-запросов
 *              - Генерацию кастомных событий DOM для уведомления других компонентов об изменении состояния
 *
 * @requires jQuery
 * @requires ExpelModal - UI-компонент модального окна
 * @requires apiError, showModalError, clearModalError - утилиты для логирования и отображения ошибок
 */

import { ExpelModal } from '../../modals/enrollment/expel-modal.js';
import { apiError, showModalError, clearModalError } from '../../modules/utils.js';

const $ = jQuery;

/**
 * Глобальный менеджер отчисления.
 * Поддерживает два режима:
 * 1. Одиночное: клик по элементу .js-expel-student с data-атрибутами.
 * 2. Массовое: прямой вызов ExpelModal.openBulk(students[]) извне (например, из таблицы StudentsTable).
 */
export const ExpelModalManager = {
    /**
     * Флаг инициализации для предотвращения повторного навешивания обработчиков событий.
     * @private
     * @type {boolean}
     */
    _initialized: false,

    /**
     * Инициализация менеджера.
     * Точка входа, вызывается при загрузке страницы.
     */
    init() {
        // Защита от повторной инициализации (паттерн Singleton)
        if ( this._initialized ) return;
        this._initialized = true;

        ExpelModal.init();

        // Делегирование события клика для кнопок одиночного отчисления
        $( document ).on( 'click', '.js-expel-student', ( e ) => this._handleTrigger( e ) );

        // Подписка на подтверждение действия внутри модального окна
        ExpelModal.onConfirm( ( formData ) => this._doExpel( formData ) );
    },

    /**
     * Обработчик клика по кнопке отчисления конкретного студента.
     * Считывает данные из data-атрибутов и открывает модальное окно.
     * @private
     * @param {jQuery.Event} e - Событие клика.
     */
    _handleTrigger( e ) {
        e.preventDefault();

        // Останавливаем всплытие события. Это предотвращает срабатывание 
        // обработчиков клика на родительских элементах (например, если кнопка 
        // находится внутри строки таблицы, у которой есть свой обработчик клика).
        e.stopPropagation();

        const $el         = $( e.currentTarget );
        const studentId   = $el.data( 'expel-student-id' );
        const studentName = $el.data( 'expel-student-name' ) || '';

        if ( ! studentId ) return;

        let enrollments = [];

        // Безопасный парсинг JSON из data-атрибута.
        // Использование try...catch предотвращает фатальное падение всего скрипта, 
        // если данные в атрибуте повреждены или не являются валидным JSON.
        try {
            const raw = $el.attr( 'data-expel-enrollments' );
            if ( raw ) enrollments = JSON.parse( raw );
        } catch ( _ ) {
            // Игнорируем ошибки парсинга, оставляем пустой массив по умолчанию
        }

        ExpelModal.open( studentId, studentName, enrollments );
    },

    /**
     * Основная логика обработки данных формы перед отправкой.
     * Выполняет клиентскую валидацию и маршрутизирует запрос на одиночное или массовое отчисление.
     * @private
     * @param {Object} formData - Данные, собранные из формы модального окна.
     */
    _doExpel( formData ) {
        // Клиентская валидация: экономит трафик и дает мгновенный отклик пользователю
        if ( ! formData.reason ) {
            showModalError( 'Выберите причину отчисления.', ExpelModal.$modal );
            return;
        }

        if ( formData.is_other_empty ) {
            showModalError( 'Уточните конкретнее причину отчисления.', ExpelModal.$modal );
            return;
        }

        // Очищаем предыдущие ошибки перед новым запросом
        clearModalError( ExpelModal.$modal );

        // Маршрутизация: если передан массив ID, выполняем массовое отчисление, иначе одиночное
        if ( formData.student_ids ) {
            this._doExpelBulk( formData );
        } else {
            this._doExpelSingle( formData );
        }
    },

    /**
     * Выполнение AJAX-запроса для отчисления одного студента.
     * @private
     * @param {Object} formData - Данные формы.
     */
    _doExpelSingle( formData ) {
        ExpelModal.setSaving( true );

        const payload = {
            action:     fs_lms_vars.ajax_actions.expelStudent,
            security:   fs_lms_vars.nonces.expulsion,
            student_id: formData.student_id,
            reason:     formData.reason,
        };

        // Добавляем record_id в payload только если он присутствует (опциональное поле)
        if ( formData.record_id ) {
            payload.record_id = formData.record_id;
        }

        $.post( fs_lms_vars.ajaxurl, payload )
            .done( ( res ) => {
                if ( res.success ) {
                    ExpelModal.close();
                    const remaining = res.data?.remaining_enrollments || [];

                    // ПАТТЕРН: Кастомные события DOM (Pub/Sub)
                    // Вместо того чтобы напрямую манипулировать DOM (например, удалять строку таблицы),
                    // менеджер генерирует событие. Другие компоненты (например, таблица студентов) 
                    // могут слушать это событие и обновлять свой интерфейс независимо. Это снижает связанность кода.
                    if ( remaining.length === 0 ) {
                        $( document ).trigger( 'fs:student:expelled', { studentId: formData.student_id } );
                    } else {
                        $( document ).trigger( 'fs:student:expel-partial', {
                            studentId: formData.student_id,
                            remaining,
                        } );
                    }
                } else {
                    showModalError( res.data?.message || res.data || 'Ошибка отчисления.', ExpelModal.$modal );
                    ExpelModal.setSaving( false );
                }
            } )
            .fail( () => {
                apiError( 'Failed to expel student' );
                ExpelModal.setSaving( false );
            } );
    },

    /**
     * Выполнение массового отчисления студентов.
     * Отправляет параллельные AJAX-запросы для каждого студента и отслеживает завершение всех запросов.
     * @private
     * @param {Object} formData - Данные формы, содержащие массив student_ids.
     */
    _doExpelBulk( formData ) {
        ExpelModal.setSaving( true );

        let done   = 0;
        let errors = 0;
        const total = formData.student_ids.length;

        // Запускаем все запросы параллельно
        formData.student_ids.forEach( ( studentId ) => {
            $.post( fs_lms_vars.ajaxurl, {
                action:     fs_lms_vars.ajax_actions.expelStudent,
                security:   fs_lms_vars.nonces.expulsion,
                student_id: studentId,
                reason:     formData.reason,
            } )
                .done( ( res ) => {
                    if ( res.success ) {
                        const remaining = res.data?.remaining_enrollments || [];
                        if ( remaining.length === 0 ) {
                            $( document ).trigger( 'fs:student:expelled', { studentId: parseInt( studentId, 10 ) } );
                        } else {
                            $( document ).trigger( 'fs:student:expel-partial', {
                                studentId: parseInt( studentId, 10 ),
                                remaining,
                            } );
                        }
                    } else {
                        errors++;
                        showModalError( res.data?.message || 'Ошибка отчисления.', ExpelModal.$modal );
                    }

                    // Проверяем, все ли запросы завершены (успешно или с ошибкой)
                    if ( ++done === total ) {
                        this._onBulkDone( errors, formData );
                    }
                } )
                .fail( () => {
                    errors++;
                    apiError( 'Bulk expel failed' );

                    // Проверяем, все ли запросы завершены (включая упавшие)
                    if ( ++done === total ) {
                        this._onBulkDone( errors, formData );
                    }
                } );
        } );
    },

    /**
     * Финальный обработчик после завершения всех запросов массового отчисления.
     * @private
     * @param {number} errors - Количество запросов, завершившихся с ошибкой.
     * @param {Object} formData - Исходные данные формы.
     */
    _onBulkDone( errors, formData ) {
        if ( errors === 0 ) {
            // Если все успешно, закрываем модалку и сбрасываем выбор в выпадающем списке массовых действий
            ExpelModal.close();
            $( '#js-bulk-action' ).val( '' );

            // Вызываем колбэк, переданный извне (например, для перезагрузки таблицы или обновления счетчиков)
            if ( typeof formData.afterExpel === 'function' ) {
                formData.afterExpel();
            }
        } else {
            // Если были ошибки, оставляем модалку открытой и разблокируем кнопку, 
            // чтобы пользователь мог прочитать сообщения об ошибках и принять решение
            ExpelModal.setSaving( false );
        }
    },
};