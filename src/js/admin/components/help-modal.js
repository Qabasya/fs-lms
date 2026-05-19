import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

/**
 * Утилита для управления модальными окнами инструкций провайдеров.
 * @namespace HelpModal
 */
export const HelpModal = {
    /** @type {JQuery} */
    $modal: null,

    /**
     * Инициализация модуля: кэширует DOM и вешает общий обработчик на ссылки.
     */
    init() {
        this.$modal = $('#fs-lms-help-modal');
        if (!this.$modal.length) return;

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
        // Клик по ссылкам "Как подключить?" на странице настроек
        $(document).on('click', '.js-open-help-modal', (e) => {
            e.preventDefault();
            const provider = $(e.currentTarget).data('provider');
            this.open(provider);
        });

        // Клик по кнопкам закрытия, крестику или бэкдропу
        this.$modal.find('.fs-lms-modal-close, .fs-lms-modal-backdrop').on('click', () => {
            this.close();
        });

        this.$modal.on('click', '.js-copy-target', (e) => {
            const $urlElement = $(e.currentTarget);
            const textToCopy = $urlElement.text().trim();
            const $container = $urlElement.closest('.fs-lms-redirect-box');
            const $subtext = $container.find('.fs-lms-redirect-box__subtext');

            const originalSubtext = $subtext.data('original-text') || $subtext.text();
            if (!$subtext.data('original-text')) {
                $subtext.data('original-text', originalSubtext);
            }

// Копируем в буфер обмена
            navigator.clipboard.writeText(textToCopy).then(() => {
                // Подсвечиваем ссылку зеленым (опционально, если настроен класс)
                $urlElement.addClass('is-copied');

                // Меняем текст мелкой подсказки снизу на зеленый успех
                $subtext
                    .text('✓ Ссылка успешно скопирована в буфер обмена!')
                    .css('color', '#005c12'); // Нативный зеленый цвет WordPress

                // Возвращаем всё в исходное состояние через 2 секунды
                setTimeout(() => {
                    $urlElement.removeClass('is-copied');
                    $subtext
                        .text(originalSubtext)
                        .css('color', ''); // Возвращаем дефолтный
                }, 2000);
            }).catch(err => {
                console.error('Не удалось скопировать текст: ', err);
            });
        });
    }


};
