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

        // fsTaskData — объект, который PHP-сторона плагина передаёт в JS
        // через wp_localize_script(). Содержит ajax_url, nonce, post_type и т.д.
        // Если его нет — значит мы не на странице заданий, и быстрое создание не нужно.
        if (typeof fsTaskData !== 'undefined') {
            this.initQuickCreation($);
        }

        // Проверяем наличие таблицы менеджера заданий на странице.
        // .length возвращает количество найденных элементов — если 0, то блок пропускается.
        if ($('.js-task-manager-table').length) {
            this.initTemplateManager($);    // Смена шаблона для типа задания
        }
    },

// // УДАЛИТЬ
//     // ============================================================
//     // БЫСТРОЕ СОЗДАНИЕ ЗАДАНИЯ
//     //
//     // Перехватываем стандартную кнопку WordPress «Добавить новый»
//     // и вместо перехода на страницу редактора — показываем свой
//     // диалог через prompt(). Пользователь выбирает тип задания,
//     // вводит заголовок, и мы сразу создаём запись через AJAX.
//     // ============================================================
//     initQuickCreation: function ($) {
//
//         // Вешаем обработчик клика на body (а не на саму кнопку) — это «делегирование событий».
//         // Нужно потому, что кнопка .page-title-action может появиться на странице позже,
//         // уже после того как JS был инициализирован.
//         $('body').on('click', '.page-title-action', function (e) {
//             const href = $(this).attr('href') || '';
//
//             // Кнопок «Добавить новый» в админке может быть несколько (разные типы постов).
//             // Убеждаемся, что кликнули именно по кнопке нашего типа поста.
//             const isOurPostType = href.includes('post-new.php') && href.includes('post_type=' + fsTaskData.post_type);
//             if (!isOurPostType) return;
//
//             // Отменяем стандартный переход по ссылке — берём управление в свои руки
//             e.preventDefault();
//
//             // Шаг 1: запрашиваем у сервера список существующих типов заданий (таксономия).
//             // $.get — это сокращение для AJAX GET-запроса: «дай мне данные, ничего не меняй».
//             $.get(fsTaskData.ajax_url, {
//                 action:      'get_task_types',       // Название хука на PHP-стороне: wp_ajax_get_task_types
//                 subject_key: fsTaskData.subject_key, // Ключ предмета, например 'math' или 'physics'
//                 nonce:       fsTaskData.nonce        // Одноразовый токен — защита от CSRF-атак
//             }, function (res) {
//
//                 // res.success — стандартное поле ответа WordPress при использовании wp_send_json_success()
//                 if (!res.success || res.data.length === 0) return;
//
//                 // Формируем текст для диалога prompt() — нативного окна браузера с полем ввода
//                 let promptMessage = "Введите НОМЕР задания (например: 1, 2, 3...):\n";
//                 res.data.forEach(taskType => {
//                     promptMessage += `№${taskType.slug} — ${taskType.description}\n`;
//                 });
//
//                 // prompt() возвращает строку с введённым значением или null при отмене
//                 const userInput = prompt(promptMessage);
//                 if (userInput === null) return; // Пользователь нажал «Отмена»
//
//                 // Ищем в массиве тип задания, slug которого совпадает с введённым номером.
//                 // Проверяем два варианта: голый номер ("1") и с префиксом ("math_1").
//                 const selectedType = res.data.find(taskType =>
//                     taskType.slug == userInput ||
//                     taskType.slug == `${fsTaskData.subject_key}_${userInput}`
//                 );
//
//                 if (!selectedType) {
//                     alert('Задание с таким номером не найдено!');
//                     return;
//                 }
//
//                 // Шаг 2: просим ввести заголовок для новой записи
//                 const title = prompt(`Создаем Задание №${userInput}. Введите заголовок:`);
//                 if (!title) return; // Пользователь нажал «Отмена» или оставил поле пустым
//
//                 // Шаг 3: отправляем данные на сервер для создания записи.
//                 // $.post — AJAX POST-запрос: «измени что-то на сервере».
//                 $.post(fsTaskData.ajax_url, {
//                     action:      'create_task',
//                     nonce:       fsTaskData.nonce,
//                     subject_key: fsTaskData.subject_key,
//                     term_id:     selectedType.id, // ID таксономии (типа задания)
//                     title:       title
//                 }, function (response) {
//                     if (response.success) {
//                         // Сервер вернул URL только что созданной записи — переходим туда
//                         window.location.href = response.data.redirect;
//                     } else {
//                         alert('Ошибка: ' + response.data);
//                     }
//                 });
//             });
//         });
//     },


    // ============================================================
    // МЕНЕДЖЕР ШАБЛОНОВ
    //
    // В таблице заданий у каждой строки есть <select> для выбора
    // шаблона отображения. При смене значения — сразу сохраняем
    // через AJAX, без перезагрузки страницы.
    // Показываем спиннер во время запроса и галочку после успеха.
    // ============================================================
    initTemplateManager: function ($) {

        // Используем делегирование: вешаем обработчик на таблицу, а не на каждый select.
        // Так работает даже для строк, добавленных в таблицу динамически.
        $('.js-task-manager-table').on('change', '.js-change-term-template', function () {
            const $select = $(this);
            const $row    = $select.closest('tr'); // Строка таблицы, в которой находится select

            // Собираем данные для отправки на сервер
            const requestData = {
                action:   'update_term_template',
                security: fs_lms_vars.security,
                term_id:  $row.data('term-id'),  // data-term-id="..." из HTML-атрибута строки
                template: $select.val(),          // Выбранное значение в select
                key:      '',
                name:     ''
            };

            // Находим элементы индикации прямо в этой строке таблицы
            const $spinner = $row.find('.spinner');
            const $success = $row.find('.js-success-icon');

            // Показываем спиннер, прячем иконку успеха — запрос начался
            $spinner.addClass('is-active').show();
            $success.hide();

            // $.ajax — более гибкий вариант запроса, чем $.get или $.post.
            // Позволяет явно задать тип запроса и обработать ошибки сети.
            $.ajax({
                url:  fs_lms_vars.ajaxurl,
                type: 'POST',
                data: requestData,
                success: function (response) {
                    $spinner.removeClass('is-active').hide();

                    if (response.success) {
                        // fadeIn().delay(1000).fadeOut() — плавно появляется, ждёт секунду, плавно исчезает
                        $success.fadeIn().delay(1000).fadeOut();
                    } else {
                        alert('Ошибка: ' + response.data);
                    }
                },
                error: function () {
                    // Этот блок срабатывает при сетевых ошибках (нет интернета, сервер упал и т.д.)
                    // В отличие от success, здесь response может вообще не существовать.
                    $spinner.removeClass('is-active').hide();
                    alert('Системная ошибка AJAX. Проверьте консоль.');
                }
            });
        });
    },


};