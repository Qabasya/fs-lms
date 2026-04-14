const $ = jQuery;

/**
 * Компонент модального окна управления таксономиями.
 *
 * Отвечает только за UI: показать/скрыть окно, заполнить поля,
 * сбросить состояние. Бизнес-логика и AJAX — в Taxonomies (services).
 *
 * Точка связи с сервисом — метод onSave(callback):
 *   TaxonomyModal.onSave((data) => Taxonomies.save(data));
 */
export const TaxonomyModal = {
    _onSaveCallback: null,

    init() {
        if (this._initialized) return;
        this.$modal = $('#fs-taxonomy-modal');
        if (!this.$modal.length) return;
        this._initialized = true;
        this._bindEvents();
    },

    _bindEvents() {
        // Закрытие по кнопке «Отмена»
        this.$modal.on('click', '.js-modal-close', (e) => {
            e.preventDefault();
            this.close();
        });

        // Закрытие по клику на оверлей
        this.$modal.on('click', (e) => {
            if ($(e.target).is(this.$modal)) this.close();
        });

        // Сохранение — делегируем в сервис через callback
        this.$modal.on('click', '.js-modal-save', (e) => {
            e.preventDefault();
            if (typeof this._onSaveCallback === 'function') {
                this._onSaveCallback(this._collectFormData());
            }
        });
    },

    /** Регистрирует callback, вызываемый при нажатии «Сохранить». */
    onSave(callback) {
        this._onSaveCallback = callback;
    },

    /**
     * Открывает модалку в нужном режиме.
     *
     * @param {'store'|'update'} action
     * @param {{ slug?: string, name?: string }} data
     */
    open(action, data = {}) {
        const isUpdate = action === 'update';

        $('#tax-action').val(action);
        $('#modal-title').text(isUpdate ? 'Редактировать название' : 'Новая таксономия');

        // Slug нельзя менять при редактировании
        $('#slug-container').toggle(!isUpdate);
        $('#tax-slug').val(isUpdate ? (data.slug ?? '') : '');
        $('#tax-name').val(isUpdate ? (data.name ?? '') : '');

        const displayType = (isUpdate && data.display) ? data.display : 'select';
        this.$modal.find(`input[name="tax_display_type"][value="${displayType}"]`).prop('checked', true);

        this.$modal.fadeIn(200, () => $('#tax-name').trigger('focus'));
    },

    close() {
        this.$modal.fadeOut(200, () => {
            $('#tax-name, #tax-slug').val('');
            $('#tax-action').val('store');
            this.$modal.find('input[name="tax_display_type"][value="select"]').prop('checked', true);
        });
    },

    /** Блокирует/разблокирует кнопку «Сохранить» во время запроса. */
    setSaveState(loading) {
        $('.js-modal-save')
            .prop('disabled', loading)
            .text(loading ? 'Сохранение...' : 'Сохранить');
    },

    _collectFormData() {
        return {
            action:      $('#tax-action').val(),
            subject_key: $('#tax-subject-key').val(),
            tax_slug:    $('#tax-slug').val(),
            tax_name:    $('#tax-name').val(),
            display_type: this.$modal.find('input[name="tax_display_type"]:checked').val()
        };
    },
};