import {Utils} from '../modules/utils.js';

export const Subjects = {
    init() {
        this.bindEvents();
    },

    bindEvents() {
        const $ = jQuery;

        // Создание
        $('#fs-add-subject-form').on('submit', (e) => this.handleSave(e));

        // Быстрое редактирование и удаление (через делегирование)
        $(document).on('click', '.open-quick-edit', (e) => this.handleQuickEdit(e));
        $(document).on('click', '.delete-subject', (e) => this.handleDelete(e));
    },

    handleSave(e) {
        e.preventDefault();
        const $form = jQuery(e.target);
        const $btn = $form.find('.button-primary');

        Utils.toggleButton($btn, true, 'Сохранение...');

        jQuery.post(ajaxurl, $form.serialize() + '&action=fs_store_subject', (res) => {
            if (res.success) location.reload();
            else {
                alert(res.data || 'Ошибка сохранения');
                Utils.toggleButton($btn, false);
            }
        }).fail(Utils.apiError);
    },

    handleQuickEdit(e) {
        e.preventDefault();
        const $btn = jQuery(e.target);
        const data = $btn.data();
        const $row = $btn.closest('tr');

        const $editRow = jQuery('#fs-quick-edit-row').clone().show();
        $editRow.find('input[name="name"]').val(data.name);
        $editRow.find('input[name="tasks_count"]').val(data.count);
        $editRow.find('input[name="key"]').val(data.key);

        $row.hide().after($editRow);

        // Отмена
        $editRow.find('.cancel').on('click', () => {
            $editRow.remove();
            $row.show();
        });

        // Сохранение в Quick Edit
        $editRow.find('#fs-quick-edit-form').on('submit', (event) => {
            event.preventDefault();
            const $saveBtn = $editRow.find('.save');
            Utils.toggleButton($saveBtn, true, '...');

            jQuery.post(ajaxurl, jQuery(event.target).serialize() + '&action=fs_update_subject', (res) => {
                if (res.success) location.reload();
                else alert('Ошибка');
            }).fail(Utils.apiError);
        });
    },

    handleDelete(e) {
        e.preventDefault();
        const $btn = jQuery(e.target);
        const key = $btn.data('key');
        const $row = $btn.closest('tr');
        const name = $row.find('strong a').text().trim();

        if (!confirm('Вы уверены, что хотите удалить предмет "' + name + '"? \nЭто также безвозвратно удалит связанные типы записей.')) return;

        Utils.toggleButton($btn, true, '...');

        jQuery.post(ajaxurl, {
            action: 'fs_delete_subject',
            key: key,
            security: jQuery('#fs-quick-edit-form [name="security"]').val()
        }, (res) => {
            if (res.success) {
                $row.fadeOut(400, () => {
                    $row.remove();
                    if (jQuery('#tab-1 table.wp-list-table tbody').find('tr').length === 0) location.reload();
                });
            }
        });
    }
};