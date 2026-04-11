const $ = jQuery;

export const TaskCreation = {
    init() {
        // Используем стандартные селекторы из твоей верстки
        this.$modal = $('#fs-task-modal');
        this.$form = $('#fs-task-creation-form');

        if (!this.$modal.length) return;

        this.bindEvents();
    },

    bindEvents() {
        const $termSelect = $('#fs-modal-term');
        const $boilerplateSelect = $('#fs-modal-boilerplate');

        // 1. ПЕРЕХВАТ КНОПКИ (Hijacking)
        $('body').on('click', '.page-title-action', (e) => {
            const href = $(e.currentTarget).attr('href') || '';
            if (href.includes('post-new.php') && href.includes('post_type=' + fsTaskData.post_type)) {
                e.preventDefault();
                this.open();
            }
        });

        // 2. ЗАКРЫТИЕ (Исправлено: добавил прямые селекторы кнопок)
        this.$modal.on('click', '.fs-close, .fs-modal-cancel, .fs-modal-close', (e) => {
            e.preventDefault();
            this.close();
        });

        $(window).on('click', (e) => {
            if ($(e.target).is(this.$modal)) this.close();
        });

        // 3. ПОДГРУЗКА ШАБЛОНОВ (Исправлено: передаем slug вместо id, если PHP ждет его)
        $termSelect.on('change', (e) => {
            const $selectedOption = $(e.target).find('option:selected');
            const termSlug = $selectedOption.data('slug'); // Берем слаг из data-атрибута

            $boilerplateSelect.html('<option value="">-- Без шаблона (пустое условие) --</option>').prop('disabled', true);

            if (!termSlug) return;

            $.get(fsTaskData.ajax_url, {
                action: 'get_task_boilerplates',
                subject_key: fsTaskData.subject_key,
                term_slug: termSlug, // Твой PHP в ajaxGetBoilerplates ждет именно term_slug
                nonce: fsTaskData.nonce
            })
                .done((response) => {
                    if (response.success && response.data.length > 0) {
                        response.data.forEach(bp => {
                            $boilerplateSelect.append(`<option value="${bp.uid}">${bp.title}</option>`);
                        });
                        $boilerplateSelect.prop('disabled', false);
                    }
                });
        });

        this.$form.on('submit', (e) => {
            e.preventDefault();
            this.submitForm();
        });
    },

    loadTaskTypes() {
        const $termSelect = $('#fs-modal-term');
        $termSelect.html('<option value="">Загрузка...</option>').prop('disabled', true);

        $.get(fsTaskData.ajax_url, {
            action: 'get_task_types',
            subject_key: fsTaskData.subject_key,
            nonce: fsTaskData.nonce
        })
            .done((response) => {
                $termSelect.html('<option value="">-- Выберите номер --</option>');
                if (response.success) {
                    response.data.forEach(type => {
                        // УБИРАЕМ КЛЮЧ: оставляем только описание (description)
                        // Добавляем data-slug, чтобы потом подгружать шаблоны
                        $termSelect.append(`<option value="${type.id}" data-slug="${type.slug}">${type.description}</option>`);
                    });
                    $termSelect.prop('disabled', false);
                }
            });
    },

    open() {
        this.$modal.show(); // Можно заменить на fadeIn(200)
        this.$form[0].reset();
        this.loadTaskTypes();
    },

    close() {
        this.$modal.hide(); // Можно заменить на fadeOut(200)
    },

    submitForm() {
        // ... старая логика отправки ...
        const $submitBtn = this.$form.find('button[type="submit"]');
        $submitBtn.prop('disabled', true).text('Создание...');

        const data = {
            action: 'create_task',
            nonce: fsTaskData.nonce,
            subject_key: fsTaskData.subject_key,
            term_id: $('#fs-modal-term').val(),
            boilerplate_uid: $('#fs-modal-boilerplate').val(),
            title: $('#fs-modal-title').val()
        };

        $.post(fsTaskData.ajax_url, data)
            .done(res => res.success ? window.location.href = res.data.redirect : alert(res.data))
            .always(() => $submitBtn.prop('disabled', false).text('Продолжить'));
    }
};