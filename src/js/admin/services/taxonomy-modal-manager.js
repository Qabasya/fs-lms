import '../_types.js';
import { TaxonomyModal } from '../components/taxonomy-modal.js';
import { ConfirmModal } from '../components/confirm-modal.js';
import { showNotice, fadeDeleteRow } from '../modules/utils.js';

const $ = jQuery;

export const TaxonomyModalManager = {
    init() {
        if (!$('.js-taxonomy-table').length) return;

        TaxonomyModal.onSave((data) => this._handleSave(data));
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
                slug:        $row.data('slug'),
                name:        $row.data('name'),
                display:     $row.data('display'),
                is_required: $row.data('required') === 1,
            });
        });

        $('.js-taxonomy-table').on('click', '.js-delete-tax', (e) => {
            e.preventDefault();
            const $row = $(e.currentTarget).closest('tr');
            const slug = $row.data('slug');
            const subject_key = $('#tax-subject-key').val();
            const taxName = $row.data('name');

            ConfirmModal.confirm({
                title: 'Удаление таксономии',
                message: `Удалить таксономию «${taxName}»?\nВсе связанные термины будут безвозвратно стёрты.`,
                confirmText: 'Удалить',
                cancelText: 'Отмена',
            }).then(() => {
                this._doDelete(slug, subject_key, $row);
            }).catch(() => {});
        });
    },

    _handleSave(data) {
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
            is_required:  data.is_required,
        })
            .done((res) => {
                if (res.success) {
                    location.reload();
                } else if (res.data?.error_code === 'duplicate_slug') {
                    TaxonomyModal.setSlugError(res.data.message);
                    TaxonomyModal.setSaveState(false);
                } else {
                    alert('Ошибка: ' + (res.data?.message || res.data));
                    TaxonomyModal.setSaveState(false);
                }
            })
            .fail(() => {
                alert('Системная ошибка сервера');
                TaxonomyModal.setSaveState(false);
            });
    },

    _doDelete(slug, subject_key, $row) {
        $.post(fs_lms_vars.ajaxurl, {
            action:      fs_lms_vars.ajax_actions.deleteTaxonomy,
            security:    fs_lms_vars.subject_nonce,
            subject_key: subject_key,
            tax_slug:    slug,
        })
            .done((res) => {
                if (res.success) {
                    if ($row?.length) {
                        fadeDeleteRow($row, () => {
                            showNotice('Таксономия удалена', 'success', $('.js-taxonomy-table'));
                        });
                    } else {
                        location.reload();
                    }
                } else {
                    alert('Ошибка при удалении: ' + res.data);
                }
            })
            .fail(() => {
                alert('Системная ошибка при удалении');
            });
    },
};
