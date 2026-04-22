import '../_types.js';
import { showNotice } from '../modules/utils.js';

const $ = jQuery;

export const RequiredTaxGuard = {
    init() {
        const required = typeof fs_lms_task_data !== 'undefined'
            ? (fs_lms_task_data.required_taxonomies || [])
            : [];

        if (!required.length || !$('#publish').length) return;

        $('#publish').on('click.fs-required', (e) => {
            const missing = required.filter(slug => !this._hasValue(slug));
            if (!missing.length) return;

            e.preventDefault();

            $('#publish').removeClass('disabled');
            $('.spinner').removeClass('is-active');

            const $container = $('.wrap');

            showNotice(
                'Заполните все обязательные таксономии перед публикацией',
                'error',
                $container
            );

            $('html, body').animate({ scrollTop: 0 }, 'fast');
        });
    },

    _hasValue(slug) {
        const $select = $(`select[name="tax_input[${slug}][]"]`);
        if ($select.length) {
            return !!$select.val();
        }

        return $(`input[name="tax_input[${slug}][]"]:checked`)
            .filter((_, el) => !!el.value)
            .length > 0;
    },
};
