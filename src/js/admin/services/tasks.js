// НейроЗолото
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
                    action: 'fs_get_task_types',
                    subject_key: fsTaskData.subject_key,
                    nonce: fsTaskData.nonce
                }, function (res) {
                    if (res.success && res.data.length > 0) {
                        let msg = "Введите НОМЕР задания (например: 1, 2, 3...):\n";
                        res.data.forEach(t => { msg += `№${t.slug} — ${t.description}\n`; });

                        const userInp = prompt(msg);
                        const selected = res.data.find(t => t.slug == userInp || t.slug == `${fsTaskData.subject_key}_${userInp}`);

                        if (selected) {
                            const title = prompt(`Создаем Задание №${userInp}. Введите заголовок:`);
                            if (title) {
                                $.post(fsTaskData.ajax_url, {
                                    action: 'fs_create_task_action',
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
                action: 'fs_update_term_template',
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
                error: function (xhr) {
                    $spinner.removeClass('is-active').hide();
                    alert('Системная ошибка AJAX. Проверьте консоль.');
                }
            });
        });
    },

    initBoilerplateManager: function ($) {
        const $modal = $('#fs-boilerplate-modal');
        const $container = $modal.find('#boilerplate-fields-container');

        $(document).on('click', '.js-open-boilerplate-modal', (e) => {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const $row = $btn.closest('tr');
            const subjectKey = $('.js-task-manager-table').data('subject');
            const termSlug   = $row.data('task-slug');

            $modal.find('#boilerplate-task-name').text($row.data('task-name'));
            $modal.find('#boilerplate-term-slug').val(termSlug);
            $modal.find('#boilerplate-subject-key').val(subjectKey);

            // Очищаем контейнер и показываем спиннер, чтобы старая textarea не мелькала
            $container.html('<p class="js-loading-text">Загрузка структуры полей...</p>');
            $modal.fadeIn(200);

            // 1. Сначала получаем структуру
            $.get(ajaxurl, {
                action: 'fs_get_template_structure',
                subject_key: subjectKey,
                term_slug: termSlug
            }, (structRes) => {
                $container.empty(); // Убираем надпись "Загрузка..."

                if (structRes.success && structRes.data.fields && structRes.data.fields.length > 0) {
                    // Рисуем столько полей, сколько прислал PHP
                    structRes.data.fields.forEach(field => {
                        $container.append(`
                            <div class="boilerplate-field-group" style="margin-bottom:15px;">
                                <label style="display:block; font-weight:bold; margin-bottom:5px;">${field.label}</label>
                                <textarea class="js-boilerplate-input" data-id="${field.id}" style="width:100%; min-height:150px;"></textarea>
                            </div>
                        `);
                    });
                } else {
                    // Если что-то пошло не так, рисуем дефолт
                    $container.append(`
                        <div class="boilerplate-field-group" style="margin-bottom:15px;">
                            <label style="display:block; font-weight:bold; margin-bottom:5px;">Текст условия (Стандарт):</label>
                            <textarea class="js-boilerplate-input" data-id="task_condition" style="width:100%; min-height:150px;"></textarea>
                        </div>
                    `);
                }

                // 2. ВАЖНО: Загружаем контент ТОЛЬКО ПОСЛЕ того, как append закончил работу
                this.loadBoilerplateContent($, subjectKey, termSlug);

            }).fail(() => {
                $container.html('<p style="color:red;">Ошибка сервера при получении структуры.</p>');
            });
        });

        // Исправленный обработчик сохранения (добавил $saveBtn)
        $modal.on('click', '.js-boilerplate-modal-save', function(e) {
            const $saveBtn = $(this);
            const values = {};

            $container.find('.js-boilerplate-input').each(function() {
                const id = $(this).data('id');
                values[id] = $(this).val();
            });

            const data = {
                action: 'fs_save_task_type_boilerplate',
                nonce: fs_lms_vars.manager_nonce,
                subject_key: $modal.find('#boilerplate-subject-key').val(),
                term_slug: $modal.find('#boilerplate-term-slug').val(),
                text: JSON.stringify(values)
            };

            if (typeof Utils !== 'undefined') Utils.toggleButton($saveBtn, true, 'Сохраняем...');

            $.post(ajaxurl, data, (res) => {
                if (typeof Utils !== 'undefined') Utils.toggleButton($saveBtn, false, 'Сохранить');
                if (res.success) {
                    $modal.fadeOut(200);
                    const $row = $(`.js-task-manager-table tr[data-task-slug="${data.term_slug}"]`);
                    $row.find('.js-success-icon').fadeIn().delay(1000).fadeOut();
                } else {
                    alert('Ошибка сохранения: ' + res.data);
                }
            });
        });

        $modal.on('click', '.js-boilerplate-modal-close', () => $modal.fadeOut(200));
    },

    loadBoilerplateContent: function($, subjectKey, termSlug) {
        $.get(ajaxurl, {
            action: 'fs_get_task_type_boilerplate',
            subject_key: subjectKey,
            term_slug: termSlug,
            nonce: fs_lms_vars.manager_nonce
        }, (res) => {
            const $inputs = $('.js-boilerplate-input');

            if (res.success && res.data.text) {
                let rawData = res.data.text;

                // 1. Если данные пришли в кавычках и со слэшами, чистим их
                // Это исправляет проблему {\"task_19...
                if (typeof rawData === 'string' && rawData.includes('{\\"')) {
                    try {
                        // Убираем экранирование кавычек
                        rawData = rawData.replace(/\\"/g, '"');
                        // Если строка все еще обернута в лишние кавычки по краям - убираем
                        if (rawData.startsWith('"') && rawData.endsWith('"')) {
                            rawData = rawData.substring(1, rawData.length - 1);
                        }
                    } catch (err) {
                        console.error("Ошибка предварительной очистки JSON:", err);
                    }
                }

                try {
                    // 2. Пытаемся распарсить как объект
                    const values = (typeof rawData === 'object') ? rawData : JSON.parse(rawData);

                    if (values && typeof values === 'object') {
                        $inputs.each(function() {
                            const id = $(this).data('id');
                            if (values[id] !== undefined) {
                                $(this).val(values[id]);
                            }
                        });
                    } else {
                        // Если распарсилось, но это не объект (например, просто число)
                        $inputs.first().val(rawData);
                    }
                } catch (e) {
                    // 3. Если это не JSON (старый формат), просто пишем в первое поле
                    $inputs.first().val(res.data.text);
                }
            }
        });
    }
};