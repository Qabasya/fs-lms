/**
 * @fileoverview Модуль управления таблицей учебных групп.
 *
 * @module GroupsTable
 * @description Менеджер для страницы списка групп.
 *              Отвечает за:
 *              - Раскрытие аккордеонов со списком учеников внутри каждой группы
 *              - Ленивую загрузку списка учеников через AJAX (только при первом раскрытии)
 *              - Защиту от XSS при рендеринге имен учеников
 *              - Фильтрацию таблицы по учебному периоду через изменение URL
 *
 * @requires jQuery
 */

const $ = jQuery;

/**
 * Менеджер таблицы учебных групп.
 */
export const GroupsTable = {
    /** @type {boolean} Флаг для предотвращения повторной инициализации */
    _initialized: false,

    /**
     * Инициализация модуля.
     * Точка входа, вызывается при загрузке страницы.
     */
    init() {
        // Защита от повторной инициализации (паттерн Singleton)
        if (this._initialized) return;

        // Guard clause: проверяем наличие таблицы групп на текущей странице.
        // Если скрипт загружается глобально, но на странице нет таблицы,
        // мы не тратим ресурсы на навешивание обработчиков событий.
        if (!$('.groups-table').length) return;

        this._initialized = true;
        this._bindEvents();
    },

    /**
     * Привязка обработчиков событий.
     * @private
     */
    _bindEvents() {
        // ДЕЛЕГИРОВАНИЕ СОБЫТИЙ:
        // Навешиваем обработчик на document, так как строки таблицы могут добавляться динамически
        // (например, при AJAX-пагинации или фильтрации).
        $(document).on('click', '.js-toggle-students', (e) => this._handleToggleAccordion(e));

        // Инициализация фильтра по периоду
        this._bindPeriodFilter();
    },

    /**
     * Привязка обработчика к выпадающему списку фильтрации по периоду.
     * При изменении значения происходит переход на новый URL с параметром фильтра.
     * @private
     */
    _bindPeriodFilter() {
        // Используем function() вместо стрелочной функции, чтобы 'this' указывал на DOM-элемент select.
        $('#filter-by-period').on('change', function () {
            // ПАТТЕРН: Безопасное манипулирование URL через Web API.
            // Вместо ручного парсинга и конкатенации строк, мы используем встроенный объект URL.
            // Это гарантирует, что все спецсимволы будут корректно закодированы,
            // а существующие параметры URL не будут потеряны.
            const url = new URL(window.location.href);

            // Устанавливаем или обновляем параметр period_filter в query string.
            // Если параметр уже был, он перезапишется. Если не было — добавится.
            url.searchParams.set('period_filter', this.value);

            // Перенаправляем браузер на новый URL.
            // Это стандартный подход для серверной фильтрации без AJAX.
            window.location.href = url.toString();
        });
    },

    /**
     * Обработчик клика по строке группы для раскрытия/скрытия аккордеона с учениками.
     * @private
     * @param {jQuery.Event} e - Событие клика.
     */
    _handleToggleAccordion(e) {
        // ВАЖНЫЙ UX-ПАТТЕРН: Игнорирование клика по интерактивным элементам внутри строки.
        // Если внутри строки группы (.js-toggle-students) есть кнопки удаления, ссылки или другие действия,
        // клик по ним НЕ должен сворачивать/разворачивать аккордеон.
        // closest() проверяет, был ли клик по указанному селектору (или внутри него).
        if ($(e.target).closest('.js-delete-group, .row-actions, a, button').length) {
            return;
        }

        const $row = $(e.currentTarget);
        const groupId = $row.data('group-id');
        const $accordionRow = $(`#students-row-${groupId}`); // Строка таблицы с контентом аккордеона
        const $arrow = $row.find('.accordion-arrow');

        if ($accordionRow.hasClass('hidden')) {
            // РАСКРЫТИЕ:
            $accordionRow.removeClass('hidden');
            $arrow.css('transform', 'rotate(90deg)'); // Поворачиваем стрелочку

            // Загружаем список учеников, если они еще не были загружены (ленивая загрузка)
            this._loadStudentsIfNeeded(groupId, $accordionRow);
        } else {
            // СКРЫТИЕ:
            $accordionRow.addClass('hidden');
            $arrow.css('transform', 'rotate(0deg)'); // Возвращаем стрелочку в исходное положение
        }
    },

    /**
     * Ленивая загрузка списка учеников группы через AJAX.
     * Данные загружаются только при первом раскрытии аккордеона и кэшируются в DOM.
     *
     * ПАТТЕРН: Клиентское кэширование через data-атрибуты.
     * Мы помечаем строку флагом data('loaded') = true после успешной загрузки.
     * При последующих раскрытиях аккордеона AJAX-запрос не отправляется,
     * что экономит трафик и снижает нагрузку на сервер.
     *
     * @private
     * @param {string|number} groupId - ID группы.
     * @param {jQuery} $accordionRow - jQuery-объект строки аккордеона.
     */
    _loadStudentsIfNeeded(groupId, $accordionRow) {
        // Если данные уже загружены, просто выходим.
        if ($accordionRow.data('loaded')) return;

        const $content = $accordionRow.find('.students-accordion-content');
        $content.html('<p class="description">Загрузка...</p>'); // Показываем индикатор загрузки

        $.post(fs_lms_vars.ajaxurl, {
            action:   fs_lms_vars.ajax_actions.getStudentsByGroup,
            group_id: groupId,
            security: fs_lms_vars.nonces.manager, // Nonce для защиты от CSRF
        })
            .done((res) => {
                if (!res.success) {
                    $content.html('<p class="description">Ошибка загрузки.</p>');
                    return;
                }

                const students = res.data;

                if (!students.length) {
                    // Обработка пустого состояния (Edge case: в группе еще нет учеников)
                    $content.html('<p class="description"><span class="dashicons dashicons-groups"></span> Ученики ещё не добавлены</p>');
                } else {
                    // РЕНДЕРИНГ СПИСКА С ЗАЩИТОЙ ОТ XSS:
                    // Мы проходим по массиву учеников и создаем HTML-строку.
                    // ВАЖНО: имя ученика (s.name) может содержать опасные символы (например, <script>).
                    // Чтобы предотвратить XSS-атаку, мы создаем временный span,
                    // устанавливаем в него текст через .text() (что автоматически экранирует HTML),
                    // а затем забираем безопасный HTML через .html().
                    const items = students.map(s => `<li>${ $('<span>').text(s.name).html() }</li>`).join('');
                    $content.html(`<ul class="students-list">${items}</ul>`);
                }

                // Помечаем строку как загруженную, чтобы не делать повторных запросов
                $accordionRow.data('loaded', true);
            })
            .fail(() => {
                // Обработка сетевых ошибок (таймаут, ошибка 500 и т.д.)
                $content.html('<p class="description">Ошибка загрузки.</p>');
            });
    }
};