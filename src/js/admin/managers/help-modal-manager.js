import { HelpModal } from '../modals/help-modal.js';

const $ = jQuery;

export const HelpModalManager = {
    init() {
        HelpModal.init();
        this._bindEvents();
    },

    _bindEvents() {
        $(document).on('click', '.js-copy-target', (e) => this._handleCopy(e));
    },

    _handleCopy(e) {
        const $urlElement = $(e.currentTarget);
        const textToCopy = $urlElement.text().trim();
        const $container = $urlElement.closest('.fs-lms-redirect-box');
        const $subtext = $container.find('.fs-lms-redirect-box__subtext');

        const originalSubtext = $subtext.data('original-text') || $subtext.text();
        if (!$subtext.data('original-text')) {
            $subtext.data('original-text', originalSubtext);
        }

        navigator.clipboard.writeText(textToCopy).then(() => {
            $urlElement.addClass('is-copied');
            $subtext
                .text('✓ Ссылка успешно скопирована в буфер обмена!')
                .css('color', '#005c12');

            setTimeout(() => {
                $urlElement.removeClass('is-copied');
                $subtext
                    .text(originalSubtext)
                    .css('color', '');
            }, 2000);
        }).catch(err => {
            console.error('Не удалось скопировать текст: ', err);
        });
    },
};
