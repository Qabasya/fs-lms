import '../_types.js';
import { showNotice } from '../modules/utils.js';

const $ = jQuery;

export const RequiredTaxGuard = {
    init() {
        const rawRequired = typeof fs_lms_task_data !== 'undefined'
            ? (fs_lms_task_data.required_taxonomies || [])
            : [];
        const formatSlug = (str) => str.replace(/[-_]/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

        const required = rawRequired.map(t => {
            const isObj = typeof t === 'object' && t !== null;
            const slug = isObj ? t.slug : t;
            // Приоритет: name → label → отформатированный слаг
            const name = isObj ? (t.name || t.label || formatSlug(slug)) : formatSlug(slug);
            return { slug, name };
        });

        if (!required.length || !$('#publish').length) return;

        $('#publish').on('click.fs-required', (e) => {
            const missing = required.filter(t => !this._hasValue(t.slug));
            if (!missing.length) return;

            e.preventDefault();

            $('#publish').removeClass('disabled');
            $('.spinner').removeClass('is-active');

            const $container = $('.wrap');
            // Собираем имена недостающих таксономий через запятую
            const missingNames = missing.map(t => t.name).join(', ');

            showNotice(
                `Заполните все обязательные таксономии: ${missingNames}`,
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
