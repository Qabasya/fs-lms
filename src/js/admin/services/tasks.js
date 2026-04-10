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
            this.initBoilerplateManager($); // Редактирование болванок (заготовок текста)
        }
    },


    // ============================================================
    // БЫСТРОЕ СОЗДАНИЕ ЗАДАНИЯ
    //
    // Перехватываем стандартную кнопку WordPress «Добавить новый»
    // и вместо перехода на страницу редактора — показываем свой
    // диалог через prompt(). Пользователь выбирает тип задания,
    // вводит заголовок, и мы сразу создаём запись через AJAX.
    // ============================================================
    initQuickCreation: function ($) {

        // Вешаем обработчик клика на body (а не на саму кнопку) — это «делегирование событий».
        // Нужно потому, что кнопка .page-title-action может появиться на странице позже,
        // уже после того как JS был инициализирован.
        $('body').on('click', '.page-title-action', function (e) {
            const href = $(this).attr('href') || '';

            // Кнопок «Добавить новый» в админке может быть несколько (разные типы постов).
            // Убеждаемся, что кликнули именно по кнопке нашего типа поста.
            const isOurPostType = href.includes('post-new.php') && href.includes('post_type=' + fsTaskData.post_type);
            if (!isOurPostType) return;

            // Отменяем стандартный переход по ссылке — берём управление в свои руки
            e.preventDefault();

            // Шаг 1: запрашиваем у сервера список существующих типов заданий (таксономия).
            // $.get — это сокращение для AJAX GET-запроса: «дай мне данные, ничего не меняй».
            $.get(fsTaskData.ajax_url, {
                action:      'get_task_types',       // Название хука на PHP-стороне: wp_ajax_get_task_types
                subject_key: fsTaskData.subject_key, // Ключ предмета, например 'math' или 'physics'
                nonce:       fsTaskData.nonce        // Одноразовый токен — защита от CSRF-атак
            }, function (res) {

                // res.success — стандартное поле ответа WordPress при использовании wp_send_json_success()
                if (!res.success || res.data.length === 0) return;

                // Формируем текст для диалога prompt() — нативного окна браузера с полем ввода
                let promptMessage = "Введите НОМЕР задания (например: 1, 2, 3...):\n";
                res.data.forEach(taskType => {
                    promptMessage += `№${taskType.slug} — ${taskType.description}\n`;
                });

                // prompt() возвращает строку с введённым значением или null при отмене
                const userInput = prompt(promptMessage);
                if (userInput === null) return; // Пользователь нажал «Отмена»

                // Ищем в массиве тип задания, slug которого совпадает с введённым номером.
                // Проверяем два варианта: голый номер ("1") и с префиксом ("math_1").
                const selectedType = res.data.find(taskType =>
                    taskType.slug == userInput ||
                    taskType.slug == `${fsTaskData.subject_key}_${userInput}`
                );

                if (!selectedType) {
                    alert('Задание с таким номером не найдено!');
                    return;
                }

                // Шаг 2: просим ввести заголовок для новой записи
                const title = prompt(`Создаем Задание №${userInput}. Введите заголовок:`);
                if (!title) return; // Пользователь нажал «Отмена» или оставил поле пустым

                // Шаг 3: отправляем данные на сервер для создания записи.
                // $.post — AJAX POST-запрос: «измени что-то на сервере».
                $.post(fsTaskData.ajax_url, {
                    action:      'create_task',
                    nonce:       fsTaskData.nonce,
                    subject_key: fsTaskData.subject_key,
                    term_id:     selectedType.id, // ID таксономии (типа задания)
                    title:       title
                }, function (response) {
                    if (response.success) {
                        // Сервер вернул URL только что созданной записи — переходим туда
                        window.location.href = response.data.redirect;
                    } else {
                        alert('Ошибка: ' + response.data);
                    }
                });
            });
        });
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


    // ============================================================
    // МЕНЕДЖЕР БОЛВАНОК
    //
    // «Болванка» — это заготовка текста для конкретного типа задания.
    // Учитель открывает modal, редактирует текст в TinyMCE-редакторах
    // (по одному на каждое поле шаблона), и сохраняет.
    //
    // Ключевая сложность: TinyMCE — тяжёлый редактор, его экземпляры
    // нужно явно создавать и удалять, иначе будут баги с дублированием.
    // ============================================================
    initBoilerplateManager: function ($) {

        const $modal     = $('#fs-boilerplate-modal');
        const $container = $modal.find('#boilerplate-fields-container'); // Обёртка для полей редактора

        // ----------------------------------------------------------
        // Вспомогательная функция: удаляет все TinyMCE-редакторы внутри modal.
        // Вызывается перед закрытием и перед повторным открытием modal —
        // чтобы не накапливались «зомби»-экземпляры редактора.
        // ----------------------------------------------------------
        const destroyEditors = () => {
            $container.find('.js-boilerplate-editor').each(function () {
                const editorId = $(this).attr('id');

                // Проверяем все объекты по цепочке, прежде чем вызвать метод.
                // Если хотя бы один из них undefined — получим ошибку.
                const editorExists = window.wp?.editor && window.tinymce?.get(editorId);
                if (editorExists) {
                    window.wp.editor.remove(editorId);
                }
            });
        };

        // ----------------------------------------------------------
        // Открытие modal по клику на кнопку в строке таблицы.
        // Стрелочная функция (e) => {} используется здесь намеренно,
        // чтобы внутри работал this.loadBoilerplateContent (см. setTimeout ниже).
        // ----------------------------------------------------------
        $(document).on('click', '.js-open-boilerplate-modal', (e) => {
            e.preventDefault();

            const $row      = $(e.currentTarget).closest('tr');
            const subjectKey = $('.js-task-manager-table').data('subject');
            const termSlug   = $row.data('task-slug');

            // Заполняем заголовок и скрытые поля modal данными из строки таблицы
            $modal.find('#boilerplate-task-name').text($row.data('task-name'));
            $modal.find('#boilerplate-term-slug').val(termSlug);
            $modal.find('#boilerplate-subject-key').val(subjectKey);

            // Показываем «заглушку» пока грузим структуру полей
            $container.html('<p>Загрузка структуры полей...</p>');

            // Открываем modal с плавным появлением (200мс), и только после этого
            // делаем AJAX-запрос — чтобы редакторы инициализировались в видимый DOM.
            // TinyMCE не умеет инициализироваться в скрытый элемент.
            $modal.fadeIn(200, () => {

                // Шаг 1: получаем структуру полей для данного типа задания
                $.get(ajaxurl, {
                    action:      'get_template_structure',
                    subject_key: subjectKey,
                    term_slug:   termSlug,
                    nonce:       fs_lms_vars.manager_nonce
                }, (structRes) => {
                    $container.empty(); // Очищаем «заглушку»

                    if (!structRes.success || !structRes.data.fields) return;

                    // Рендерим HTML-разметку для каждого поля.
                    // field.html — это уже готовый HTML от сервера (например, <textarea>)
                    structRes.data.fields.forEach(field => {
                        $container.append(`
                            <div class="boilerplate-field-group" style="margin-bottom:15px;">
                                <label style="display:block; font-weight:bold; margin-bottom:5px;">
                                    ${field.label}
                                </label>
                                ${field.html}
                            </div>
                        `);
                    });

                    // Шаг 2: загружаем сохранённый текст болванки и инициализируем редакторы.
                    // setTimeout на 150мс — небольшая задержка, чтобы DOM успел отрисоваться
                    // до того, как TinyMCE попытается прикрепиться к textarea.
                    setTimeout(() => this.loadBoilerplateContent($, subjectKey, termSlug), 150);
                });
            });
        });


        // ----------------------------------------------------------
        // Сохранение болванки.
        // Обычная function (не стрелочная) — нам здесь this не нужен,
        // зато нужен контекст $(this) для кнопки.
        // ----------------------------------------------------------
        $modal.on('click', '.js-boilerplate-modal-save', function () {
            const $saveBtn = $(this);

            // Utils.toggleButton — вероятно, ваша утилита для блокировки кнопки во время запроса
            if (typeof Utils !== 'undefined') {
                Utils.toggleButton($saveBtn, true, 'Сохранение...');
            }

            // Собираем содержимое всех редакторов в один объект { fieldKey: 'текст', ... }
            const fieldValues = {};
            $container.find('.js-boilerplate-editor').each(function () {
                const editorId  = $(this).attr('id');
                const fieldKey  = $(this).data('field-key');
                const tinyEditor = window.tinymce?.get(editorId);

                if (tinyEditor) {
                    // .save() синхронизирует содержимое визуального редактора обратно в <textarea>
                    tinyEditor.save();
                    fieldValues[fieldKey] = tinyEditor.getContent();
                } else {
                    // Если TinyMCE не загрузился — берём значение напрямую из textarea
                    fieldValues[fieldKey] = $(this).val();
                }
            });

            // Отправляем все поля одним запросом, сериализовав объект в JSON-строку.
            // На PHP-стороне нужно будет сделать json_decode($_POST['text']).
            $.post(ajaxurl, {
                action:      'save_task_boilerplate',
                nonce:       fs_lms_vars.manager_nonce,
                subject_key: $modal.find('#boilerplate-subject-key').val(),
                term_slug:   $modal.find('#boilerplate-term-slug').val(),
                text:        JSON.stringify(fieldValues)
            }, (res) => {
                if (typeof Utils !== 'undefined') {
                    Utils.toggleButton($saveBtn, false, 'Сохранить');
                }

                if (res.success) {
                    destroyEditors(); // Чистим редакторы перед закрытием
                    $modal.fadeOut(200);
                } else {
                    alert('Ошибка: ' + (res.data || 'неизвестно'));
                }
            });
        });


        // Закрытие modal по кнопке «Закрыть» — тоже чистим редакторы
        $modal.on('click', '.js-boilerplate-modal-close', () => {
            destroyEditors();
            $modal.fadeOut(200);
        });
    },


    // ============================================================
    // ЗАГРУЗКА СОХРАНЁННОГО ТЕКСТА БОЛВАНКИ
    //
    // Вызывается после того, как структура полей уже отрендерена в DOM.
    // Получает сохранённый JSON с сервера, раскладывает значения
    // по соответствующим полям и инициализирует TinyMCE-редакторы.
    // ============================================================
    loadBoilerplateContent: function ($, subjectKey, termSlug) {

        $.get(ajaxurl, {
            action:      'get_task_boilerplate',
            subject_key: subjectKey,
            term_slug:   termSlug,
            nonce:       fs_lms_vars.manager_nonce
        }, (res) => {
            console.log('Ответ от сервера (болванка):', res.data.text);

            // contentValues будет объектом вида { fieldKey: 'текст поля', ... }
            let contentValues = {};

            if (res.success && res.data.text) {
                const rawData = res.data.text;

                try {
                    // Новый формат: сервер вернул JSON-строку — парсим её в объект.
                    // Проверяем, что это строка и начинается с '{' (признак JSON-объекта).
                    const isJsonString = typeof rawData === 'string' && rawData.trim().startsWith('{');
                    contentValues = isJsonString ? JSON.parse(rawData) : rawData;
                } catch (parseError) {
                    // Старый формат: сервер вернул просто текст, а не JSON.
                    // Кладём его в первое найденное поле — для обратной совместимости.
                    const firstFieldKey = $('.js-boilerplate-editor').first().data('field-key');
                    if (firstFieldKey) {
                        contentValues[firstFieldKey] = rawData;
                    }
                }
            }

            // Перебираем все textarea с редакторами и инициализируем TinyMCE для каждого
            $('.js-boilerplate-editor').each(function () {
                const $textarea = $(this);
                const editorId  = $textarea.attr('id');
                const fieldKey  = $textarea.data('field-key');

                // Берём текст для этого конкретного поля, или пустую строку если его нет
                const fieldValue = contentValues?.[fieldKey] || '';

                // Сначала устанавливаем значение в сам textarea (на случай если TinyMCE не загрузится)
                $textarea.val(fieldValue);

                if (!window.wp?.editor) return; // TinyMCE недоступен — работаем с голым textarea

                // Если редактор для этого ID уже существует — удаляем его перед пересозданием.
                // Это важно при повторном открытии modal для другого задания.
                if (window.tinymce?.get(editorId)) {
                    window.wp.editor.remove(editorId);
                }

                // Инициализируем TinyMCE-редактор для этого textarea
                window.wp.editor.initialize(editorId, {
                    tinymce: {
                        wpautop: false,          // Не оборачивать строки в <p> автоматически
                        forced_root_block: '',   // Не добавлять корневой блок-обёртку
                        entity_encoding: 'raw',  // Не кодировать спецсимволы в HTML-entities
                        paste_as_text: true,     // При вставке — только plain text, без форматирования

                        setup: function (editor) {
                            // Событие 'init' — редактор готов к работе, устанавливаем содержимое
                            editor.on('init', () => {
                                editor.setContent(fieldValue);
                            });

                            // Синхронизируем textarea при каждом изменении в редакторе.
                            // Нужно для корректного чтения значения при сохранении.
                            editor.on('change input', () => editor.save());
                        }
                    },
                    quicktags:    true, // Панель с HTML-тегами (вкладка «Текст»)
                    mediaButtons: true  // Кнопка «Добавить медиа»
                });
            });
        });
    }
};