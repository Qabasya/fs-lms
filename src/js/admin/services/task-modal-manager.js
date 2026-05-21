import '../_types.js';
import {TaskModal} from '../components/task-modal.js';
import {showNotice} from '../modules/utils.js';

const $ = jQuery;

export const TaskModalManager = {
    _submitting: false,

    init() {
        TaskModal.init();

        TaskModal.onOpen(() => this.loadTaskTypes());
        TaskModal.onTermChange((slug) => this.loadBoilerplates(slug));
        TaskModal.onSubmit((data) => this.createTask(data));
    },

    loadTaskTypes() {
        TaskModal.setTerms('<option value="">Загрузка...</option>');

        $.get(fs_lms_vars.ajaxurl, {
            action:      fs_lms_vars.ajax_actions.getTaskTypes,
            subject_key: fs_lms_task_data.subject_key,
            security:    fs_lms_task_data.security,
        }).done((res) => {
            let html = '<option value="">-- Выберите номер --</option>';

            if (res.success) {
                res.data.forEach(type => {
                    html += `<option value="${type.id}" data-slug="${type.slug}">${type.description}</option>`;
                });
            }

            TaskModal.setTerms(html);
        });
    },

    loadBoilerplates(termSlug) {
        if (!termSlug) {
            TaskModal.setBoilerplates('<option value="">-- Сначала выберите номер --</option>');
            return;
        }

        TaskModal.setBoilerplates('<option value="">Загрузка...</option>');

        $.get(fs_lms_vars.ajaxurl, {
            action:      fs_lms_vars.ajax_actions.getTaskBoilerplates,
            subject_key: fs_lms_task_data.subject_key,
            term_slug:   termSlug,
            security:    fs_lms_task_data.security,
        }).done((res) => {
            let html = '<option value="">-- Без шаблона --</option>';

            if (res.success && Array.isArray(res.data)) {
                res.data.forEach(bp => {
                    html += `<option value="${bp.uid}">${bp.title}</option>`;
                });
            }

            TaskModal.setBoilerplates(html);
        });
    },

    createTask(data) {
        if (this._submitting) return;

        const subject_key = typeof fs_lms_task_data !== 'undefined' ? fs_lms_task_data.subject_key : '';

        if (!data.termId) {
            showNotice('Номер задания обязателен для заполнения', 'error', TaskModal.$modal);
            return;
        }

        if (!subject_key || !data.title) {
            showNotice('Заполните все обязательные поля', 'error', TaskModal.$modal);
            return;
        }

        this._submitting = true;
        TaskModal.setSubmitState(true);

        $.post(fs_lms_vars.ajaxurl, {
            action:          fs_lms_vars.ajax_actions.createTask,
            security:        fs_lms_task_data.security,
            subject_key:     subject_key,
            term_id:         data.termId,
            boilerplate_uid: data.boilerplateUid,
            title:           data.title,
        })
            .done((res) => {
                if (res.success) {
                    window.open(res.data.redirect, '_blank');
                    TaskModal.close();
                    TaskModal.setSubmitState(false);
                } else {
                    alert(res.data);
                    TaskModal.setSubmitState(false);
                }
            })
            .fail(() => {
                alert('Ошибка сервера при создании задания');
                TaskModal.setSubmitState(false);
            })
            .always(() => {
                this._submitting = false;
            });
    },
};
