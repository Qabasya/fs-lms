import '../_types.js';
import {Utils} from '../modules/utils.js';

export const Tasks = {
    init() {
        const $ = jQuery;

        if ($('.js-task-manager-table').length) {
            this.initTemplateManager($);
        }
    },

    initTemplateManager($) {
        $('.js-task-manager-table').on('change', '.js-change-term-template', function (e) {
            e.stopImmediatePropagation();

            const $select = $(this);
            const $row    = $select.closest('tr');
            const $table  = $select.closest('.js-task-manager-table');

            const requestData = {
                action:   fs_lms_vars.ajax_actions.updateTermTemplate,
                security: fs_lms_vars.subject_nonce,
                term_id:  $row.data('term-id'),
                template: $select.val(),
                key:      $table.data('subject'),
                name:     $row.data('task-name'),
            };

            const $spinner = $row.find('.spinner');
            const $success = $row.find('.js-success-icon');

            $spinner.addClass('is-active').show();
            $success.hide();

            $.ajax({
                url:  fs_lms_vars.ajaxurl,
                type: 'POST',
                data: requestData,
                success(response) {
                    $spinner.removeClass('is-active').hide();
                    if (response.success) {
                        $success.fadeIn().delay(1000).fadeOut();
                    } else {
                        alert('Ошибка: ' + response.data);
                    }
                },
                error() {
                    $spinner.removeClass('is-active').hide();
                    alert('Системная ошибка AJAX. Проверьте консоль.');
                },
            });
        });
    },
};
