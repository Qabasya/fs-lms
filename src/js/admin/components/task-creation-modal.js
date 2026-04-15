const $ = jQuery;

/**
 * Компонент модального окна создания задания.
 *
 * Отвечает только за UI: открыть/закрыть, показать данные в форме,
 * сообщить сервису о действиях пользователя через callbacks.
 *
 * Точки связи с сервисом (task-creation.js):
 *   TaskCreationModal.onOpen(fn)       — модалка открылась, загрузи типы заданий
 *   TaskCreationModal.onTermChange(fn) — выбран тип, загрузи boilerplate'ы
 *   TaskCreationModal.onSubmit(fn)     — форма отправлена, создай задание
 *
 * @global fsTaskData Данные из WordPress (ajax_url, subject_key, nonce, post_type)
 */
export const TaskCreationModal = {
    _initialized: false,
    _callbacks: { onOpen: null, onTermChange: null, onSubmit: null },

    init() {
        this.$modal = $('#fs-task-modal');
        this.$form  = $('#fs-task-creation-form');
        if (!this.$modal.length || this._initialized) return;
        this._initialized = true;
        this._bindEvents();
    },

    _bindEvents() {
        // Открытие по клику на «Добавить» в списке заданий
        $('body').off('click.fs', '.page-title-action').on('click.fs', '.page-title-action', (e) => {
            const href = $(e.currentTarget).attr('href') || '';
            if (href.includes('post-new.php') && href.includes('post_type=' + fsTaskData.post_type)) {
                e.preventDefault();
                this.open();
            }
        });

        // Закрытие
        this.$modal.off('click.fs').on('click.fs', '.fs-close, .fs-modal-cancel, .fs-modal-close', (e) => {
            e.preventDefault();
            this.close();
        });

        // Смена типа задания — сервис подгружает boilerplate'ы
        $('#fs-modal-term').off('change.fs').on('change.fs', (e) => {
            const termSlug = $(e.target).find('option:selected').data('slug');
            if (typeof this._callbacks.onTermChange === 'function') {
                this._callbacks.onTermChange(termSlug);
            }
        });

        // Отправка формы — сервис создаёт задание
        this.$form.off('submit.fs').on('submit.fs', (e) => {
            e.preventDefault();
            if (typeof this._callbacks.onSubmit === 'function') {
                this._callbacks.onSubmit(this._getFormData());
            }
        });
    },

    // ─── Регистрация callbacks ────────────────────────────────────────────────

    onOpen(fn)       { this._callbacks.onOpen = fn; },
    onTermChange(fn) { this._callbacks.onTermChange = fn; },
    onSubmit(fn)     { this._callbacks.onSubmit = fn; },

    // ─── API для сервиса ──────────────────────────────────────────────────────

    open() {
        this.$modal.show();
        this.$form[0].reset();
        if (typeof this._callbacks.onOpen === 'function') this._callbacks.onOpen();
    },

    close() {
        this.$modal.hide();
    },

    /** Обновляет список типов заданий. */
    setTerms(html) {
        $('#fs-modal-term').html(html).prop('disabled', false);
    },

    /** Обновляет список boilerplate'ов. */
    setBoilerplates(html) {
        $('#fs-modal-boilerplate').html(html).prop('disabled', false);
    },

    /** Блокирует/разблокирует кнопку отправки во время запроса. */
    setSubmitState(loading) {
        $('#fs-modal-submit')
            .prop('disabled', loading)
            .text(loading ? 'Создание...' : 'Продолжить');
    },

    _getFormData() {
        return {
            termId:         $('#fs-modal-term').val(),
            title:          $('#fs-modal-title').val(),
            boilerplateUid: $('#fs-modal-boilerplate').val(),
        };
    },
};