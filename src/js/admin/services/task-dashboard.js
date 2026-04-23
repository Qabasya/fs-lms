import '../_types.js';

const $ = jQuery;

export const TaskFilter = {
    init() {
        this._initNumberFilter();
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
                    action: fs_lms_vars.ajax_actions.getTasksByNumber,
                    security: fs_lms_vars.subject_nonce,
                    subject_key: fs_lms_task_data.subject_key,
                    term_id: termId,
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

}
