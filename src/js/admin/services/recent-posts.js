
/**
 * @requires jQuery - глобальная зависимость WordPress.
 * @requires ../_types.js - глобальные типы данных.
 */

import '../_types.js';

const $ = jQuery;
export const RecentContent = {
    init() {

        // Ищем любой из контейнеров (для задач или статей)
        const $container = $('#fs-recent-tasks-container, #fs-recent-articles-container');


        // Если ни одного контейнера нет, выходим
        if (!$container.length) return;

        // Определяем тип контента по ID контейнера
        const isArticle = $container.attr('id') === 'fs-recent-articles-container';
        const type = isArticle ? 'articles' : 'tasks';

        // Выбираем правильный AJAX action
        const ajaxAction = isArticle
            ? fs_lms_vars.ajax_actions.getRecentArticles
            : fs_lms_vars.ajax_actions.getRecentTasks;

        // Проверка данных (они есть благодаря wp_localize_script)
        if (typeof fs_lms_task_data === 'undefined' || !fs_lms_task_data.subject_key) {
            console.warn(`[Recent${type === 'articles' ? 'Articles' : 'Tasks'}] fs_lms_task_data не найден`);
            return;
        }

        const subjectKey = fs_lms_task_data.subject_key;

        $container.addClass('is-loading').css('opacity', '0.5');

        $.post(fs_lms_vars.ajaxurl, {
            action:      ajaxAction,
            security:    fs_lms_vars.subject_nonce,
            subject_key: subjectKey,
        }, (response) => {
            $container.removeClass('is-loading').css('opacity', '1');

            if (response.success) {
                $container.html(response.data.html);
            } else {
                const msg = response.data?.message || 'Не удалось загрузить данные.';
                $container.html(`<div class="notice notice-error inline"><p>${msg}</p></div>`);
            }
        }).fail((jqXHR, textStatus) => {
            $container.removeClass('is-loading').css('opacity', '1');
            console.error(`[Recent${type}] AJAX Error:`, textStatus, jqXHR.responseText);
            $container.html('<p>Ошибка сервера. Проверьте консоль (F12).</p>');
        });
    },
};