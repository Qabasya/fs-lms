import '../_types.js';

const $ = jQuery;

export const BoilerplateList = {
    init() {
        if (!$('.delete-boilerplate-link').length) {
            return;
        }
        this.bindEvents();
    },

    bindEvents() {
        $('body').on('click', '.delete-boilerplate-link', (e) => {
            e.preventDefault();
            if (confirm('Вы уверены, что хотите удалить этот шаблон?')) {
                this.delete($(e.currentTarget));
            }
        });
    },

    delete($el) {
        const params = new URLSearchParams(window.location.search);
        const data = {
            action: fs_lms_vars.ajax_actions.deleteBoilerplate,
            nonce: $('#nonce').val(),
            uid: $el.data('uid'),
            subject_key: params.get('subject'),
            term_slug: params.get('term'),
        };

        $.post(fs_lms_vars.ajaxurl, data, (response) => {
            if (response.success) {
                $el.closest('tr')
                    .css('background', '#ff8d8d')
                    .fadeOut(400, function () {
                        $(this).remove();
                    });
            } else {
                alert('Ошибка: ' + (response.data?.message || response.data || 'Неизвестная ошибка'));
            }
        });
    },
};