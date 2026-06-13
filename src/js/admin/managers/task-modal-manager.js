/**
 * @module TaskModalManager
 * @description Менеджер для управления модальным окном создания новой задачи.
 *              Отвечает за:
 *              - Инициализацию модального окна и подписку на его события
 *              - Загрузку типов задач (task types / terms) при открытии модалки
 *              - Каскадную загрузку шаблонов (boilerplates) при смене типа задачи
 *              - Валидацию данных формы перед отправкой
 *              - Защиту от двойной отправки формы через флаг состояния
 *              - Создание задачи через AJAX и открытие результата в новой вкладке
 *
 * @requires jQuery
 * @requires TaskModal - UI-компонент модального окна создания задачи
 * @requires showNotice, showModalError - утилиты для отображения уведомлений
 */

import '../_types.js';
import {TaskModal} from '../modals/task-modal.js';
import { showNotice, showModalError } from '../modules/utils.js';

const $ = jQuery;

/**
 * Основной объект-менеджер.
 * Управляет жизненным циклом создания задачи: от открытия модалки до отправки данных на сервер.
 */
export const TaskModalManager = {

    /**
     * Флаг состояния отправки формы.
     * Используется для защиты от двойного клика по кнопке "Создать".
     * Даже если UI-кнопка разблокируется по какой-то причине, этот флаг
     * предотвратит повторную отправку запроса на сервер.
     * @private
     * @type {boolean}
     */
    _submitting: false,

    /**
     * Инициализация менеджера.
     * Точка входа, вызывается при загрузке страницы.
     * Подписывается на ключевые события жизненного цикла модального окна.
     */
    init() {
        TaskModal.init();

        // Подписка на событие открытия модалки.
        // Каждый раз, когда пользователь открывает модалку, загружаем актуальный список типов задач.
        // Это гарантирует, что список не устарел (например, если другой админ добавил новый тип).
        TaskModal.onOpen(() => this.loadTaskTypes());

        // Подписка на событие изменения типа задачи (select).
        // При смене типа — загружаем соответствующие шаблоны (boilerplates).
        // Это реализация паттерна "каскадных dropdowns" (зависимых выпадающих списков).
        TaskModal.onTermChange((slug) => this.loadBoilerplates(slug));

        // Подписка на событие отправки формы.
        // Когда пользователь нажимает "Создать", модалка вызывает этот колбэк с данными формы.
        TaskModal.onSubmit((data) => this.createTask(data));
    },

    /**
     * Загрузка списка типов задач (task types / terms) с сервера.
     * Вызывается при каждом открытии модального окна.
     *
     * Использует $.get вместо $.post, так как операция идемпотентна —
     * мы только получаем данные, не изменяя состояние на сервере.
     */
    loadTaskTypes() {
        // Показываем индикатор загрузки прямо в dropdown, 
        // чтобы пользователь понимал, что данные подгружаются.
        TaskModal.setTerms('<option value="">Загрузка...</option>');

        $.get(fs_lms_vars.ajaxurl, {
            action:      fs_lms_vars.ajax_actions.getTaskTypes,
            subject_key: fs_lms_task_data.subject_key, // Ключ текущего предмета из глобальных данных страницы
            security:    fs_lms_task_data.security,
        }).done((res) => {
            // Формируем HTML для dropdown.
            // Первый option — пустой, с подсказкой для пользователя.
            let html = '<option value="">-- Выберите номер --</option>';

            if (res.success) {
                // Генерируем option-элементы для каждого типа задачи.
                // ВАЖНО: сохраняем slug в data-атрибуте, чтобы потом использовать его 
                // для загрузки boilerplates. ID используется для отправки на сервер, 
                // а slug — для клиентской логики.
                res.data.forEach(type => {
                    html += `<option value="${type.id}" data-slug="${type.slug}">${type.description}</option>`;
                });
            }

            // Обновляем dropdown в модалке сформированным HTML
            TaskModal.setTerms(html);
        });
    },

    /**
     * Загрузка шаблонов (boilerplates) для выбранного типа задачи.
     * Реализует паттерн "каскадных dropdowns": содержимое второго списка
     * зависит от выбора в первом.
     *
     * @param {string} termSlug - Слаг (строковый идентификатор) выбранного типа задачи.
     */
    loadBoilerplates(termSlug) {
        // Ранний выход, если тип задачи не выбран.
        // Очищаем dropdown и показываем подсказку.
        if (!termSlug) {
            TaskModal.setBoilerplates('<option value="">-- Сначала выберите номер --</option>');
            return;
        }

        // Показываем индикатор загрузки
        TaskModal.setBoilerplates('<option value="">Загрузка...</option>');

        $.get(fs_lms_vars.ajaxurl, {
            action:      fs_lms_vars.ajax_actions.getTaskBoilerplates,
            subject_key: fs_lms_task_data.subject_key,
            term_slug:   termSlug, // Передаем slug выбранного типа для фильтрации шаблонов
            security:    fs_lms_task_data.security,
        }).done((res) => {
            let html = '<option value="">-- Без шаблона --</option>';

            // Дополнительная проверка: убеждаемся, что сервер вернул именно массив.
            // Это защита от неожиданного формата ответа (например, если сервер вернет объект вместо массива).
            if (res.success && Array.isArray(res.data)) {
                res.data.forEach(bp => {
                    html += `<option value="${bp.uid}">${bp.title}</option>`;
                });
            }

            TaskModal.setBoilerplates(html);
        });
    },

    /**
     * Создание новой задачи через AJAX-запрос.
     * Выполняет клиентскую валидацию, защищает от двойной отправки
     * и открывает созданную задачу в новой вкладке после успеха.
     *
     * @param {Object} data - Данные формы из модального окна.
     * @param {string|number} data.termId - ID выбранного типа задачи.
     * @param {string} data.boilerplateUid - UID выбранного шаблона (может быть пустым).
     * @param {string} data.title - Название задачи.
     */
    createTask(data) {
        // ДВОЙНАЯ ЗАЩИТА ОТ ПОВТОРНОЙ ОТПРАВКИ:
        // 1. Флаг _submitting на уровне менеджера (не зависит от UI)
        // 2. Метод setSubmitState блокирует кнопку в UI
        // Это защищает от ситуации, когда пользователь быстро кликает несколько раз,
        // или когда UI-кнопка не успевает заблокироваться.
        if (this._submitting) return;

        // Безопасное получение subject_key с проверкой на существование глобального объекта.
        // Если fs_lms_task_data по какой-то причине не определен, используем пустую строку.
        const subject_key = typeof fs_lms_task_data !== 'undefined' ? fs_lms_task_data.subject_key : '';

        // КЛИЕНТСКАЯ ВАЛИДАЦИЯ: Проверяем обязательные поля до отправки на сервер.
        // Это экономит трафик и дает мгновенный отклик пользователю.
        if (!data.termId) {
            showNotice('Номер задания обязателен для заполнения', 'error', TaskModal.$modal.find( '.fs-lms-modal-body' ));
            return;
        }

        if (!subject_key || !data.title) {
            showNotice('Заполните все обязательные поля', 'error', TaskModal.$modal.find( '.fs-lms-modal-body' ));
            return;
        }

        // Устанавливаем флаги отправки
        this._submitting = true;
        TaskModal.setSubmitState(true); // Блокирует кнопку и показывает индикатор загрузки в UI

        $.post(fs_lms_vars.ajaxurl, {
            action:          fs_lms_vars.ajax_actions.createTask,
            security:        fs_lms_task_data.security,
            subject_key:     subject_key,
            term_id:         data.termId,
            boilerplate_uid: data.boilerplateUid, // Может быть пустым, если пользователь выбрал "Без шаблона"
            title:           data.title,
        })
            .done((res) => {
                if (res.success) {
                    // ОТКРЫТИЕ В НОВОЙ ВКЛАДКЕ: После успешного создания задачи 
                    // открываем её в новой вкладке, чтобы пользователь мог сразу начать работу.
                    // Текущая страница остается открытой, чтобы администратор мог создать ещё одну задачу.
                    // Это типичный UX-паттерн для админок: "создал → открыл → готов к следующему".
                    window.open(res.data.redirect, '_blank');

                    // Закрываем модалку и сбрасываем состояние
                    TaskModal.close();
                    TaskModal.setSubmitState(false);
                } else {
                    // Показываем ошибку от сервера внутри модалки
                    showNotice(res.data, 'error', TaskModal.$modal.find( '.fs-lms-modal-body' ));
                    TaskModal.setSubmitState(false);
                }
            })
            .fail(() => {
                // Обработка сетевых ошибок (потеря соединения, ошибка 500)
                showNotice('Ошибка сервера при создании задания', 'error', TaskModal.$modal.find( '.fs-lms-modal-body' ));
                TaskModal.setSubmitState(false);
            })
            .always(() => {
                // .always() срабатывает независимо от успеха/ошибки.
                // Гарантируем, что флаг _submitting будет сброшен, 
                // иначе повторное открытие модалки не позволит отправить форму.
                this._submitting = false;
            });
    },
};