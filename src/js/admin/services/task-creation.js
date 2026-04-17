import '../_types.js';
import { TaskCreationModal } from '../components/task-creation-modal.js';

const $ = jQuery;

export const TaskCreation = {
    _submitting: false,

    init() {
        TaskCreationModal.init();

        TaskCreationModal.onOpen(() => this.loadTaskTypes());
        TaskCreationModal.onTermChange((slug) => this.loadBoilerplates(slug));
        TaskCreationModal.onSubmit((data) => this.createTask(data));
    },

    loadTaskTypes() {
        TaskCreationModal.setTerms('<option value="">Загрузка...</option>');

        $.get(fs_lms_vars.ajaxurl, {
            action:      fs_lms_vars.ajax_actions.getTaskTypes,
            subject_key: fs_lms_task_data.subject_key,
            nonce:       fs_lms_task_data.nonce,
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

        $.get(fs_lms_vars.ajaxurl, {
            action:      fs_lms_vars.ajax_actions.getTaskBoilerplates,
            subject_key: fs_lms_task_data.subject_key,
            term_slug:   termSlug,
            nonce:       fs_lms_task_data.nonce,
        }).done((res) => {
            let html = '<option value="">-- Без шаблона --</option>';
            if (res.success && Array.isArray(res.data)) {
                res.data.forEach(bp => {
                    html += `<option value="${bp.uid}">${bp.title}</option>`;
                });
            }
            TaskCreationModal.setBoilerplates(html);
        });
    },

    createTask(data) {
        if (this._submitting) return;

        const subject_key = typeof fs_lms_task_data !== 'undefined' ? fs_lms_task_data.subject_key : '';

        if (!data.termId || !subject_key || !data.title) {
            alert('Пожалуйста, заполните все обязательные поля (Номер, Предмет, Заголовок).');
            return;
        }

        this._submitting = true;
        TaskCreationModal.setSubmitState(true);

        $.post(fs_lms_vars.ajaxurl, {
            action:          fs_lms_vars.ajax_actions.createTask,
            nonce:           fs_lms_task_data.nonce,
            subject_key:     subject_key,
            term_id:         data.termId,
            boilerplate_uid: data.boilerplateUid,
            title:           data.title,
        })
        .done((res) => {
            if (res.success) {
                window.open(res.data.redirect, '_blank');
                TaskCreationModal.close();
                TaskCreationModal.setSubmitState(false);
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
