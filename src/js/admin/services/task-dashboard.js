import '../_types.js';

const $ = jQuery;

export const TaskFilter = {
    init() {
        this._initNumberFilter();
        this._initTableSort();
    },

    _initNumberFilter() {
        const $wrapper = $('.task-dashboard-wrapper');
        if (!$wrapper.length) return;

        const $container = $('#fs-task-table-container');

        $wrapper.on('change', '#fs-task-number-filter', (e) => {
            const termId = $(e.currentTarget).val();

            if (!termId) {
                $container.empty();
                return;
            }

            $container.html('<p>Загрузка заданий...</p>');

            $.post(
                fs_lms_vars.ajaxurl,
                {
                    action:      fs_lms_vars.ajax_actions.getTasksByNumber,
                    security:    fs_lms_vars.subject_nonce,
                    subject_key: fs_lms_task_data.subject_key,
                    term_id:     termId,
                },
                (response) => {
                    if (response.success) {
                        $container.html(response.data.html);
                    } else {
                        $container.html('<p>Ошибка загрузки.</p>');
                    }
                }
            );
        });
    },

    _initTableSort() {
        $('#fs-task-table-container').on('click', 'th.sortable', (e) => {
            const $th    = $(e.currentTarget);
            const $table = $th.closest('table');
            const col    = $th.index();
            const isAsc  = $th.hasClass('sort-asc');

            $table.find('thead th').removeClass('sort-asc sort-desc');
            $th.addClass(isAsc ? 'sort-desc' : 'sort-asc');

            const $tbody = $table.find('tbody');
            const $rows  = $tbody.find('tr').filter((_, tr) => !$(tr).find('[colspan]').length).toArray();

            $rows.sort((a, b) => {
                const aVal = $(a).find('td').eq(col).data('val') ?? '';
                const bVal = $(b).find('td').eq(col).data('val') ?? '';

                // пустые значения всегда в конец
                if (!aVal && bVal) return 1;
                if (aVal && !bVal) return -1;

                return isAsc
                    ? String(bVal).localeCompare(String(aVal), 'ru', { numeric: true })
                    : String(aVal).localeCompare(String(bVal), 'ru', { numeric: true });
            });

            $tbody.append($rows);
        });
    },
};
