import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

/**
 * Утилита для управления модальными окнами инструкций провайдеров.
 * @namespace HelpModal
 */
export const HelpModal = {
    /** @type {JQuery} */
    $modal: null,
    _initialized: false,

    init() {
        if (this._initialized) return;

        this.$modal = $('#fs-lms-help-modal');
        if (!this.$modal.length) return;

        this._initialized = true;
        this._bindEvents();
    },

    /**
     * Переключает контент под конкретного провайдера и открывает модалку.
     * @param {string} provider Идентификатор ('google', 'vk', 'github')
     */
    open(provider) {
        if (!provider) return;

        // Прячем старый контент и показываем нужный
        this.$modal.find('.fs-lms-help-content').addClass('hidden');
        const $targetContent = this.$modal.find(`.fs-lms-help-content[data-provider="${provider}"]`);

        if ($targetContent.length) {
            $targetContent.removeClass('hidden');
            openModal(this.$modal);

            // Вешаем ESC с уникальным неймспейсом
            bindEsc('help_modal', () => { this.close(); });
        }
    },

    /**
     * Закрывает модальное окно
     */
    close() {
        closeModal(this.$modal);
        unbindEsc('help_modal');
    },

    /**
     * Внутренние обработчики кликов для закрытия и триггеров
     * @private
     */
    _bindEvents() {
        $(document).on('click', '.js-open-help-modal', (e) => {
            e.preventDefault();
            const provider = $(e.currentTarget).data('provider');
            this.open(provider);
        });

        this.$modal.find('.fs-lms-modal-close, .fs-lms-modal-backdrop').on('click', () => {
            this.close();
        });
    }


};
