const $ = jQuery;

export const GroupsTable = {
    _initialized: false,

    init() {
        if (this._initialized) return;

        // Проверяем, есть ли вообще таблица групп на текущей странице
        if (!$('.groups-table').length) return;

        this._initialized = true;
        this._bindEvents();
    },

    _bindEvents() {
        // Делегированное событие клика по строке группы
        $(document).on('click', '.js-toggle-students', (e) => this._handleToggleAccordion(e));
        this._bindPeriodFilter();
    },

    _bindPeriodFilter() {
        $('#filter-by-period').on('change', function () {
            const url = new URL(window.location.href);
            url.searchParams.set('period_filter', this.value);
            window.location.href = url.toString();
        });
    },

    _handleToggleAccordion(e) {
        // Игнорируем клик, если нажали на кнопку удаления или другие ссылки/кнопки внутри строки
        if ($(e.target).closest('.js-delete-group, .row-actions, a, button').length) {
            return;
        }

        const $row = $(e.currentTarget);
        const groupId = $row.data('group-id');
        const $accordionRow = $(`#students-row-${groupId}`);
        const $arrow = $row.find('.accordion-arrow');

        if ($accordionRow.hasClass('hidden')) {
            // // Опционально: закрываем остальные открытые аккордеоны, чтобы не раздувать таблицу
            // $('.students-accordion-row').addClass('hidden');
            // $('.accordion-arrow').css('transform', 'rotate(0deg)');

            // Открываем текущий
            $accordionRow.removeClass('hidden');
            $arrow.css('transform', 'rotate(90deg)');

            // Задел на будущее: здесь можно будет вызвать подгрузку учеников через AJAX
            // this._loadStudentsIfNeeded(groupId, $accordionRow);
        } else {
            $accordionRow.addClass('hidden');
            $arrow.css('transform', 'rotate(0deg)');
        }
    }
};