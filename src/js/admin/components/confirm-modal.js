const $ = jQuery;

const ConfirmModal = {
    $modal: null,
    $body: null,
    _reject: null,

    init() {
        this.$body = $('body');
        this.$modal = $('#fs-lms-confirm-modal');

        if (!this.$modal.length) {
            console.warn('[ConfirmModal] Модалка не найдена в DOM. Проверьте подключение confirm-modal.php');
        }
    },

    confirm({ title = 'Подтвердите действие', message = '', confirmText = 'Подтвердить', cancelText = 'Отмена' } = {}) {

        this.$modal.find('.fs-lms-modal-title').text(title);
        this.$modal.find('.fs-lms-modal-message').text(message);
        this.$modal.find('.fs-lms-modal-confirm').text(confirmText);
        this.$modal.find('.fs-lms-modal-cancel').text(cancelText);

        this._open();

        return new Promise((resolve, reject) => {

            this._reject = reject; // сохраняем ссылку

            this.$modal.find('.fs-lms-modal-confirm')
                .off('click.confirm')
                .on('click.confirm', () => {
                    this._close();
                    resolve();
                });

            this.$modal.find('.fs-lms-modal-cancel, .fs-lms-modal-close, .fs-lms-modal-backdrop')
                .off('click.confirm')
                .on('click.confirm', () => {
                    this._close();
                    reject();
                });
        });
    },

    _open() {
        const scrollBarWidth = window.innerWidth - document.documentElement.clientWidth;
        document.documentElement.style.setProperty('--scrollbar-width', scrollBarWidth + 'px');

        $('html').addClass('modal-open');
        this.$modal.removeClass('hidden');

        void this.$modal[0].offsetHeight;

        this.$modal.addClass('active');
        this._bindEsc();
    },

    _close() {
        this.$modal.removeClass('active');

        setTimeout(() => {
            this.$modal.addClass('hidden');
            $('html').removeClass('modal-open');
            document.documentElement.style.removeProperty('--scrollbar-width');
            this._unbindEsc();
        }, 200); // Должно совпадать с duration в CSS transition
    },

    _bindEsc() {
        this._escHandler = (e) => {
            if (e.key === 'Escape') {
                this._close();
                if (this._reject) this._reject();
            }
        };

        $(document).on('keydown.confirmModal', this._escHandler);
    },

    _unbindEsc() {
        $(document).off('keydown.confirmModal');
    },

};

export { ConfirmModal };
