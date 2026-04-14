/**
 * Логика модального окна создания заданий
 *
 * Отвечает за:
 * - Открытие/закрытие модального окна создания задания
 * - Загрузку списка номеров заданий (типов) через AJAX
 * - Загрузку списка типовых условий (boilerplate) при выборе номера
 * - Отправку формы и создание задания через AJAX
 *
 * @requires jQuery
 * @global fsTaskData Объект с данными из WordPress (ajax_url, subject_key, nonce, post_type)
 */
const $ = jQuery;

export const TaskCreation = {
    /**
     * Инициализация модуля
     */
    init() {
        this.$modal = $('#fs-task-modal');
        this.$form  = $('#fs-task-creation-form');

        // Предотвращение повторной инициализации
        if (!this.$modal.length || this._initialized) return;
        this._initialized = true;

        this.bindEvents();
    },

    /**
     * Привязка обработчиков событий
     */
    bindEvents() {
        const $termSelect        = $('#fs-modal-term');
        const $boilerplateSelect = $('#fs-modal-boilerplate');

        // 1. Открытие модалки при клике на кнопку "Добавить новое"
        $('body').off('click.fs', '.page-title-action').on('click.fs', '.page-title-action', (e) => {
            const href = $(e.currentTarget).attr('href') || '';
            // Проверяем, что это кнопка создания поста для нужного CPT
            if (href.includes('post-new.php') && href.includes('post_type=' + fsTaskData.post_type)) {
                e.preventDefault();
                this.open();
            }
        });

        // 2. Закрытие модального окна
        this.$modal.off('click.fs').on('click.fs', '.fs-close, .fs-modal-cancel, .fs-modal-close', (e) => {
            e.preventDefault();
            this.close();
        });

        // 3. Подгрузка списка boilerplate при выборе номера задания
        $termSelect.off('change.fs').on('change.fs', (e) => {
            const $selectedOption = $(e.target).find('option:selected');
            const termSlug        = $selectedOption.data('slug');

            // Показываем индикатор загрузки
            $boilerplateSelect.html('<option value="">Загрузка...</option>').prop('disabled', true);

            if (!termSlug) {
                $boilerplateSelect.html('<option value="">-- Сначала выберите номер --</option>');
                return;
            }

            // AJAX-запрос на получение списка boilerplate
            $.get(fsTaskData.ajax_url, {
                action:      'get_task_boilerplates',
                subject_key: fsTaskData.subject_key,
                term_slug:   termSlug,
                nonce:       fsTaskData.nonce
            }).done((response) => {
                let html = '<option value="">-- Без шаблона (пусто) --</option>';
                if (response.success && Array.isArray(response.data)) {
                    response.data.forEach(bp => {
                        html += `<option value="${bp.uid}">${bp.title}</option>`;
                    });
                    $boilerplateSelect.prop('disabled', false);
                }
                $boilerplateSelect.html(html);
            });
        });

        // 4. Отправка формы
        this.$form.off('submit.fs').on('submit.fs', (e) => {
            e.preventDefault();
            this.submitForm();
        });
    },

    /**
     * Загрузка списка типов заданий (номеров) для выбранного предмета
     */
    loadTaskTypes() {
        const $termSelect = $('#fs-modal-term');
        $termSelect.html('<option value="">Загрузка...</option>').prop('disabled', true);

        $.get(fsTaskData.ajax_url, {
            action:      'get_task_types',
            subject_key: fsTaskData.subject_key,
            nonce:       fsTaskData.nonce
        }).done((response) => {
            let html = '<option value="">-- Выберите номер --</option>';
            if (response.success) {
                response.data.forEach(type => {
                    html += `<option value="${type.id}" data-slug="${type.slug}">${type.description}</option>`;
                });
                $termSelect.prop('disabled', false);
            }
            $termSelect.html(html);
        });
    },

    /**
     * Открытие модального окна
     */
    open() {
        this.$modal.show();
        this.$form[0].reset();
        this.loadTaskTypes();
    },

    /**
     * Закрытие модального окна
     */
    close() {
        this.$modal.hide();
        this._submitting = false;
    },

    /**
     * Отправка формы создания задания
     */
    submitForm() {
        // Предотвращение повторной отправки
        if (this._submitting) return;
        this._submitting = true;

        const $submitBtn = $('#fs-modal-submit');
        const termId     = $('#fs-modal-term').val();
        const subjectKey = typeof fsTaskData !== 'undefined' ? fsTaskData.subject_key : '';
        const title      = $('#fs-modal-title').val();

        // Валидация обязательных полей
        if (!termId || !subjectKey || !title) {
            alert('Пожалуйста, заполните все обязательные поля (Номер, Предмет, Заголовок).');
            this._submitting = false;
            return;
        }

        $submitBtn.prop('disabled', true).text('Создание...');

        const data = {
            action:          'create_task',
            nonce:           fsTaskData.nonce,
            subject_key:     subjectKey,
            term_id:         termId,
            boilerplate_uid: $('#fs-modal-boilerplate').val(),
            title:           title
        };

        $.post(fsTaskData.ajax_url, data)
            .done(res => {
                if (res.success) {
                    // Редирект на страницу редактирования созданного задания
                    window.location.href = res.data.redirect;
                } else {
                    alert(res.data);
                    $submitBtn.prop('disabled', false).text('Продолжить');
                }
            })
            .fail(() => {
                alert('Ошибка сервера при создании задания');
                $submitBtn.prop('disabled', false).text('Продолжить');
            })
            .always(() => {
                this._submitting = false;
            });
    }
};