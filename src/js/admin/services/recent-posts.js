
/**
 * @fileoverview Модуль управления таблицей постов (задач) с AJAX-фильтрацией и пагинацией.
 * @description Обеспечивает динамическую загрузку таблицы постов без перезагрузки страницы:
 *              фильтрация по статусам (All/Published/Draft/Trash), пагинация и поиск.
 * @requires jQuery - глобальная зависимость WordPress.
 * @requires ../_types.js - глобальные типы данных.
 */

import '../_types.js';

const $ = jQuery;
export const RecentTasks = {
    init() {
        const $container = $('#fs-recent-tasks-container');
        if (!$container.length) return;


        $container.addClass('is-loading').css('opacity', '0.5');

        $.post(fs_lms_vars.ajaxurl, {
            action:      fs_lms_vars.ajax_actions.getRecentTasks,
            security:    fs_lms_vars.subject_nonce,
            subject_key: fs_lms_task_data.subject_key,
        }, (response) => {
            $container.removeClass('is-loading').css('opacity', '1');

            if (response.success) {
                $container.html(response.data.html);
            } else {
                $container.html('<div class="notice notice-error inline"><p>Не удалось загрузить задачи.</p></div>');
            }
        }).fail(() => {
            $container.removeClass('is-loading').css('opacity', '1');
            $container.html('<p>Ошибка сервера. Попробуйте обновить страницу.</p>');
        });
    },
};