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
        if ($(e.target).closest('.js-delete-group, .row-actions, a, button').length) {
            return;
        }

        const $row = $(e.currentTarget);
        const groupId = $row.data('group-id');
        const $accordionRow = $(`#students-row-${groupId}`);
        const $arrow = $row.find('.accordion-arrow');

        if ($accordionRow.hasClass('hidden')) {
            $accordionRow.removeClass('hidden');
            $arrow.css('transform', 'rotate(90deg)');
            this._loadStudentsIfNeeded(groupId, $accordionRow);
        } else {
            $accordionRow.addClass('hidden');
            $arrow.css('transform', 'rotate(0deg)');
        }
    },

    _loadStudentsIfNeeded(groupId, $accordionRow) {
        if ($accordionRow.data('loaded')) return;

        const $content = $accordionRow.find('.students-accordion-content');
        $content.html('<p class="description">Загрузка...</p>');

        $.post(fs_lms_vars.ajaxurl, {
            action:   fs_lms_vars.ajax_actions.getStudentsByGroup,
            group_id: groupId,
            security: fs_lms_vars.nonces.manager,
        })
        .done((res) => {
            if (!res.success) {
                $content.html('<p class="description">Ошибка загрузки.</p>');
                return;
            }
            const students = res.data;
            if (!students.length) {
                $content.html('<p class="description"><span class="dashicons dashicons-groups"></span> Ученики ещё не добавлены</p>');
            } else {
                const items = students.map(s => `<li>${ $('<span>').text(s.name).html() }</li>`).join('');
                $content.html(`<ul class="students-list">${items}</ul>`);
            }
            $accordionRow.data('loaded', true);
        })
        .fail(() => {
            $content.html('<p class="description">Ошибка загрузки.</p>');
        });
    }
};