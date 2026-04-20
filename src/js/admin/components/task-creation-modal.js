import '../_types.js';
import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

/**
 * Модальное окно создания задачи.
 * Управляет формой, состоянием UI и callback-хуками.
 * @namespace TaskCreationModal
 */
export const TaskCreationModal = {
    _initialized: false,
    /** @type {{ onOpen: Function|null, onTermChange: Function|null, onSubmit: Function|null }} */
    _callbacks: { onOpen: null, onTermChange: null, onSubmit: null },

    $modal: null,
    $form: null,
    $termSelect: null,
    $boilerplateSelect: null,
    $submitBtn: null,
    $titleInput: null,

    /** Инициализация: кэширует элементы и привязывает события. */
    init() {
        this.$modal = $('#fs-task-modal');
        this.$form  = $('#fs-task-creation-form');

        if (!this.$modal.length || this._initialized) {
            return;
        }

        this._initialized = true;

        // Кэшируем элементы, которые используются повторно
        this.$termSelect       = $('#fs-modal-term');
        this.$boilerplateSelect = $('#fs-modal-boilerplate');
        this.$submitBtn        = $('#fs-modal-submit');
        this.$titleInput       = $('#fs-modal-title');

        this._bindEvents();
    },

    /** Привязка делегированных и прямых обработчиков. @private */
    _bindEvents() {
        $('body')
            .off('click.fs', '.page-title-action')
            .on('click.fs', '.page-title-action', (e) => {
                const href = $(e.currentTarget).attr('href') || '';
                const postType = fs_lms_task_data?.post_type || '';

                if (href.includes('post-new.php') && href.includes('post_type=' + postType)) {
                    e.preventDefault();
                    this.open();
                }
            });

        this.$modal.on('click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .fs-lms-modal-close, .js-modal-close', (e) => {
            e.preventDefault();
            this.close();
        });

        this.$termSelect.off('change.fs').on('change.fs', () => {
            const termSlug = this.$termSelect.find('option:selected').data('slug');
            if (typeof this._callbacks.onTermChange === 'function') {
                this._callbacks.onTermChange(termSlug);
            }
        });

        this.$form.off('submit.fs').on('submit.fs', (e) => {
            e.preventDefault();
            if (typeof this._callbacks.onSubmit === 'function') {
                this._callbacks.onSubmit(this._getFormData());
            }
        });
    },

    /** @param {Function} fn */
    onOpen(fn)       { this._callbacks.onOpen = fn; },
    /** @param {Function} fn */
    onTermChange(fn) { this._callbacks.onTermChange = fn; },
    /** @param {Function} fn */
    onSubmit(fn)     { this._callbacks.onSubmit = fn; },

    /** Открывает модалку, сбрасывает форму и вызывает коллбек onOpen. */
    open() {
        openModal(this.$modal);
        bindEsc('task_creation', () => this.close());

        if (this.$form && this.$form[0]) {
            this.$form[0].reset();
        }

        if (typeof this._callbacks.onOpen === 'function') {
            this._callbacks.onOpen();
        }
    },

    /** Закрывает модалку. */
    close() {
        closeModal(this.$modal);
        unbindEsc('task_creation');
    },

    /**
     * Обновляет список термов.
     * @param {string} html
     */
    setTerms(html) {
        if (!this.$termSelect.length) return;
        this.$termSelect.html(html).prop('disabled', false);
    },

    /**
     * Обновляет список шаблонов.
     * @param {string} html
     */
    setBoilerplates(html) {
        if (!this.$boilerplateSelect.length) return;
        this.$boilerplateSelect.html(html).prop('disabled', false);
    },

    /**
     * Переключает состояние кнопки отправки.
     * @param {boolean} loading
     */
    setSubmitState(loading) {
        if (!this.$submitBtn.length) return;
        this.$submitBtn
            .prop('disabled', loading)
            .text(loading ? 'Создание...' : 'Продолжить');
    },

    /** Собирает данные формы. @returns {{ termId: string, title: string, boilerplateUid: string }} @private */
    _getFormData() {
        return {
            termId:         this.$termSelect.val(),
            title:          this.$titleInput.val(),
            boilerplateUid: this.$boilerplateSelect.val(),
        };
    },
};