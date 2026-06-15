/**
 * @module GroupModal
 * @description UI-компонент модального окна для создания и редактирования учебных групп.
 *              Отвечает за:
 *              - Кэширование DOM-элементов для оптимизации производительности
 *              - Управление состоянием модального окна (открытие, закрытие, сброс)
 *              - Переключение между режимами "Создание" и "Редактирование"
 *              - Валидацию названия группы с использованием HTML5 Constraint Validation API
 *              - Управление расписанием группы (чекбоксы дней недели + временные слоты)
 *              - Сериализацию расписания в JSON для отправки на сервер
 *              - Сбор данных формы и уведомление внешних менеджеров через паттерн Pub/Sub
 *
 * @requires jQuery
 * @requires openModal, closeModal, bindEsc, unbindEsc - базовые утилиты управления модальными окнами
 */

import { openModal, closeModal, bindEsc, unbindEsc } from '../../modules/modal-base.js';

const $ = jQuery;

/**
 * UI-компонент модального окна управления учебными группами.
 * Работает в связке с GroupModalManager, который обрабатывает AJAX-запросы.
 */
export const GroupModal = {
    /** @type {jQuery|null} Ссылка на основной контейнер модального окна */
    $modal: null,

    /** @type {Function[]} Массив колбэков, вызываемых при успешной валидации и отправке формы */
    _saveCallbacks: [],

    /** @type {boolean} Флаг для предотвращения повторной инициализации */
    _initialized: false,

    // Кэшированные jQuery-объекты полей формы для избежания повторных поисков в DOM.
    // Поиск элементов через $('#id') — относительно дорогая операция,
    // поэтому сохраняем ссылки один раз при инициализации.

    /** @type {jQuery} Поле ввода названия группы */
    $titleInput: null,
    /** @type {jQuery} Dropdown выбора учебного периода */
    $periodSelect: null,
    /** @type {jQuery} Dropdown выбора предмета */
    $subjectSelect: null,
    /** @type {jQuery} Dropdown выбора преподавателя */
    $teacherSelect: null,
    /** @type {jQuery} Скрытое поле типа действия (add/edit) */
    $actionInput: null,
    /** @type {jQuery} Скрытое поле ID группы (для режима редактирования) */
    $groupIdInput: null,

    /** @type {jQuery} Кнопка сохранения формы */
    $saveBtn: null,
    /** @type {jQuery} Элемент заголовка модального окна */
    $titleEl: null,
    /** @type {jQuery} Форма модального окна */
    $form: null,

    /**
     * Инициализация компонента.
     * Выполняет проверку существования модалки, кэширует элементы и навешивает события.
     */
    init() {
        // Защита от повторной инициализации (паттерн Singleton)
        if (this._initialized) return;

        this.$modal = $('#fs-lms-group-modal');
        if (!this.$modal.length) return; // Если модалки нет в DOM, прекращаем инициализацию

        this._initialized = true;
        this._cacheElements();
        this._bindEvents();
    },

    /**
     * Кэширование DOM-элементов.
     * Поиск элементов через jQuery — относительно дорогая операция.
     * Сохраняя ссылки один раз при инициализации, мы ускоряем последующую работу с формой.
     * @private
     */
    _cacheElements() {
        this.$titleInput    = $('#group-title');
        this.$periodSelect  = $('#group-period');
        this.$subjectSelect = $('#group-subject');
        this.$teacherSelect = $('#group-teacher');

        this.$actionInput  = this.$modal.find('input[name="action_type"]');
        this.$groupIdInput = this.$modal.find('input[name="id"]');

        this.$saveBtn  = this.$modal.find('button[type="submit"]');
        this.$titleEl  = this.$modal.find('.fs-lms-modal-title');
        this.$form     = this.$modal.find('form');
    },

    /**
     * Привязка обработчиков событий.
     * Используется неймспейсинг событий (`.fs`), чтобы при необходимости можно было
     * безопасно удалить только эти обработчики через .off('.fs'), не затрагивая другие.
     * @private
     */
    _bindEvents() {
        // Закрытие модалки при клике на фон, кнопку отмены или крестик
        this.$modal.on('click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .js-modal-close, .fs-close', (e) => {
            e.preventDefault();
            this.close();
        });

        // UX: Очистка состояния ошибки валидации, как только пользователь начинает вводить валидный текст.
        // Регулярное выражение проверяет наличие хотя бы одной буквы (латиница/кириллица) или цифры.
        // Это дает мгновенную обратную связь: как только пользователь ввел корректный символ,
        // ошибка валидации исчезает, и он может отправить форму.
        this.$titleInput.on('input.fs', () => {
            if (/[a-zA-Zа-яёА-ЯЁ\d]/.test(this.$titleInput.val())) {
                this.$titleInput[0].setCustomValidity('');
            }
        });

        // Обработчик чекбоксов дней недели в расписании.
        // При установке/снятии галочки показываем/скрываем поля ввода времени для этого дня.
        // toggleClass('hidden', !$cb.prop('checked')) — компактная запись:
        // если чекбокс НЕ отмечен, добавляем класс 'hidden', иначе убираем его.
        this.$modal.on('change.fs', '.js-schedule-day-cb', (e) => {
            const $cb  = $(e.currentTarget);
            const $row = $cb.closest('.fs-schedule-day-row');
            $row.find('.fs-schedule-day-times').toggleClass('hidden', !$cb.prop('checked'));
        });

        // Перехват отправки формы с валидацией и уведомлением подписчиков
        this.$form.on('submit.fs', (e) => {
            e.preventDefault(); // Предотвращаем стандартную перезагрузку страницы браузером

            if (!this._validate()) return; // Если валидация не прошла, прерываем выполнение

            const formData = this._collectFormData();
            // Уведомляем всех подписчиков (например, GroupModalManager) о готовности данных
            this._saveCallbacks.forEach(cb => cb(formData));
        });
    },

    /**
     * Регистрация колбэка, который будет вызван при попытке сохранения формы.
     * Реализует паттерн Pub/Sub (Издатель-Подписчик).
     * @param {Function} callback - Функция, принимающая объект с данными формы.
     */
    onSave(callback) {
        if (typeof callback === 'function') {
            this._saveCallbacks.push(callback);
        }
    },

    /**
     * Открытие модального окна в режиме создания или редактирования.
     * @param {'add'|'edit'} action - Режим работы модалки.
     * @param {Object} [data={}] - Объект с данными для заполнения формы (только для режима 'edit').
     * @param {string|number} [data.id] - ID группы.
     * @param {string} [data.title] - Название группы.
     * @param {string|number} [data.period_id] - ID периода.
     * @param {string|number} [data.subject_id] - ID предмета.
     * @param {string|number} [data.teacher_id] - ID преподавателя.
     * @param {Array<{day: string, start: string, end: string}>} [data.schedule] - Массив расписания.
     */
    open(action, data = {}) {
        if (!this._initialized) return;

        const isUpdate = action === 'edit';

        if (this.$actionInput.length) this.$actionInput.val(action);
        this.$titleEl.text(isUpdate ? 'Редактировать группу' : 'Добавить новую группу');
        this.$saveBtn.text(isUpdate ? 'Сохранить изменения' : 'Создать группу');

        if (isUpdate) {
            if (this.$groupIdInput.length) this.$groupIdInput.val(data.id ?? '');
            this.$titleInput.val(data.title ?? '');
            this.$periodSelect.val(data.period_id ?? '').trigger('change');
            this.$subjectSelect.val(data.subject_id ?? '').trigger('change');
            this.$teacherSelect.val(data.teacher_id ?? '').trigger('change');
            this._restoreSchedule(data.schedule ?? []);
            this._setEditReadonly(true);
        } else {
            this._resetForm();
        }

        // Базовая логика открытия модалки и привязки клавиши Esc
        openModal(this.$modal);
        bindEsc('student_group', () => this.close());

        // ПАТТЕРН: Двойной requestAnimationFrame для управления фокусом.
        // Браузеру требуется время на отрисовку модалки (CSS transition).
        // Если вызвать .focus() сразу, фокус может потеряться или вызвать "дерганье" интерфейса.
        // Двойной RAF гарантирует, что фокус будет установлен после завершения перерисовки кадра.
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                this.$titleInput.trigger('focus');
            });
        });
    },

    /**
     * Закрытие модального окна и очистка состояния.
     */
    close() {
        closeModal(this.$modal);
        unbindEsc('student_group');
        this._setEditReadonly(false);
        this._resetForm();
    },

    /**
     * Управление состоянием кнопки сохранения (блокировка и изменение текста).
     * @param {boolean} loading - Флаг состояния загрузки.
     */
    setSaveState(loading) {
        const isUpdate = this.$actionInput.length && this.$actionInput.val() === 'edit';
        this.$saveBtn
            .prop('disabled', loading)
            .text(loading ? 'Сохранение...' : (isUpdate ? 'Сохранить изменения' : 'Создать группу'));
    },

    /**
     * Клиентская валидация формы.
     * Использует HTML5 Constraint Validation API для отображения ошибок.
     * Проверяет, что название группы содержит хотя бы одну букву или цифру.
     * @private
     * @returns {boolean} true, если форма валидна; false, если есть ошибки.
     */
    _validate() {
        const title = this.$titleInput.val().trim();

        // Регулярное выражение проверяет наличие хотя бы одного символа:
        // a-zA-Z — латинские буквы
        // а-яёА-ЯЁ — кириллические буквы (включая ё/Ё)
        // \d — цифры
        const hasSlugChars = /[a-zA-Zа-яёА-ЯЁ\d]/.test(title);

        if (!hasSlugChars) {
            // setCustomValidity устанавливает сообщение об ошибке для встроенного в браузер механизма валидации.
            // Пустая строка '' означает, что поле валидно.
            this.$titleInput[0].setCustomValidity(
                'Название должно содержать хотя бы одну букву или цифру.'
            );
        } else {
            this.$titleInput[0].setCustomValidity('');
        }

        // checkValidity() — встроенный метод HTML5, который проверяет все поля формы
        // на валидность (required, type, pattern, custom validity и т.д.).
        // Возвращает true, если все поля валидны, иначе false и показывает браузерные подсказки.
        return this.$form[0].checkValidity();
    },

    /**
     * Блокирует/разблокирует поля только для чтения в режиме редактирования.
     * @private
     * @param {boolean} lock
     */
    _setEditReadonly(lock) {
        this.$modal.find('[data-edit-readonly]').each((_, el) => {
            $(el).prop('disabled', lock).prop('readonly', lock);
        });
    },

    /**
     * Сброс формы к исходному состоянию.
     * Очищает значения, снимает флажки и сбрасывает состояния пользовательской валидации.
     * @private
     */
    _resetForm() {
        // reset() — встроенный метод HTMLFormElement, который сбрасывает все поля к начальным значениям
        this.$form[0].reset();

        // Сброс кастомной ошибки валидации
        this.$titleInput[0].setCustomValidity('');

        // Очистка скрытых полей (с проверкой length на случай, если элемент не найден)
        if (this.$groupIdInput.length) this.$groupIdInput.val('');
        if (this.$actionInput.length) this.$actionInput.val('add');

        // Скрываем все временные слоты в расписании
        this.$modal.find('.fs-schedule-day-times').addClass('hidden');
    },

    /**
     * Восстановление расписания группы из массива объектов.
     * Устанавливает галочки на нужных днях недели и заполняет поля времени.
     * @private
     * @param {Array<{day: string, start: string, end: string}>} schedule - Массив расписания.
     */
    _restoreSchedule(schedule) {
        // Сначала сбрасываем все чекбоксы и скрываем все временные слоты
        this.$modal.find('.js-schedule-day-cb').prop('checked', false);
        this.$modal.find('.fs-schedule-day-times').addClass('hidden');

        // Защита: если schedule не массив, используем пустой массив
        const entries = Array.isArray(schedule) ? schedule : [];

        // Проходим по каждому элементу расписания и восстанавливаем состояние
        entries.forEach(({ day, start, end }) => {
            // Ищем строку расписания по data-атрибуту data-day
            const $row = this.$modal.find(`.fs-schedule-day-row[data-day="${day}"]`);
            if (!$row.length) return; // Если строка не найдена, пропускаем

            // Устанавливаем галочку, заполняем время и показываем временной слот
            $row.find('.js-schedule-day-cb').prop('checked', true);
            $row.find('.js-day-start').val(start || '');
            $row.find('.js-day-end').val(end || '');
            $row.find('.fs-schedule-day-times').removeClass('hidden');
        });
    },

    /**
     * Сбор данных из полей формы в единый объект.
     * Включая сериализацию расписания в JSON-строку.
     * @private
     * @returns {Object} Объект с подготовленными данными для отправки на сервер.
     */
    _collectFormData() {
        const schedule = [];

        // Проходим по всем строкам расписания и собираем данные только для отмеченных дней
        this.$modal.find('.fs-schedule-day-row').each((_, row) => {
            const $row = $(row);
            const $cb  = $row.find('.js-schedule-day-cb');

            // Если чекбокс не отмечен, пропускаем этот день
            if (!$cb.prop('checked')) return;

            // Собираем данные дня: day (ключ дня), start (время начала), end (время окончания)
            schedule.push({
                day:   $cb.val(),
                start: $row.find('.js-day-start').val() || '',
                end:   $row.find('.js-day-end').val()   || '',
            });
        });

        return {
            action_type:   this.$actionInput.length ? this.$actionInput.val() : 'add',
            id:            this.$groupIdInput.length ? this.$groupIdInput.val() : '',
            title:         this.$titleInput.val().trim(),
            period_id:     this.$periodSelect.val(),
            subject_id:    this.$subjectSelect.val(),
            teacher_id:    this.$teacherSelect.val(),

            // Сериализуем массив расписания в JSON-строку для отправки на сервер.
            // Сервер распарсит JSON и сохранит расписание в базу данных.
            // JSON.stringify преобразует массив объектов в строку формата:
            // [{"day":"mon","start":"09:00","end":"10:00"},{"day":"wed","start":"14:00","end":"15:00"}]
            schedule_json: JSON.stringify(schedule),
        };
    },
};