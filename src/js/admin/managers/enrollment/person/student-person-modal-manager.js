/**
 * @module StudentPersonModalManager
 * @description Менеджер для управления модальным окном просмотра и редактирования данных студента.
 *              Отвечает за:
 *              - Отображение данных студента с маскированием персональных данных (PII)
 *              - Раскрытие полных данных по запросу администратора
 *              - Редактирование и сохранение изменений
 *              - Экспорт персональных данных в файл
 *              - Регенерацию пароля для связанного пользователя WordPress
 *              - Динамическую синхронизацию кнопки отчисления с актуальными зачислениями студента
 *              - Подписку на глобальные события системы (отчисление, частичное отчисление)
 *
 * @requires jQuery
 * @requires StudentPersonModal - UI-компонент модального окна
 */

import { StudentPersonModal } from '../../../modals/enrollment/person/student-person-modal.js';

const $ = jQuery;

// Геттеры для глобальных переменных WordPress.
const NONCES   = () => fs_lms_applications_vars.nonces;
const ACTIONS  = () => fs_lms_vars.ajax_actions;
const AJAX_URL = () => fs_lms_vars.ajaxurl;

/**
 * Основной объект-менеджер.
 * Методы с префиксом `_` — внутренние, не предназначены для вызова извне.
 */
export const StudentPersonModalManager = {

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
        // Защита от повторной инициализации (паттерн Singleton).
        // В сложных приложениях скрипты могут подгружаться несколько раз, 
        // и без этой проверки один клик вызовет 2, 3, 10 одинаковых действий.
        if ( this._initialized ) return;

        StudentPersonModal.init();

        // Дополнительная проверка: если сам UI-компонент не инициализировался, 
        // не навешиваем обработчики событий менеджера.
        // Это предотвращает ситуации, когда обработчики ссылаются на неинициализированный DOM.
        if ( ! StudentPersonModal._initialized ) return;

        this._initialized = true;
        this._bindEvents();
    },

    /**
     * Привязка обработчиков событий.
     * Используется неймспейсинг событий (например, 'click.spmm') для точечного управления обработчиками.
     * Это позволяет в будущем удалить только обработчики этого модуля через $(document).off('click.spmm'),
     * не затрагивая другие части системы.
     * @private
     */
    _bindEvents() {
        // Открытие модального окна при клике на кнопку просмотра студента.
        // Селектор [data-person-type="student"] гарантирует, что обработчик сработает 
        // только для студентов, а не для родителей (у которых person-type="parent").
        $( document ).on( 'click.spmm', '.js-view-person[data-person-type="student"]', ( e ) => {
            e.preventDefault();
            this._openModal( $( e.currentTarget ) );
        } );

        // Раскрытие всех маскированных персональных данных (без перехода в режим редактирования)
        $( document ).on( 'click.spmm_reveal', '#fs-student-person-modal .js-reveal-all', ( e ) => {
            e.preventDefault();
            this._revealAll();
        } );

        // Включение режима редактирования с предварительной загрузкой полных данных
        $( document ).on( 'click.spmm_edit', '#fs-student-person-modal .js-pmm-edit', ( e ) => {
            e.preventDefault();
            this._startEditing();
        } );

        // Отмена редактирования и возврат к режиму просмотра
        $( document ).on( 'click.spmm_cancel', '#fs-student-person-modal .js-pmm-cancel', ( e ) => {
            e.preventDefault();
            StudentPersonModal.setEditing( false );
        } );

        // Сохранение изменений данных студента
        $( document ).on( 'click.spmm_save', '#fs-student-person-modal .js-pmm-save', ( e ) => {
            e.preventDefault();
            this._save();
        } );

        // Экспорт персональных данных студента в файл
        $( document ).on( 'click.spmm_export', '.js-export-person[data-person-type="student"]', ( e ) => {
            e.preventDefault();
            const personId = parseInt( $( e.currentTarget ).data( 'personId' ), 10 );
            if ( personId ) this._export( personId );
        } );

        // Подписка на кастомное событие полного отчисления студента.
        // Если студент отчислен полностью (не осталось ни одного зачисления), закрываем модалку.
        $( document ).on( 'fs:student:expelled', () => {
            StudentPersonModal.close();
        } );

        // Подписка на событие частичного отчисления (удалено одно из нескольких зачислений).
        // Также закрываем модалку, так как данные устарели и требуют перезагрузки.
        $( document ).on( 'fs:student:expel-partial', () => {
            StudentPersonModal.close();
        } );

        // Подписка на событие регенерации пароля (может быть вызвано из других частей системы).
        // Деструктуризация { wpUserId, $btn } извлекает параметры события.
        $( document ).on( 'fs-lms:spm-regenerate-password', ( e, { wpUserId, $btn } ) => {
            this._regeneratePassword( wpUserId, $btn );
        } );
    },

    /**
     * Открытие модального окна с данными студента.
     * Сначала заполняет модалку базовыми данными из DOM (мгновенно),
     * затем загружает через AJAX дополнительные данные: расписание, маскированные PII,
     * список всех зачислений и логин.
     * @private
     * @param {jQuery} $btn - jQuery-объект кнопки, на которую нажали.
     */
    _openModal( $btn ) {
        const personId = parseInt( $btn.data( 'personId' ), 10 ) || 0;
        const wpUserId = parseInt( $btn.data( 'wpUserId' ), 10 ) || 0;

        // Извлекаем дополнительные данные из data-атрибутов строки таблицы.
        // Оператор || {} предотвращает ошибки, если атрибут отсутствует.
        const rowData  = $btn.closest( 'tr' ).data( 'enrollment' ) || {};

        // Сбрасываем состояние модалки перед открытием, чтобы очистить данные от предыдущего просмотра
        StudentPersonModal.reset();
        StudentPersonModal.setPersonId( personId );
        StudentPersonModal.setWpUserId( wpUserId );

        const studentName = $btn.data( 'displayName' ) || '';

        // ДИНАМИЧЕСКОЕ ОБНОВЛЕНИЕ DATA-АТРИБУТОВ КНОПКИ ОТЧИСЛЕНИЯ.
        // Это критически важный момент: кнопка отчисления находится ВНУТРИ модалки, 
        // и её data-атрибуты должны соответствовать текущему студенту.
        // 
        // ВАЖНО: используем И .data(), И .attr() одновременно.
        // - .data() обновляет внутренний кэш jQuery (используется при чтении через .data())
        // - .attr() обновляет реальный HTML-атрибут (используется при чтении через .attr() или в селекторах)
        // Если обновить только одно из двух, часть кода будет читать старые значения.
        $( '#fs-student-person-modal .js-expel-student' )
            .data( 'expel-student-id', wpUserId )
            .data( 'expel-student-name', studentName )
            .attr( 'data-expel-student-id', wpUserId )
            .attr( 'data-expel-student-name', studentName )
            .removeAttr( 'data-expel-enrollments' ); // Очищаем старые зачисления, загрузим новые через AJAX

        // МГНОВЕННОЕ ЗАПОЛНЕНИЕ: Сразу показываем данные из строки таблицы, 
        // не дожидаясь AJAX-запроса. Это создает ощущение мгновенного отклика интерфейса.
        // Пользователь видит модалку с данными за миллисекунды, а детальные данные подгружаются фоном.
        StudentPersonModal.fill( {
            display_name:  $btn.data( 'displayName' )      || '',
            last_name:     rowData.student_last_name        || '',
            first_name:    rowData.student_first_name       || '',
            middle_name:   rowData.student_middle_name      || '',
            email:         $btn.data( 'email' )             || '',
            login:         $btn.data( 'userLogin' )         || '',
            phone:         rowData.student_phone            || '',
            contract_no:   rowData.contract_no              || '',
            subject:       rowData.subject                  || '',
            group:         rowData.group                    || '',
            schedule:      rowData.schedule                 || '',
            school:        rowData.student_school           || '',
            grade:         String( rowData.student_grade    || '' ), // Явное преобразование в строку для безопасности типов
            birth_date:    rowData.student_birth_date       || '',
            guardian_name: rowData.guardian_full_name       || '',
        } );

        StudentPersonModal.open();

        // Если personId отсутствует (новый студент или тестовые данные), не делаем AJAX-запрос
        if ( ! personId ) return;

        // LAZY LOADING: Загружаем детальные данные только при открытии модалки.
        // Это оптимизирует производительность: не нужно загружать PII и расписание 
        // для всех студентов в таблице сразу.
        $.post( AJAX_URL(), {
            action:    ACTIONS().getPersonData,
            person_id: personId,
            security:  NONCES().manager,
        } ).done( ( res ) => {
            if ( ! res.success ) return;

            // Извлекаем массив зачислений и маскированные PII с fallback на пустые значения.
            // Оператор ?? (nullish coalescing) предпочтительнее || здесь, так как он различает 
            // null/undefined и другие falsy-значения (0, false, ''). Если, например, 
            // enrollments — это пустой массив [], оператор || заменил бы его на {}, 
            // а ?? оставит как есть.
            const enrollments = res.data.enrollments ?? [];
            const enr = enrollments[ 0 ] ?? {}; // Первое зачение — основное, отображается в шапке
            const pii = res.data.masked_pii ?? {};

            // Перезаполняем модалку более точными данными с сервера.
            // Это может перезаписать значения из rowData, если они отличаются 
            // (например, если данные в таблице были кэшированы и устарели).
            StudentPersonModal.fill( {
                last_name:     enr.last_name     ?? '',
                first_name:    enr.first_name    ?? '',
                middle_name:   enr.middle_name   ?? '',
                schedule:      enr.schedule      ?? '',
                subject:       enr.subject_name  ?? '',
                group:         enr.group_title   ?? '',
                contract_no:   enr.contract_no   ?? '',
                birth_date:    enr.birth_date    ?? '',
                school:        enr.school        ?? '',
                grade:         enr.grade         ?? '',
                doc_number:    pii.doc_number    ?? '',
                inn:           pii.inn           ?? '',
                phone:         pii.phone         ?? '',
                email:         res.data.email    ?? '',
                // Безопасное извлечение имени представителя из массива.
                // Цепочка (res.data.representatives ?? [])[0]?.name предотвращает ошибки, 
                // если representatives — undefined или пустой массив.
                guardian_name: ( res.data.representatives ?? [] )[ 0 ]?.name ?? '',
                login:         res.data.login    ?? '',
                password:      res.data.password ?? '',
            } );

            // ДОПОЛНИТЕЛЬНЫЕ ЗАЧИСЛЕНИЯ: Если у студента несколько предметов (2+), 
            // бэкенд возвращает их все в массиве enrollments. Первое мы уже отобразили в шапке, 
            // остальные добавляем отдельными строками через специальный метод модалки.
            // Метод .slice(1) создает копию массива без первого элемента.
            enrollments.slice( 1 ).forEach( ( e ) => {
                StudentPersonModal.addEnrollmentRow( {
                    contract_no: e.contract_no  ?? '',
                    subject:     e.subject_name ?? '',
                    group:       e.group_title  ?? '',
                    schedule:    e.schedule     ?? '',
                } );
            } );

            // СИНХРОНИЗАЦИЯ КНОПКИ ОТЧИСЛЕНИЯ: Формируем массив активных зачислений 
            // и сериализуем его в JSON для передачи через data-атрибут.
            // Это позволяет модулю ExpelModalManager знать, какие именно зачисления 
            // можно удалить, и показывать пользователю список при подтверждении.
            const activeEnrollments = enrollments.map( ( e ) => ( {
                record_id:    e.record_id,
                subject_name: e.subject_name ?? '',
                group_title:  e.group_title  ?? '',
            } ) );

            // Обновляем data-атрибут кнопки отчисления.
            // Здесь используем только .attr(), так как JSON.stringify всегда возвращает строку, 
            // и .data() в данном случае не нужен — значение будет прочитано через .attr() в ExpelModalManager.
            $( '#fs-student-person-modal .js-expel-student' )
                .attr( 'data-expel-enrollments', JSON.stringify( activeEnrollments ) );
        } );
    },

    /**
     * Включение режима редактирования с предварительной загрузкой полных (немаскированных) данных.
     * Параллельно запрашивает PII и учетные данные пользователя WordPress.
     * @private
     */
    _startEditing() {
        const personId = StudentPersonModal.getPersonId();
        const wpUserId = StudentPersonModal.getWpUserId();

        // Если personId отсутствует (новый студент), просто включаем режим редактирования
        if ( ! personId ) {
            StudentPersonModal.setEditing( true );
            return;
        }

        // ПАТТЕРН: Параллельные AJAX-запросы через $.when.
        // Запрашиваем полные персональные данные (без маскирования).
        // Параметр 'reason' передается на сервер для логирования/аудита: 
        // кто и когда раскрыл персональные данные.
        const piiPromise = $.post( AJAX_URL(), {
            action:    ACTIONS().revealAllPersonPii,
            person_id: personId,
            reason:    'admin_userlist_edit',
            security:  NONCES().revealPii,
        } ).done( ( res ) => {
            if ( res.success ) StudentPersonModal.fillRevealed( res.data );
        } );

        // Если есть связанный пользователь WordPress, запрашиваем его пароль.
        // Если wpUserId отсутствует, создаем сразу разрешенный Deferred, 
        // чтобы $.when мог корректно обработать оба промиса.
        const credPromise = wpUserId
            ? $.post( AJAX_URL(), {
                action:   ACTIONS().revealUserCredentials,
                user_id:  wpUserId,
                security: NONCES().revealPii,
            } ).done( ( res ) => {
                if ( res.success ) StudentPersonModal.fillRevealed( { password: res.data.password || '' } );
            } )
            : $.Deferred().resolve();

        // Ждем завершения обоих запросов, затем включаем режим редактирования.
        // .always() срабатывает независимо от успеха/ошибки, гарантируя, что UI не заблокируется, 
        // даже если один из запросов упал с ошибкой.
        $.when( piiPromise, credPromise ).always( () => {
            StudentPersonModal.setEditing( true );
        } );
    },

    /**
     * Раскрытие всех маскированных данных без включения режима редактирования.
     * Используется для просмотра полных данных без возможности их изменения.
     * @private
     */
    _revealAll() {
        const personId = StudentPersonModal.getPersonId();
        const wpUserId = StudentPersonModal.getWpUserId();
        if ( ! personId ) return;

        // Раскрываем PII
        $.post( AJAX_URL(), {
            action:    ACTIONS().revealAllPersonPii,
            person_id: personId,
            reason:    'admin_userlist_reveal',
            security:  NONCES().revealPii,
        } ).done( ( res ) => {
            if ( res.success ) StudentPersonModal.fillRevealed( res.data );
        } );

        // Раскрываем пароль, если есть связанный пользователь
        if ( wpUserId ) {
            $.post( AJAX_URL(), {
                action:   ACTIONS().revealUserCredentials,
                user_id:  wpUserId,
                security: NONCES().revealPii,
            } ).done( ( res ) => {
                if ( res.success ) {
                    StudentPersonModal.fillRevealed( { password: res.data.password || '' } );
                } else {
                    // FALLBACK: Если сервер не смог вернуть пароль (например, он не установлен), 
                    // показываем кнопку "Сгенерировать пароль", чтобы администратор мог создать его.
                    StudentPersonModal.showRegenerateButton( wpUserId );
                }
            } );
        }
    },

    /**
     * Сохранение изменений данных студента.
     * Фильтрует поля перед отправкой, исключая маскированные значения и неразрешенные поля.
     * @private
     */
    _save() {
        const personId = StudentPersonModal.getPersonId();
        if ( ! personId ) return;

        // Белый список полей (Whitelist), которые разрешено редактировать.
        // Это защита от Mass Assignment атак: даже если злоумышленник подменит запрос 
        // и добавит дополнительные поля (например, 'role': 'admin'), они будут отфильтрованы.
        const allowed = [
            'last_name', 'first_name', 'middle_name',
            'phone', 'email', 'birth_date',
            'login', 'password',
            'school',
            'doc_number', 'inn',
        ];

        const edit = StudentPersonModal.getEditData();
        const payload = {
            action:    ACTIONS().updatePerson,
            security:  NONCES().updatePerson,
            person_id: personId,
        };

        // Фильтрация полей: добавляем в payload только разрешенные поля, 
        // которые не содержат символ '•' (маркер маскированного значения).
        // Это предотвращает отправку на сервер маскированных данных вместо реальных.
        // Например, если телефон отображается как "+7 (•••) •••-••-••", 
        // мы не хотим, чтобы это значение было сохранено в базу.
        allowed.forEach( k => {
            if ( edit[ k ] && ! edit[ k ].includes( '•' ) ) payload[ k ] = edit[ k ];
        } );

        $.post( AJAX_URL(), payload ).done( ( res ) => {
            if ( res.success ) StudentPersonModal.setEditing( false );
        } );
    },

    /**
     * Экспорт персональных данных студента в файл.
     * Получает URL для скачивания и перенаправляет браузер на него.
     * @private
     * @param {number} personId - ID студента.
     */
    _export( personId ) {
        if ( ! personId ) return;

        // Единичный экспорт через тот же эндпоинт, что и массовый (поддерживает single-режим
        // при передаче ids). Хук export_pii в плагине не реализован — используем exportStudents.
        $.post( AJAX_URL(), {
            action:   ACTIONS().exportStudents,
            ids:      [ personId ],
            security: NONCES().manager,
        } ).done( r => {
            // Сервер возвращает временную ссылку на файл — инициируем скачивание редиректом.
            if ( r.success && r.data.url ) window.location.href = r.data.url;
        } );
    },

    /**
     * Регенерация пароля для связанного пользователя WordPress.
     * @private
     * @param {number} wpUserId - ID пользователя WordPress.
     * @param {jQuery} $btn - jQuery-объект кнопки, на которую нажали.
     */
    _regeneratePassword( wpUserId, $btn ) {
        // Блокируем кнопку для предотвращения повторных кликов и дублирования запросов
        $btn.prop( 'disabled', true );

        $.post( AJAX_URL(), {
            action:   ACTIONS().regenerateUserPassword,
            user_id:  wpUserId,
            security: NONCES().revealPii,
        } ).done( ( res ) => {
            if ( res.success ) {
                // Обновляем отображение пароля в модалке
                StudentPersonModal.fillRevealed( { password: res.data.password || '' } );
                // Удаляем кнопку, так как пароль уже сгенерирован и больше не нужна
                $btn.remove();
            } else {
                // Разблокируем кнопку при ошибке, чтобы пользователь мог повторить попытку
                $btn.prop( 'disabled', false );
            }
        } );
    },
};