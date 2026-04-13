/*
Хуки из TaskCreationController
wp_ajax_get_task_types
wp_ajax_create_task
wp_ajax_get_template_structure
wp_ajax_save_task_boilerplate
wp_ajax_get_task_boilerplate
 */


import {Utils} from '../modules/utils.js';

// ============================================================
// Объект Tasks — центральная точка управления заданиями в LMS.
//
// Вместо того чтобы разбрасывать функции по всему файлу,
// мы собираем их в один объект. Это называется «паттерн модуля».
// Каждый метод отвечает за свою отдельную часть интерфейса.
// ============================================================
export const Tasks = {

    // ----------------------------------------------------------
    // init() — точка входа. Вызывается один раз при загрузке страницы.
    //
    // Здесь мы проверяем, на какой странице находимся, и запускаем
    // только нужные части кода. Нет смысла инициализировать modal
    // с болванками, если на странице нет таблицы заданий.
    // ----------------------------------------------------------
    init: function () {
        // jQuery передаётся в переменную $, чтобы везде писать $(...) вместо jQuery(...)
        const $ = jQuery;



        // Проверяем наличие таблицы менеджера заданий на странице.
        // .length возвращает количество найденных элементов — если 0, то блок пропускается.
        if ($('.js-task-manager-table').length) {
            this.initTemplateManager($);    // Смена шаблона для типа задания
        }
    },


    // ============================================================
    // МЕНЕДЖЕР ШАБЛОНОВ
    //
    // В таблице заданий у каждой строки есть <select> для выбора
    // шаблона отображения. При смене значения — сразу сохраняем
    // через AJAX, без перезагрузки страницы.
    // Показываем спиннер во время запроса и галочку после успеха.
    // ============================================================
    initTemplateManager: function ($) {
        $('.js-task-manager-table').on('change', '.js-change-term-template', function (e) {
            e.stopImmediatePropagation(); // Защита от двойного срабатывания

            const $select = $(this);
            const $row    = $select.closest('tr');
            const $table  = $select.closest('.js-task-manager-table'); // Находим саму таблицу

            const requestData = {
                action:   'update_term_template',
                security: fs_lms_vars.security,
                term_id:  $row.data('term-id'),
                template: $select.val(),
                key:      $table.data('subject'), // Берем "test_1" из атрибута таблицы
                name:     $row.data('task-name')  // Берем "Задание №1" из строки
            };

            const $spinner = $row.find('.spinner');
            const $success = $row.find('.js-success-icon');

            $spinner.addClass('is-active').show();
            $success.hide();

            $.ajax({
                url:  fs_lms_vars.ajaxurl,
                type: 'POST',
                data: requestData,
                success: function (response) {
                    $spinner.removeClass('is-active').hide();
                    if (response.success) {
                        $success.fadeIn().delay(1000).fadeOut();
                    } else {
                        alert('Ошибка: ' + response.data);
                    }
                },
                error: function () {
                    $spinner.removeClass('is-active').hide();
                    alert('Системная ошибка AJAX. Проверьте консоль.');
                }
            });
        });
    },

};