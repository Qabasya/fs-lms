/*
Хуки из TaskCreationController
wp_ajax_get_task_types
wp_ajax_create_task
wp_ajax_get_template_structure
wp_ajax_save_task_boilerplate
wp_ajax_get_task_boilerplate
 */

//TODO: модалка слишком длинная выходит для задания 19-21
//TODO: увеличить ширину выпадашки "Визуальный шаблон"
//TODO: нужно куда-то поместить типы заданий и дать возможность редактировать boilerplate каждого

import {Utils} from '../modules/utils.js';

export const Tasks = {
    init: function () {
        const $ = jQuery;

        if (typeof fsTaskData !== 'undefined') {
            this.initQuickCreation($);
        }

        if ($('.js-task-manager-table').length) {
            this.initTemplateManager($);
            this.initBoilerplateManager($);
        }
    },

    initQuickCreation: function ($) {
        $('body').on('click', '.page-title-action', function (e) {
            const href = $(this).attr('href') || '';
            if (href.indexOf('post-new.php') !== -1 && href.indexOf('post_type=' + fsTaskData.post_type) !== -1) {
                e.preventDefault();

                $.get(fsTaskData.ajax_url, {
                    action: 'get_task_types',
                    subject_key: fsTaskData.subject_key,
                    nonce: fsTaskData.nonce
                }, function (res) {
                    if (res.success && res.data.length > 0) {
                        let msg = "Введите НОМЕР задания (например: 1, 2, 3...):\n";
                        res.data.forEach(t => {
                            msg += `№${t.slug} — ${t.description}\n`;
                        });

                        const userInp = prompt(msg);
                        const selected = res.data.find(t => t.slug == userInp || t.slug == `${fsTaskData.subject_key}_${userInp}`);

                        if (selected) {
                            const title = prompt(`Создаем Задание №${userInp}. Введите заголовок:`);
                            if (title) {
                                $.post(fsTaskData.ajax_url, {
                                    action: 'create_task',
                                    nonce: fsTaskData.nonce,
                                    subject_key: fsTaskData.subject_key,
                                    term_id: selected.id,
                                    title: title
                                }, function (final) {
                                    if (final.success) window.location.href = final.data.redirect;
                                    else alert('Ошибка: ' + final.data);
                                });
                            }
                        } else {
                            alert('Задание с таким номером не найдено!');
                        }
                    }
                });
            }
        });
    },

    initTemplateManager: function ($) {
        $('.js-task-manager-table').on('change', '.js-change-term-template', function () {
            const $select = $(this);
            const $row = $select.closest('tr');

            const sendData = {
                action: 'update_term_template',
                security: fs_lms_vars.security,
                term_id: $row.data('term-id'),
                template: $select.val(),
                key: '',
                name: ''
            };

            const $spinner = $row.find('.spinner');
            const $success = $row.find('.js-success-icon');

            $spinner.addClass('is-active').show();
            $success.hide();

            $.ajax({
                url: fs_lms_vars.ajaxurl,
                type: 'POST',
                data: sendData,
                success: function (response) {
                    $spinner.removeClass('is-active').hide();
                    if (response.success) $success.fadeIn().delay(1000).fadeOut();
                    else alert('Ошибка: ' + response.data);
                },
                error: function () {
                    $spinner.removeClass('is-active').hide();
                    alert('Системная ошибка AJAX. Проверьте консоль.');
                }
            });
        });
    },

    initBoilerplateManager: function ($) {
        const $modal = $('#fs-boilerplate-modal');
        const $container = $modal.find('#boilerplate-fields-container');

        const removeEditors = () => {
            $container.find('.js-boilerplate-editor').each(function () {
                const id = $(this).attr('id');
                if (window.wp && window.wp.editor && window.tinymce && window.tinymce.get(id)) {
                    window.wp.editor.remove(id);
                }
            });
        };

        $(document).on('click', '.js-open-boilerplate-modal', (e) => {
            e.preventDefault();
            const $row = $(e.currentTarget).closest('tr');
            const subjectKey = $('.js-task-manager-table').data('subject');
            const termSlug = $row.data('task-slug');

            $modal.find('#boilerplate-task-name').text($row.data('task-name'));
            $modal.find('#boilerplate-term-slug').val(termSlug);
            $modal.find('#boilerplate-subject-key').val(subjectKey);

            $container.html('<p>Загрузка структуры полей...</p>');

            $modal.fadeIn(200, () => {
                $.get(ajaxurl, {
                    action: 'get_template_structure',
                    subject_key: subjectKey,
                    term_slug: termSlug,
                    nonce: fs_lms_vars.manager_nonce
                }, (structRes) => {
                    $container.empty();

                    if (structRes.success && structRes.data.fields) {
                        structRes.data.fields.forEach(field => {
                            $container.append(`
                                <div class="boilerplate-field-group" style="margin-bottom:15px;">
                                    <label style="display:block; font-weight:bold; margin-bottom:5px;">${field.label}</label>
                                    ${field.html}
                                </div>
                            `);
                        });
                        setTimeout(() => this.loadBoilerplateContent($, subjectKey, termSlug), 150);
                    }
                });
            });
        });

        $modal.on('click', '.js-boilerplate-modal-save', function () {
            const $saveBtn = $(this);
            if (typeof Utils !== 'undefined') Utils.toggleButton($saveBtn, true, 'Сохранение...');

            // Собираем объект со всеми полями
            const values = {};
            $container.find('.js-boilerplate-editor').each(function () {
                const id = $(this).attr('id');
                const key = $(this).data('field-key');

                if (window.tinymce && window.tinymce.get(id)) {
                    window.tinymce.get(id).save();
                    values[key] = window.tinymce.get(id).getContent();
                } else {
                    values[key] = $(this).val();
                }
            });

            $.post(ajaxurl, {
                action: 'save_task_boilerplate',
                nonce: fs_lms_vars.manager_nonce,
                subject_key: $modal.find('#boilerplate-subject-key').val(),
                term_slug: $modal.find('#boilerplate-term-slug').val(),
                text: JSON.stringify(values) // Сохраняем как JSON-строку
            }, (res) => {
                if (typeof Utils !== 'undefined') Utils.toggleButton($saveBtn, false, 'Сохранить');
                if (res.success) {
                    removeEditors();
                    $modal.fadeOut(200);
                } else {
                    alert('Ошибка: ' + (res.data || 'неизвестно'));
                }
            });
        });

        $modal.on('click', '.js-boilerplate-modal-close', () => {
            removeEditors();
            $modal.fadeOut(200);
        });
    },

    loadBoilerplateContent: function ($, subjectKey, termSlug) {
        $.get(ajaxurl, {
            action:      'get_task_boilerplate',
            subject_key: subjectKey,
            term_slug:   termSlug,
            nonce:       fs_lms_vars.manager_nonce
        }, (res) => {
            console.log('Ответ от сервера:', res.data.text);
            let contentValues = {};

            if (res.success && res.data.text) {
                let rawData = res.data.text;

                // Пытаемся распарсить JSON
                try {
                    contentValues = (typeof rawData === 'string' && rawData.trim().startsWith('{'))
                        ? JSON.parse(rawData)
                        : rawData;
                } catch (e) {
                    // Если это не JSON, записываем старый текст в первое доступное поле
                    const firstKey = $('.js-boilerplate-editor').first().data('field-key');
                    if (firstKey) contentValues[firstKey] = rawData;
                }
            }

            $('.js-boilerplate-editor').each(function () {
                const $textarea = $(this);
                const id  = $textarea.attr('id');
                const key = $textarea.data('field-key');

                // Распределяем текст по ключам
                let val = (contentValues && contentValues[key]) ? contentValues[key] : '';

                $textarea.val(val);

                if (window.wp && window.wp.editor) {
                    if (window.tinymce && window.tinymce.get(id)) {
                        window.wp.editor.remove(id);
                    }

                    window.wp.editor.initialize(id, {
                        tinymce: {
                            wpautop: false,
                            forced_root_block: '',
                            entity_encoding: 'raw',
                            paste_as_text: true,
                            setup: function (ed) {
                                ed.on('init', () => {
                                    ed.setContent(val);
                                });
                                ed.on('change input', () => ed.save());
                            }
                        },
                        quicktags: true,
                        mediaButtons: true
                    });
                }
            });
        });
    }
};