/**
 * @module AcademicPeriodModalManager
 * @description Менеджер для управления модальными окнами и AJAX-запросами
 *              при работе с учебными периодами (добавление, редактирование, удаление).
 *              Использует jQuery для DOM-манипуляций и AJAX.
 */

import {
    toggleButton, // Утилита для блокировки/разблокировки кнопки и смены текста (защита от двойного клика)
    apiError,     // Утилита для логирования ошибок API (обычно выводит в консоль или отправляет в мониторинг)
    escapeHtml,   // Утилита для экранирования HTML-сущностей (защита от XSS-атак)
    showNotice,   // Утилита для отображения всплывающих уведомлений (успех/ошибка)
} from '../../modules/utils.js';

import { ConfirmModal } from '../../modals/confirm-modal.js';
import { AcademicPeriodModal } from '../../modals/enrollment/academic-period-modal';

// Глобальный алиас для jQuery. 
// Примечание: в современных проектах часто стараются избегать глобального $, 
// но в WordPress-среде это распространенная практика.
const $ = jQuery;

/**
 * Основной объект-менеджер.
 * Методы с префиксом `_` считаются приватными (внутренними) и не должны
 * вызываться напрямую извне этого модуля.
 */
export const AcademicPeriodModalManager = {

    /**
     * Инициализация менеджера.
     * Точка входа, которую нужно вызвать при загрузке страницы.
     */
    init() {
        // Инициализируем внутреннюю логику самих модальных окон
        AcademicPeriodModal.init();
        ConfirmModal.init();

        // Навешиваем обработчики событий
        this._bindEvents();
    },

    /**
     * Привязка обработчиков событий к элементам DOM.
     * @private
     */
    _bindEvents() {
        // Прямая привязка: элемент .js-add-period уже существует в DOM на момент загрузки
        $('.js-add-period').on('click', (e) => this._handleOpenAddModal(e));

        // ДЕЛЕГИРОВАНИЕ СОБЫТИЙ: $(document).on(...)
        //Мы используем делегирование для .js-edit-period и .js-delete-period, 
        // потому что эти кнопки могут быть добавлены в DOM динамически (например, после AJAX-подгрузки).
        // Если написать $('.js-edit-period').on(...), то на новые элементы событие не навесится.
        $(document).on('click', '.js-edit-period', (e) => this._handleOpenEditModal(e));
        $(document).on('click', '.js-delete-period', (e) => this._handleDelete(e));

        // Подписка на событие сохранения внутри модального окна.
        // Когда пользователь нажимает "Сохранить" в модалке, она вызывает этот колбэк с данными формы.
        AcademicPeriodModal.onSave((formData) => this._handleSave(formData));
    },

    /**
     * Обработчик открытия модального окна для добавления нового периода.
     * @private
     * @param {jQuery.Event} e - Событие клика.
     */
    _handleOpenAddModal(e) {
        //preventDefault() отменяет стандартное поведение браузера.
        // Если это ссылка (<a href="#">), страница не будет перезагружена или прокручена вверх.
        e.preventDefault();
        AcademicPeriodModal.open('add');
    },

    /**
     * Обработчик открытия модального окна для редактирования периода.
     * Считывает данные из data-* атрибутов нажатой кнопки и передает их в модалку.
     * @private
     * @param {jQuery.Event} e - Событие клика.
     */
    _handleOpenEditModal(e) {
        e.preventDefault();
        const $link = $(e.currentTarget); // e.currentTarget - это именно тот элемент, на который навешан обработчик

        // jQuery .data() автоматически считывает атрибуты data-* и приводит типы (например, "1" к числу 1).
        AcademicPeriodModal.open('edit', {
            id:         $link.data('id'),
            name:       $link.data('name'),
            start_date: $link.data('start-date'), // Дефис в HTML (data-start-date) превращается в camelCase (start-date -> startDate, но здесь ключ задан явно)
            end_date:   $link.data('end-date'),
            //data-атрибуты всегда строки. Преобразуем "1" или "0" в настоящий boolean.
            is_current: parseInt($link.data('current'), 10) === 1,
        });
    },

    /**
     * Обработчик сохранения данных формы (создание или обновление).
     * Отправляет AJAX-запрос на сервер.
     * @private
     * @param {Object} formData - Данные, собранные из формы модального окна.
     */
    _handleSave(formData) {
        // Блокируем кнопку сохранения и показываем спиннер, чтобы пользователь не нажал её дважды
        AcademicPeriodModal.setSaveState(true);

        // fs_lms_vars - глобальный объект, который WordPress передает через wp_localize_script.
        // Он содержит ajaxurl, имена действий (actions) и nonce (токены безопасности).
        $.post(fs_lms_vars.ajaxurl, {
            action:      fs_lms_vars.ajax_actions.saveAcademicPeriod,
            security:    fs_lms_vars.nonces.manager, // Nonce защищает от CSRF-атак
            action_type: formData.action_type,
            id:          formData.id,
            name:        formData.name,
            start_date:  formData.start_date,
            end_date:    formData.end_date,
            is_current:  formData.is_current,
        })
            .done((res) => {
                // res - это ответ от сервера (обычно в формате { success: true/false, data: {...} })
                if (res.success) {
                    // Самый простой способ обновить данные на странице после успешного сохранения - перезагрузка.
                    // (В более сложных SPA можно было бы обновить DOM вручную без перезагрузки).
                    location.reload();
                } else if (res.data?.error_code === 'duplicate_id') {
                    // Специфическая обработка ошибки: если ID уже существует, показываем ошибку прямо в модалке
                    AcademicPeriodModal.setIdError(res.data.message);
                    AcademicPeriodModal.setSaveState(false); // Разблокируем кнопку, чтобы пользователь мог исправить ID
                } else {
                    // Общая ошибка: показываем уведомление внутри тела модального окна
                    showNotice(res.data?.message || res.data || 'Ошибка сохранения.', 'error', AcademicPeriodModal.$modal.find('.fs-lms-modal-body'));
                    AcademicPeriodModal.setSaveState(false);
                }
            })
            .fail(() => {
                // Сетевая ошибка (например, потеря интернета или ошибка 500 на сервере)
                apiError('Failed to save academic period');
                AcademicPeriodModal.setSaveState(false);
            });
    },

    /**
     * Обработчик нажатия на кнопку удаления.
     * Реализует двухэтапную проверку: сначала запрашиваем у сервера информацию о последствиях,
     * затем показываем модальное окно подтверждения, и только потом удаляем.
     * @private
     * @param {jQuery.Event} e - Событие клика.
     */
    _handleDelete(e) {
        e.preventDefault();
        const $btn  = $(e.currentTarget);
        const id    = $btn.data('key');
        const name  = $btn.data('name');
        const $row  = $btn.closest('tr'); // Находим строку таблицы, чтобы потом её удалить или показать в ней ошибку

        // Блокируем кнопку на время запроса
        toggleButton($btn, true, '...');

        // ЭТАП 1: Проверка возможности удаления на сервере
        $.post(fs_lms_vars.ajaxurl, {
            action:    fs_lms_vars.ajax_actions.checkPeriodDeletion,
            security:  fs_lms_vars.nonces.deletePeriod,
            period_id: id,
        })
            .done((res) => {
                toggleButton($btn, false); // Снимаем блокировку кнопки проверки

                if (!res.success) {
                    // Если сервер запретил удаление (например, период уже используется в закрытом отчете)
                    showNotice(res.data?.message || 'Ошибка проверки периода.', 'error', $row.closest('.wrap'));
                    return; // Прерываем выполнение, модалка подтверждения не будет показана
                }

                //Оператор ?? (Nullish Coalescing) присваивает 0, если значение слева равно null или undefined.
                const studentCount = res.data?.student_count ?? 0;
                const groupCount   = res.data?.group_count   ?? 0;

                //Обязательно экранируем имя перед вставкой в HTML/текст, чтобы предотвратить XSS, 
                // если в названии периода есть спецсимволы или вредоносный код.
                const safeName     = escapeHtml(name);

                let message;
                if (studentCount === 0) {
                    message = `Вы уверены, что хотите полностью удалить учебный период «${safeName}»?\nЭто действие необратимо.`;
                } else {
                    message =
                        `Период «${safeName}» содержит ${groupCount} гр. и ${studentCount} уч.\n` +
                        `Ученики без других зачислений будут удалены безвозвратно вместе со всеми данными.\n` +
                        `Это действие необратимо.`;
                }

                // ЭТАП 2: Показ модального окна подтверждения с динамическим текстом
                ConfirmModal.confirm({
                    title:       'Удалить период?',
                    message,
                    confirmText: 'Да, удалить',
                    cancelText:  'Отмена',
                    // Если есть студенты, делаем модалку пошире ('md'), иначе узкой ('sm')
                    size:        studentCount > 0 ? 'md' : 'sm',
                    isDanger:    true, // Визуально выделяет кнопку подтверждения красным цветом
                })
                    .then(() => this._doDelete(id, $btn, $row)) // Если пользователь нажал "Да"
                    .catch(() => {}); // Если пользователь нажал "Отмена" или закрыл модалку, ничего не делаем
            })
            .fail(() => {
                toggleButton($btn, false);
                apiError('Failed to check period deletion');
            });
    },

    /**
     * Непосредственное выполнение удаления периода после подтверждения пользователем.
     * @private
     * @param {string|number} id - ID удаляемого периода.
     * @param {jQuery} $btn - jQuery-объект кнопки удаления.
     * @param {jQuery} $row - jQuery-объект строки таблицы.
     */
    _doDelete(id, $btn, $row) {
        // Снова блокируем кнопку, так как начинается реальный запрос на удаление
        toggleButton($btn, true, '...');

        $.post(fs_lms_vars.ajaxurl, {
            action:    fs_lms_vars.ajax_actions.deletePeriod,
            security:  fs_lms_vars.nonces.deletePeriod,
            period_id: id,
        })
            .done((res) => {
                if (res.success) {
                    // UX: Плавное исчезновение строки перед её физическим удалением из DOM
                    $row.fadeOut(400, () => {
                        $row.remove();

                        // Edge case: Если это была последняя строка в таблице, 
                        // лучше перезагрузить страницу, чтобы показать сообщение "Записей не найдено" от бэкенда.
                        if ($row.parent().children('tr').length === 0) {
                            location.reload();
                        }
                    });
                } else {
                    // Если бэкенд вернул ошибку уже на этапе удаления
                    toggleButton($btn, false);
                    showNotice(res.data?.message || 'Ошибка удаления периода.', 'error', $row.closest('.wrap'));
                }
            })
            .fail(() => {
                toggleButton($btn, false);
                apiError('Failed to delete period');
            });
    },
};