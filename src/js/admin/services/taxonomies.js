import '../_types.js';
import { TaxonomyModal } from '../components/taxonomy-modal.js';

const $ = jQuery;

export const Taxonomies = {
    init() {
        if (!$('.js-taxonomy-table').length) return;

        TaxonomyModal.onSave((data) => this.save(data));
        this._bindEvents();
    },

    _bindEvents() {
        $('.js-add-taxonomy').on('click', (e) => {
            e.preventDefault();
            TaxonomyModal.open('store');
        });

        $('.js-taxonomy-table').on('click', '.js-edit-tax', (e) => {
            e.preventDefault();
            const $row = $(e.currentTarget).closest('tr');
            TaxonomyModal.open('update', {
                slug: $row.data('slug'),
                name: $row.data('name'),
                display: $row.data('display'),
            });
        });

        $('.js-taxonomy-table').on('click', '.js-delete-tax', (e) => {
            e.preventDefault();
            const $row       = $(e.currentTarget).closest('tr');
            const slug       = $row.data('slug');
            const subject_key = $('#tax-subject-key').val();

            if (confirm(`Удалить таксономию "${$row.data('name')}"?\nВсе связанные термины будут стёрты.`)) {
                this._ajaxDelete(slug, subject_key);
            }
        });
    },

    save(data) {
        if (!data.tax_name || (data.action === 'store' && !data.tax_slug)) {
            alert('Пожалуйста, заполните все поля');
            return;
        }

        TaxonomyModal.setSaveState(true);

        $.post(fs_lms_vars.ajaxurl, {
            action:       data.action === 'store' ? fs_lms_vars.ajax_actions.storeTaxonomy : fs_lms_vars.ajax_actions.updateTaxonomy,
            security:     fs_lms_vars.subject_nonce,
            subject_key:  data.subject_key,
            tax_slug:     data.tax_slug,
            tax_name:     data.tax_name,
            display_type: data.display_type,
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

    _ajaxDelete(slug, subject_key) {
        $.post(fs_lms_vars.ajaxurl, {
            action:      fs_lms_vars.ajax_actions.deleteTaxonomy,
            security:    fs_lms_vars.subject_nonce,
            subject_key: subject_key,
            tax_slug:    slug,
        })
        .done((res) => {
            if (res.success) location.reload();
            else alert('Ошибка при удалении: ' + res.data);
        })
        .fail(() => alert('Системная ошибка при удалении'));
    },
};
