import '../_types.js';
import { TaxonomyModal } from '../modals/taxonomy-modal.js';
import { ConfirmModal } from '../modals/confirm-modal.js';
import { showNotice, fadeDeleteRow, showModalError } from '../modules/utils.js';

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
        if ( ! data.tax_name ) {
            showNotice('Пожалуйста, заполните название', 'error', TaxonomyModal.$modal.find( '.fs-lms-modal-body' ));
            return;
        }

        TaxonomyModal.setSaveState(true);

        const isStore = data.action === 'store';
        const postData = {
            action:       isStore ? fs_lms_vars.ajax_actions.storeTaxonomy : fs_lms_vars.ajax_actions.updateTaxonomy,
            security:     fs_lms_vars.nonces.subject,
            subject_key:  data.subject_key,
            tax_name:     data.tax_name,
            display_type: data.display_type,
            is_required:  data.is_required,
        };
        if ( ! isStore ) { postData.tax_slug = data.tax_slug; }

        $.post(fs_lms_vars.ajaxurl, postData)
            .done((res) => {
                if (res.success) {
                    location.reload();
                } else {
                    showNotice('Ошибка: ' + (res.data?.message || res.data), 'error', TaxonomyModal.$modal.find( '.fs-lms-modal-body' ));
                    TaxonomyModal.setSaveState(false);
                }
            })
            .fail(() => {
                showNotice('Системная ошибка сервера', 'error', TaxonomyModal.$modal.find( '.fs-lms-modal-body' ));
                TaxonomyModal.setSaveState(false);
            });
    },

    _doDelete(slug, subject_key, $row) {
        $.post(fs_lms_vars.ajaxurl, {
            action:      fs_lms_vars.ajax_actions.deleteTaxonomy,
            security:    fs_lms_vars.nonces.subject,
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
                    showNotice('Ошибка при удалении: ' + res.data, 'error', $('.js-taxonomy-table'));
                }
            })
            .fail(() => {
                showNotice('Системная ошибка при удалении', 'error', $('.js-taxonomy-table'));
            });
    },
};
