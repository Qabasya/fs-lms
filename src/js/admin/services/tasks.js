// НейроГовно
export const Tasks = {
    init: function () {
        const $ = jQuery;

        // 1. Быстрое создание (модалка)
        // Проверяем наличие данных fsTaskData (они есть только в списке постов CPT)
        if (typeof fsTaskData !== 'undefined') {
            this.initQuickCreation($);
        }

        // 2. Менеджер шаблонов (4-я вкладка)
        // Проверяем наличие таблицы и общих переменных плагина
        if ($('.js-task-manager-table').length && typeof fs_lms_vars !== 'undefined') {
            this.initTemplateManager($);
        }
    },

    initQuickCreation: function ($) {
        console.log('FS LMS: Сервис создания задач запущен для:', fsTaskData.subject_key);

        // ВАЖНО: Проверь, чтобы этот селектор соответствовал кнопке "Добавить новое" в WP
        $('body').on('click', '.page-title-action', function (e) {
            const href = $(this).attr('href') || '';

            // Проверка, что кликнули именно по кнопке создания нового поста нужного типа
            if (href.indexOf('post-new.php') !== -1 && href.indexOf('post_type=' + fsTaskData.post_type) !== -1) {
                e.preventDefault();

                $.get(fsTaskData.ajax_url, {
                    action: 'fs_get_task_types',
                    subject_key: fsTaskData.subject_key
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
    // Новая логика для Менеджера заданий (Tab 4)
    initTemplateManager: function ($) {
        console.log('FS LMS: Менеджер шаблонов типов заданий запущен');

        // Вешаем событие на смену значения в выпадающем списке
        $('.js-task-manager-table').on('change', '.js-change-term-template', function () {
            const $select = $(this);
            const $row = $select.closest('tr');
            const $spinner = $row.find('.spinner');
            const $success = $row.find('.js-success-icon');

            // Получаем ID ТЕРМА (номера задания) и выбранный шаблон
            const termId = $row.data('term-id');
            const templateId = $select.val();

            // Визуальная индикация начала загрузки
            $spinner.addClass('is-active').show();
            $success.hide();

            $.ajax({
                url: fs_lms_vars.ajaxurl,
                type: 'POST',
                data: {
                    action: 'fs_update_term_template', // Наш метод в SubjectSettingsCallbacks
                    security: fs_lms_vars.security,    // Наш nonce
                    term_id: termId,                   // ID ТЕРМА
                    template: templateId               // ID ШАБЛОНА
                },
                success: function (response) {
                    $spinner.removeClass('is-active').hide();

                    if (response.success) {
                        // Показываем галочку успеха на 1.5 секунды
                        $success.fadeIn().delay(1500).fadeOut();
                    } else {
                        alert(response.data || 'Ошибка при сохранении');
                    }
                },
                error: function () {
                    $spinner.removeClass('is-active').hide();
                    alert('Системная ошибка AJAX');
                }
            });
        });
    }
};