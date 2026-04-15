const $ = jQuery;

import { TaskCreationModal } from '../components/task-creation-modal.js';

/**
 * Сервис создания заданий.
 *
 * Содержит всю AJAX-логику: загрузку типов заданий, boilerplate'ов
 * и отправку запроса на создание поста. Работает через API компонента
 * TaskCreationModal, не трогая DOM напрямую.
 *
 * @global fsTaskData Данные из WordPress (ajax_url, subject_key, nonce, post_type)
 */
export const TaskCreation = {
    _submitting: false,

    init() {
        // Компонент уже инициализирован через UI.init(), вызов повторный безопасен
        TaskCreationModal.init();

        TaskCreationModal.onOpen(() => this.loadTaskTypes());
        TaskCreationModal.onTermChange((slug) => this.loadBoilerplates(slug));
        TaskCreationModal.onSubmit((data) => this.createTask(data));
    },

    // ─── Загрузка данных ──────────────────────────────────────────────────────

    loadTaskTypes() {
        TaskCreationModal.setTerms('<option value="">Загрузка...</option>');

        $.get(fsTaskData.ajax_url, {
            action:      'get_task_types',
            subject_key: fsTaskData.subject_key,
            nonce:       fsTaskData.nonce,
        }).done((res) => {
            let html = '<option value="">-- Выберите номер --</option>';
            if (res.success) {
                res.data.forEach(type => {
                    html += `<option value="${type.id}" data-slug="${type.slug}">${type.description}</option>`;
                });
            }
            TaskCreationModal.setTerms(html);
        });
    },

    loadBoilerplates(termSlug) {
        if (!termSlug) {
            TaskCreationModal.setBoilerplates('<option value="">-- Сначала выберите номер --</option>');
            return;
        }

        TaskCreationModal.setBoilerplates('<option value="">Загрузка...</option>');

        $.get(fsTaskData.ajax_url, {
            action:      'get_task_boilerplates',
            subject_key: fsTaskData.subject_key,
            term_slug:   termSlug,
            nonce:       fsTaskData.nonce,
        }).done((res) => {
            let html = '<option value="">-- Без шаблона (пусто) --</option>';
            if (res.success && Array.isArray(res.data)) {
                res.data.forEach(bp => {
                    html += `<option value="${bp.uid}">${bp.title}</option>`;
                });
            }
            TaskCreationModal.setBoilerplates(html);
        });
    },

    // ─── Создание задания ─────────────────────────────────────────────────────

    createTask(data) {
        if (this._submitting) return;

        const subjectKey = typeof fsTaskData !== 'undefined' ? fsTaskData.subject_key : '';

        if (!data.termId || !subjectKey || !data.title) {
            alert('Пожалуйста, заполните все обязательные поля (Номер, Предмет, Заголовок).');
            return;
        }

        this._submitting = true;
        TaskCreationModal.setSubmitState(true);

        $.post(fsTaskData.ajax_url, {
            action:          'create_task',
            nonce:           fsTaskData.nonce,
            subject_key:     subjectKey,
            term_id:         data.termId,
            boilerplate_uid: data.boilerplateUid,
            title:           data.title,
        })
        .done((res) => {
            if (res.success) {
                window.location.href = res.data.redirect;
            } else {
                alert(res.data);
                TaskCreationModal.setSubmitState(false);
            }
        })
        .fail(() => {
            alert('Ошибка сервера при создании задания');
            TaskCreationModal.setSubmitState(false);
        })
        .always(() => {
            this._submitting = false;
        });
    },
};