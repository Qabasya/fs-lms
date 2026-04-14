const $ = jQuery;

import { TaxonomyModal } from '../components/taxonomy-modal.js';

/**
 * Сервис управления таксономиями предмета.
 *
 * Содержит AJAX-логику для CRUD таксономий.
 * Работает с TaxonomyModal через callback-паттерн:
 *   TaxonomyModal.onSave((data) => this.save(data))
 *
 * Хуки из SubjectController:
 *   wp_ajax_store_taxonomy
 *   wp_ajax_update_taxonomy
 *   wp_ajax_delete_taxonomy
 */
export const Taxonomies = {
    init() {
        if (!$('.js-taxonomy-table').length) return;

        TaxonomyModal.onSave((data) => this.save(data));
        this._bindEvents();
    },

    _bindEvents() {
        // Открытие модалки для создания
        $('.js-add-taxonomy').on('click', (e) => {
            e.preventDefault();
            TaxonomyModal.open('store');
        });

        // Открытие модалки для редактирования
        $('.js-taxonomy-table').on('click', '.js-edit-tax', (e) => {
            e.preventDefault();
            const $row = $(e.currentTarget).closest('tr');
            TaxonomyModal.open('update', {
                slug: $row.data('slug'),
                name: $row.data('name'),
            });
        });

        // Удаление
        $('.js-taxonomy-table').on('click', '.js-delete-tax', (e) => {
            e.preventDefault();
            const $row       = $(e.currentTarget).closest('tr');
            const slug       = $row.data('slug');
            const subjectKey = $('#tax-subject-key').val();

            if (confirm(`Удалить таксономию "${$row.data('name')}"?\nВсе связанные термины будут стёрты.`)) {
                this._ajaxDelete(slug, subjectKey);
            }
        });
    },

    // ─── AJAX ─────────────────────────────────────────────────────────────────

    save(data) {
        if (!data.tax_name || (data.action === 'store' && !data.tax_slug)) {
            alert('Пожалуйста, заполните все поля');
            return;
        }

        TaxonomyModal.setSaveState(true);

        $.post(ajaxurl, {
            action:      data.action === 'store' ? 'store_taxonomy' : 'update_taxonomy',
            security:    fs_lms_vars.security,
            subject_key: data.subject_key,
            tax_slug:    data.tax_slug,
            tax_name:    data.tax_name,
        })
        .done((res) => {
            if (res.success) {
                location.reload();
            } else {
                alert('Ошибка: ' + res.data);
                TaxonomyModal.setSaveState(false);
            }
        })
        .fail(() => {
            alert('Системная ошибка сервера');
            TaxonomyModal.setSaveState(false);
        });
    },

    _ajaxDelete(slug, subjectKey) {
        $.post(ajaxurl, {
            action:      'delete_taxonomy',
            security:    fs_lms_vars.security,
            subject_key: subjectKey,
            tax_slug:    slug,
        })
        .done((res) => {
            if (res.success) location.reload();
            else alert('Ошибка при удалении: ' + res.data);
        })
        .fail(() => alert('Системная ошибка при удалении'));
    },
};