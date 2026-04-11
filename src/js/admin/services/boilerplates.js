const $ = jQuery; // Этого достаточно, если admin.js делает обертку

export const Boilerplates = {
    init() {
        console.log('Boilerplates service started');
        // Инициализируем события ВСЕГДА, чтобы работала кнопка удаления в списке
        this.bindEvents();
    },

    bindEvents() {
        const $body = $('body');
        const $form = $('#fs-lms-boilerplate-form');

        // Логика для формы (редактор)
        if ($form.length) {
            console.log('Form found, binding save event...');
            $form.on('submit', (e) => {
                e.preventDefault();
                this.save($form);
            });
        }

        // Логика для списка (удаление)
        // Используем делегирование, чтобы кнопка работала всегда
        $body.on('click', '.delete-boilerplate-link', (e) => {
            e.preventDefault();
            if (confirm('Вы уверены, что хотите удалить этот шаблон?')) {
                this.delete($(e.currentTarget));
            }
        });
    },

    save($form) {
        if (typeof tinyMCE !== 'undefined') {
            tinyMCE.triggerSave();
        }

        const $btn = $form.find('input[type="submit"]');
        const originalText = $btn.val();
        const data = $form.serialize();

        $btn.val('Сохранение...').prop('disabled', true);

        $.post(ajaxurl, data)
            .done((response) => {
                if (response.success) {
                    alert('Успешно сохранено!');
                    const urlParams = new URLSearchParams(window.location.search);
                    if (urlParams.get('action') === 'new' && response.data.uid) {
                        urlParams.set('action', 'edit');
                        urlParams.set('uid', response.data.uid);
                        window.location.search = urlParams.toString();
                    }
                } else {
                    alert('Ошибка: ' + (response.data || 'Неизвестная ошибка'));
                }
            })
            .fail(() => alert('Ошибка сервера'))
            .always(() => $btn.val(originalText).prop('disabled', false));
    },

    delete($el) {
        const data = {
            action: 'delete_boilerplate',
            nonce: $('input[name="fs_lms_boilerplate_nonce"]').val(),
            subject_key: new URLSearchParams(window.location.search).get('subject'),
            term_slug:   new URLSearchParams(window.location.search).get('term'),
            uid:         $el.data('uid')
        };

        $.post(ajaxurl, data, (response) => {
            if (response.success) {
                $el.closest('tr').css('background', '#ff8d8d').fadeOut(400, function() {
                    $(this).remove();
                });
            } else {
                alert(response.data);
            }
        });
    }
};