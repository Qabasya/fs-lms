/**
 * @module SelectParentModal
 * @description UI-компонент модального окна для поиска и назначения существующего родителя
 *              к заявке на зачисление. Реализует полноценный UX-флоу:
 *              - Открытие модалки с привязкой к конкретной заявке
 *              - Поиск родителей по имени/email через AJAX
 *              - Отображение результатов в динамически генерируемой таблице
 *              - Выбор родителя и назначение его на заявку
 *              - Снятие назначения родителя с подтверждением
 *              - Обновление строки таблицы заявок БЕЗ перезагрузки страницы
 *
 *              Ключевая особенность: после успешного назначения/снятия родителя
 *              строка таблицы обновляется через DOM-манипуляции, что создает
 *              ощущение мгновенного отклика интерфейса (SPA-подобное поведение).
 *
 * @requires jQuery
 * @requires openModal, closeModal, bindEsc, unbindEsc - базовые утилиты управления модальными окнами
 * @requires AlertModal - модальное окно для отображения ошибок
 * @requires ConfirmModal - модальное окно для подтверждения деструктивных действий
 */

import { openModal, closeModal, bindEsc, unbindEsc } from '../../modules/modal-base.js';
import { AlertModal } from '../alert-modal.js';
import { ConfirmModal } from '../confirm-modal.js';

const $ = jQuery;

/** Минимальная длина запроса для живого поиска. */
const MIN_QUERY = 2;

/** Задержка живого поиска, мс. */
const SEARCH_DEBOUNCE_MS = 250;

/**
 * Простой debounce: откладывает вызов fn, пока ввод не «успокоится».
 * @param {Function} fn
 * @param {number} ms
 * @returns {Function}
 */
function debounce( fn, ms ) {
    let timer;
    return ( ...args ) => {
        clearTimeout( timer );
        timer = setTimeout( () => fn( ...args ), ms );
    };
}

/**
 * UI-компонент модального окна выбора существующего родителя для заявки.
 * Работает автономно: сам обрабатывает AJAX-запросы и обновление DOM,
 * в отличие от других модалок, которые делегируют бизнес-логику менеджеру.
 */
export const SelectParentModal = {
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

        this.$modal = $( '#fs-select-parent-modal' );
        if ( ! this.$modal.length ) return; // Если модалки нет в DOM, прекращаем инициализацию

        this._initialized = true;
        this._bindEvents();
    },

    /**
     * Привязка обработчиков событий.
     * Используется неймспейсинг '.spm' для всех делегированных событий,
     * чтобы можно было точечно удалить их через $(document).off('click.spm').
     * @private
     */
    _bindEvents() {
        // ДЕЛЕГИРОВАНИЕ СОБЫТИЙ ДЛЯ КНОПКИ ОТКРЫТИЯ МОДАЛКИ:
        // Кнопки .js-select-existing-parent могут быть добавлены в DOM динамически
        // (например, после AJAX-обновления таблицы заявок), поэтому используем делегирование.
        $( document ).on( 'click.spm', '.js-select-existing-parent', ( e ) => {
            e.preventDefault();
            // Считываем ID заявки из data-атрибута кнопки
            const appId = $( e.currentTarget ).data( 'application-id' );
            this.open( appId );
        } );

        // ДЕЛЕГИРОВАНИЕ ДЛЯ КНОПКИ СНЯТИЯ НАЗНАЧЕНИЯ:
        // Эта кнопка создается динамически в методе _updateRow(), поэтому прямая привязка невозможна.
        $( document ).on( 'click.spm', '.js-remove-parent-assignment', ( e ) => {
            e.preventDefault();
            const appId = $( e.currentTarget ).data( 'application-id' );
            this._removeParent( appId );
        } );

        // Закрытие модалки при клике на фон, кнопку отмены или крестик
        this.$modal.on( 'click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .js-modal-close, .fs-close', ( e ) => {
            e.preventDefault();
            this.close();
        } );

        // ЖИВОЙ ПОИСК ПО ВВОДУ:
        // По мере набора (с debounce) запускаем поиск, как только введено >= MIN_QUERY символов.
        // Пока символов меньше — прячем результаты, чтобы не мигать «Ничего не найдено».
        const liveSearch = debounce( () => this._search(), SEARCH_DEBOUNCE_MS );
        this.$modal.on( 'input', '#spm-search', () => {
            const query = this.$modal.find( '#spm-search' ).val().trim();
            if ( query.length < MIN_QUERY ) {
                this.$modal.find( '#spm-table' ).prop( 'hidden', true );
                this.$modal.find( '#spm-no-results' ).prop( 'hidden', true );
                return;
            }
            liveSearch();
        } );

        // ОБРАБОТКА КЛАВИШИ ENTER В ПОЛЕ ПОИСКА:
        // Нажатие Enter запускает поиск немедленно (без ожидания debounce).
        this.$modal.on( 'keydown', '#spm-search', ( e ) => {
            if ( e.key === 'Enter' ) {
                e.preventDefault(); // Предотвращаем возможную отправку формы
                this._search();
            }
        } );

        // ДЕЛЕГИРОВАНИЕ ДЛЯ КНОПОК "ВЫБРАТЬ" В РЕЗУЛЬТАТАХ ПОИСКА:
        // Эти кнопки создаются динамически в методе _search() при генерации HTML таблицы,
        // поэтому прямая привязка невозможна — используем делегирование.
        this.$modal.on( 'click', '.js-spm-select', ( e ) => {
            e.preventDefault();
            const personId = $( e.currentTarget ).data( 'person-id' );
            this._selectParent( personId );
        } );
    },

    /**
     * Открытие модального окна для выбора родителя.
     * Сбрасывает состояние поиска и подготавливает модалку к работе.
     *
     * @param {string|number} applicationId - ID заявки, для которой назначается родитель.
     */
    open( applicationId ) {
        // Сохраняем ID заявки в скрытом поле формы.
        // Это нужно, чтобы при последующих AJAX-запросах (_selectParent) 
        // знать, к какой заявке привязывать выбранного родителя.
        this.$modal.find( '#spm-application-id' ).val( applicationId );

        // Сбрасываем поле поиска к пустому значению
        this.$modal.find( '#spm-search' ).val( '' );

        // СКРЫТИЕ РЕЗУЛЬТАТОВ ПРЕДЫДУЩЕГО ПОИСКА:
        // Если пользователь открывает модалку повторно (для другой заявки),
        // нужно скрыть таблицу результатов и сообщение "ничего не найдено" 
        // от предыдущего поиска, чтобы не вводить пользователя в заблуждение.
        this.$modal.find( '#spm-table' ).prop( 'hidden', true );
        this.$modal.find( '#spm-no-results' ).prop( 'hidden', true );

        // Привязываем клавишу ESC с уникальным неймспейсом 'select_parent'
        bindEsc( 'select_parent', () => this.close() );

        // Открываем модалку через базовую утилиту
        openModal( this.$modal );
    },

    /**
     * Закрытие модального окна и отвязка глобальных обработчиков.
     */
    close() {
        unbindEsc( 'select_parent' );
        closeModal( this.$modal );
    },

    /**
     * Выполнение AJAX-запроса для поиска родителей по имени или email.
     * Результаты отображаются в динамически генерируемой HTML-таблице.
     * @private
     */
    _search() {
        // Получаем поисковый запрос и удаляем пробелы по краям
        const query = this.$modal.find( '#spm-search' ).val().trim();

        // Безопасное получение глобальных переменных WordPress.
        // Оператор ?? подставляет пустой объект, если fs_lms_applications_vars не определен,
        // что предотвращает ошибки при обращении к vars.nonces.
        const vars  = window.fs_lms_applications_vars ?? {};

        $.ajax( {
            url:    fs_lms_vars.ajaxurl,
            method: 'POST',
            data:   {
                action:   fs_lms_vars.ajax_actions.searchParents,
                query:    query,
                // Безопасное получение nonce с опциональной цепочкой ?.:
                // если vars.nonces равен undefined, выражение вернет undefined, 
                // а ?? '' подставит пустую строку.
                security: vars.nonces?.manager ?? '',
            },
            success: ( res ) => {
                // Если сервер вернул ошибку, просто выходим — не показываем уведомление,
                // так как это может быть штатная ситуация (например, слишком короткий запрос).
                if ( ! res.success ) { return; }

                const rows = res.data ?? [];

                // ОЧИСТКА ТАБЛИЦЫ РЕЗУЛЬТАТОВ:
                // Метод .empty() удаляет все дочерние элементы из tbody,
                // подготавливая его для новых результатов поиска.
                const $tbody = this.$modal.find( '#spm-tbody' ).empty();

                // ОБРАБОТКА ПУСТОГО РЕЗУЛЬТАТА:
                // Если поиск не вернул ни одной записи, скрываем таблицу 
                // и показываем специальное сообщение "Ничего не найдено".
                if ( rows.length === 0 ) {
                    this.$modal.find( '#spm-table' ).prop( 'hidden', true );
                    this.$modal.find( '#spm-no-results' ).prop( 'hidden', false );
                    return;
                }

                // ГЕНЕРАЦИЯ HTML ТАБЛИЦЫ:
                // Проходим по каждому результату и создаем строку таблицы.
                // Используем шаблонные строки (template literals) для читаемости.
                rows.forEach( ( r ) => {
                    // Защита от некорректных данных: пропускаем записи без person_id
                    if ( ! r.person_id ) return;

                    $tbody.append(
                        `<tr>
                            <td>${ r.display_name ?? '' }</td>
                            <td>${ r.email ?? '' }</td>
                            <td>
                                <button type="button"
                                    class="button button-small js-spm-select"
                                    data-person-id="${ r.person_id }">
                                    Выбрать
                                </button>
                            </td>
                        </tr>`
                    );
                } );

                // ПОКАЗ ТАБЛИЦЫ РЕЗУЛЬТАТОВ:
                // Скрываем сообщение "ничего не найдено" и показываем таблицу
                this.$modal.find( '#spm-no-results' ).prop( 'hidden', true );
                this.$modal.find( '#spm-table' ).prop( 'hidden', false );
            },
            // Обработчик сетевых ошибок намеренно пустой.
            // Это "fire and forget" запрос: если поиск упал, просто ничего не показываем.
            // Пользователь может повторить попытку, нажав "Найти" еще раз.
            error: () => {},
        } );
    },

    /**
     * Назначение выбранного родителя на заявку.
     * Выполняет AJAX-запрос и обновляет строку таблицы без перезагрузки страницы.
     *
     * @private
     * @param {string|number} personId - ID выбранного родителя.
     */
    _selectParent( personId ) {
        // Считываем ID заявки из скрытого поля (установленного при открытии модалки)
        const appId = this.$modal.find( '#spm-application-id' ).val();
        const vars  = window.fs_lms_applications_vars ?? {};

        $.ajax( {
            url:    fs_lms_vars.ajaxurl,
            method: 'POST',
            data:   {
                action:           fs_lms_vars.ajax_actions.selectExistingParent,
                application_id:   appId,
                parent_person_id: personId,
                security:         vars.nonces?.selectExistingParent ?? '',
            },
            success: ( res ) => {
                if ( ! res.success ) {
                    // Используем AlertModal для показа ошибки вместо alert().
                    // Это соответствует единому стилю приложения и выглядит профессиональнее.
                    AlertModal.show( res.data || 'Ошибка назначения родителя.' );
                    return;
                }

                // Закрываем модалку после успешного назначения
                this.close();

                // ОБНОВЛЕНИЕ СТРОКИ ТАБЛИЦЫ БЕЗ ПЕРЕЗАГРУЗКИ:
                // Это ключевой UX-паттерн: вместо location.reload() мы точечно 
                // обновляем только ту строку таблицы, которую редактировали.
                // Это создает ощущение мгновенного отклика интерфейса.
                this._updateRow(
                    appId,
                    res.data.parent_name ?? '',   // Имя назначенного родителя
                    res.data.join_url ?? '',      // Новая JOIN-ссылка
                    true                          // Флаг: родитель назначен
                );
            },
            error: () => AlertModal.show( 'Сетевая ошибка.' ),
        } );
    },

    /**
     * Снятие назначения родителя с заявки.
     * Использует async/await с ConfirmModal для линейного и читаемого кода.
     *
     * ПАТТЕРН: async/await с промисифицированным ConfirmModal.
     * Вместо вложенных .then()/.catch() мы используем try/catch,
     * что делает код похожим на синхронный и легко читаемым.
     *
     * @private
     * @param {string|number} appId - ID заявки.
     */
    async _removeParent( appId ) {
        // ПАТТЕРН: Подтверждение через await.
        // ConfirmModal.confirm() возвращает Promise, который разрешается при подтверждении
        // и отклоняется при отмене. Мы ждем результата через await.
        try {
            await ConfirmModal.confirm( {
                message:     'Снять назначение родителя? JOIN-ссылка будет обновлена.',
                confirmText: 'Снять',
                isDanger:    true, // Красная кнопка для визуального обозначения опасного действия
            } );
        } catch {
            // Если пользователь нажал "Отмена" или закрыл модалку, 
            // Promise отклоняется, и мы попадаем в catch.
            // Просто выходим без выполнения AJAX-запроса.
            return;
        }

        // Если мы дошли до этой строки, значит пользователь подтвердил действие.
        // Выполняем AJAX-запрос на снятие назначения.
        const vars = window.fs_lms_applications_vars ?? {};

        $.ajax( {
            url:    fs_lms_vars.ajaxurl,
            method: 'POST',
            data:   {
                action:         fs_lms_vars.ajax_actions.removeParentAssignment,
                application_id: appId,
                security:       vars.nonces?.removeParentAssignment ?? '',
            },
            success: ( res ) => {
                if ( ! res.success ) {
                    AlertModal.show( res.data || 'Ошибка снятия назначения.' );
                    return;
                }

                // Обновляем строку таблицы: родитель снят, имя '—', новая JOIN-ссылка
                this._updateRow( appId, '—', res.data.join_url ?? '', false );
            },
            error: () => AlertModal.show( 'Сетевая ошибка.' ),
        } );
    },

    /**
     * Обновление строки таблицы заявок после назначения/снятия родителя.
     * Реализует паттерн "точечного обновления DOM": вместо перезагрузки всей страницы
     * мы меняем только конкретные ячейки в нужной строке.
     *
     * ПАТТЕРН: Условный рендеринг кнопок действий.
     * В зависимости от флага hasParent генерируются разные наборы кнопок:
     * - Если родитель назначен: "Сменить родителя" + "Снять назначение"
     * - Если родитель не назначен: только "Назначить родителя"
     *
     * @private
     * @param {string|number} appId - ID заявки.
     * @param {string} parentName - Имя родителя (или '—', если не назначен).
     * @param {string} joinUrl - Новая JOIN-ссылка для приглашения родителя.
     * @param {boolean} hasParent - Флаг: назначен ли родитель.
     */
    _updateRow( appId, parentName, joinUrl, hasParent ) {
        // ПОИСК СТРОКИ ПО DATA-АТРИБУТУ:
        // Используем атрибут data-app-id для точного поиска нужной строки в таблице.
        // Это надежнее, чем поиск по индексу строки, который может измениться 
        // после сортировки или фильтрации таблицы.
        const $row = $( `tr[data-app-id="${ appId }"]` );
        if ( ! $row.length ) { return; } // Если строка не найдена, выходим

        // Обновляем колонку ФИО родителя (вторая колонка, индекс 1)
        // Метод .eq(1) выбирает элемент по индексу из набора найденных td
        $row.find( 'td' ).eq( 1 ).text( parentName );

        // Обновляем JOIN-ссылку и кнопки действий (четвертая колонка, индекс 3)
        const $joinCell = $row.find( 'td' ).eq( 3 );
        const $joinBtn  = $joinCell.find( '.fs-lms-join-code' );

        // ОБНОВЛЕНИЕ JOIN-КНОПКИ:
        // Если в ячейке уже есть кнопка с JOIN-кодом, обновляем её атрибуты.
        if ( $joinBtn.length ) {
            // ИЗВЛЕЧЕНИЕ КОДА ИЗ URL:
            // joinUrl имеет формат "https://example.com/join/abc123", 
            // где "abc123" — это сам код приглашения.
            // split('/') разбивает строку по символу '/', а pop() берет последний элемент.
            // Это простой и эффективный способ извлечь код без регулярных выражений.
            const code = joinUrl.split( '/' ).pop();
            $joinBtn.attr( 'data-url', joinUrl ).text( code );
        }

        // УДАЛЕНИЕ СТАРЫХ КНОПОК ДЕЙСТВИЙ:
        // Перед добавлением новых кнопок удаляем старые, чтобы избежать дублирования.
        // Это важно, так как _updateRow() может вызываться многократно для одной строки.
        $joinCell.find( '.js-select-existing-parent' ).remove();
        $joinCell.find( '.js-remove-parent-assignment' ).remove();

        // УСЛОВНЫЙ РЕНДЕРИНГ КНОПОК:
        // В зависимости от состояния hasParent генерируем разные наборы кнопок.
        if ( hasParent ) {
            // РОДИТЕЛЬ НАЗНАЧЕН: показываем кнопки "Сменить" и "Снять"
            $joinCell.append(
                `<br><button type="button"
                    class="button-link js-select-existing-parent"
                    data-application-id="${ appId }"
                    style="margin-top: 4px; font-size: 11px;">
                    ✎ Сменить родителя
                </button>
                <button type="button"
                    class="button-link js-remove-parent-assignment"
                    data-application-id="${ appId }"
                    style="margin-top: 4px; font-size: 11px; color:#a00;">
                    ✕ Снять назначение
                </button>`
            );
        } else {
            // РОДИТЕЛЬ НЕ НАЗНАЧЕН: показываем только кнопку "Назначить"
            $joinCell.append(
                `<br><button type="button"
                    class="button-link js-select-existing-parent"
                    data-application-id="${ appId }"
                    style="margin-top: 4px; font-size: 11px;">
                    + Назначить родителя
                </button>`
            );
        }
    },
};