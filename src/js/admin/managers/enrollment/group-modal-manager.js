/**
 * @module GroupModalManager
 * @description Менеджер для управления модальными окнами и AJAX-запросами
 *              при работе с учебными группами (создание, удаление).
 *              Реализует безопасный процесс удаления с предварительной проверкой
 *              количества учеников и отображением их списка для подтверждения.
 *
 * @requires jQuery
 * @requires toggleButton, apiError, escapeHtml, showNotice - утилиты для UX и безопасности
 * @requires ConfirmModal, GroupModal - UI-компоненты модальных окон
 */

import {
    toggleButton,
    apiError,
    escapeHtml,
    showNotice,
} from '../../modules/utils.js';
import { ConfirmModal } from '../../modals/confirm-modal.js';
import { GroupModal } from '../../modals/enrollment/group-modal';

const $ = jQuery;

export const GroupModalManager = {

    /**
     * Инициализация менеджера.
     * Точка входа, вызывается при загрузке страницы.
     */
    init() {
        GroupModal.init();
        ConfirmModal.init();

        this._bindEvents();
    },

    /**
     * Привязка обработчиков событий.
     * @private
     */
    _bindEvents() {
        $(document).on('click', '.js-open-group-modal', (e) => this._handleOpenAddModal(e));
        $(document).on('click', '.js-edit-group', (e) => this._handleOpenEditModal(e));
        $(document).on('click', '.js-delete-group', (e) => this._handleDelete(e));

        GroupModal.onSave((formData) => this._handleSave(formData));
    },

    _handleOpenAddModal(e) {
        e.preventDefault();
        GroupModal.open('add');
    },

    _handleOpenEditModal(e) {
        e.preventDefault();
        const $row = $(e.currentTarget).closest('tr');
        const schedule = (() => {
            try { return JSON.parse($row.data('schedule') || '[]'); } catch { return []; }
        })();
        GroupModal.open('edit', {
            id:         $row.data('group-id'),
            title:      $row.data('group-name'),
            period_id:  $row.data('period-id'),
            subject_id: $row.data('subject-key'),
            teacher_id: $row.data('teacher-id') || '',
            schedule,
        });
    },

    _handleSave(formData) {
        GroupModal.setSaveState(true);

        const isEdit = formData.action_type === 'edit';
        const payload = {
            action:        isEdit ? fs_lms_vars.ajax_actions.updateStudentGroup : fs_lms_vars.ajax_actions.saveStudentGroup,
            security:      fs_lms_vars.nonces.manager,
            teacher_id:    formData.teacher_id,
            schedule_json: formData.schedule_json,
        };

        if (isEdit) {
            payload.id = formData.id;
        } else {
            payload.title     = formData.title;
            payload.period_id = formData.period_id;
            payload.subject_id = formData.subject_id;
        }

        $.post(fs_lms_vars.ajaxurl, payload)
            .done((res) => {
                if (res.success) {
                    location.reload();
                } else {
                    showNotice(res.data?.message || res.data || 'Ошибка сохранения группы.', 'error', GroupModal.$modal.find('.fs-lms-modal-body'));
                    GroupModal.setSaveState(false);
                }
            })
            .fail(() => {
                apiError('Failed to save student group');
                GroupModal.setSaveState(false);
            });
    },

    /**
     * Обработчик нажатия на кнопку удаления группы.
     * Реализует первый этап проверки: запрашивает у сервера количество учеников в группе,
     * чтобы решить, нужно ли показывать расширенное подтверждение или можно удалять сразу.
     * @private
     * @param {jQuery.Event} e - Событие клика.
     */
    _handleDelete(e) {
        e.preventDefault();
        const $btn  = $(e.currentTarget);
        const id    = $btn.data('id');
        const $row  = $btn.closest('tr'); // Находим строку таблицы для последующего удаления или показа ошибки

        // Извлекаем имя группы напрямую из DOM-структуры таблицы.
        // .trim() удаляет случайные пробелы или переносы строк, которые могут быть в HTML.
        const name  = $row.data('group-name') || $row.find('.column-title strong').text().trim();

        toggleButton($btn, true, '...');

        // Этап 1: Проверка возможности удаления и получение метрик
        $.post(fs_lms_vars.ajaxurl, {
            action:   fs_lms_vars.ajax_actions.checkGroupDeletion,
            security: fs_lms_vars.nonces.deleteGroup,
            group_id: id,
        })
            .done((res) => {
                toggleButton($btn, false);

                if (!res.success) {
                    showNotice(res.data?.message || 'Ошибка проверки группы.', 'error', $row.closest('.wrap'));
                    return; // Прерываем выполнение, если сервер запретил удаление
                }

                const studentCount = res.data?.student_count ?? 0;

                // Если учеников нет, удаляем сразу без дополнительных запросов и подтверждений
                if (studentCount === 0) {
                    this._doDelete(id, $btn, $row);
                    return;
                }

                // Если ученики есть, загружаем их список для детального подтверждения
                this._loadStudentsAndConfirm(id, $btn, $row, name, studentCount);
            })
            .fail(() => {
                toggleButton($btn, false);
                apiError('Failed to check group deletion');
            });
    },

    /**
     * Загрузка списка учеников группы и показ модального окна подтверждения удаления.
     * @private
     * @param {string|number} id - ID группы.
     * @param {jQuery} $btn - jQuery-объект кнопки удаления.
     * @param {jQuery} $row - jQuery-объект строки таблицы.
     * @param {string} groupName - Название группы.
     * @param {number} studentCount - Количество учеников в группе.
     */
    _loadStudentsAndConfirm(id, $btn, $row, groupName, studentCount) {
        $.post(fs_lms_vars.ajaxurl, {
            action:   fs_lms_vars.ajax_actions.getStudentsByGroup,
            group_id: id,
            security: fs_lms_vars.nonces.manager,
        })
            .done((res) => {
                const students = res.data || [];

                // Формируем маркированный список имен. 
                // Обязательно используем escapeHtml для каждого имени, чтобы предотвратить XSS-атаки, 
                // если в имени ученика содержатся спецсимволы или вредоносный код.
                const studentList = students
                    .map(s => `• ${escapeHtml(s.name || s.last_name || String(s.id))}`)
                    .join('\n');

                const safeName = escapeHtml(groupName);
                const orphanNote = 'Ученики, у которых нет других зачислений, будут удалены безвозвратно вместе со всеми данными.';
                const message = `В группе «${safeName}» ${studentCount} уч.:\n${studentList}\n\n${orphanNote}`;

                ConfirmModal.confirm({
                    title:       'Удалить группу?',
                    message,
                    confirmText: 'Удалить',
                    cancelText:  'Отмена',
                    size:        'sm',
                    isDanger:    true, // Визуально выделяет кнопку подтверждения как опасное действие
                })
                    .then(() => this._doDelete(id, $btn, $row))
                    .catch(() => {}); // Игнорируем отмену пользователем
            })
            .fail(() => {
                // ВАЖНЫЙ UX-ПАТТЕРН: Fallback при ошибке загрузки деталей.
                // Если запрос на получение списка учеников упал (например, таймаут), 
                // мы НЕ блокируем удаление полностью. Вместо этого мы показываем 
                // упрощенное предупреждение, позволяя пользователю принять решение.
                const safeName = escapeHtml(groupName);
                ConfirmModal.confirm({
                    title:       'Удалить группу?',
                    message:     `В группе «${safeName}» ${studentCount} уч. Ученики без других зачислений будут удалены безвозвратно. Продолжить?`,
                    confirmText: 'Удалить',
                    cancelText:  'Отмена',
                    size:        'sm',
                    isDanger:    true,
                })
                    .then(() => this._doDelete(id, $btn, $row))
                    .catch(() => {});
            });
    },

    /**
     * Непосредственное выполнение удаления группы после подтверждения.
     * @private
     * @param {string|number} id - ID группы.
     * @param {jQuery} $btn - jQuery-объект кнопки удаления.
     * @param {jQuery} $row - jQuery-объект строки таблицы.
     */
    _doDelete(id, $btn, $row) {
        toggleButton($btn, true, '...');

        $.post(fs_lms_vars.ajaxurl, {
            action:   fs_lms_vars.ajax_actions.deleteGroup,
            security: fs_lms_vars.nonces.deleteGroup,
            group_id: id,
        })
            .done((res) => {
                if (res.success) {
                    // Плавное визуальное удаление строки перед физическим удалением из DOM
                    $row.fadeOut(400, () => {
                        $row.remove();

                        // Проверка на пустую таблицу.
                        // Если это была последняя строка, лучше перезагрузить страницу, 
                        // чтобы отобразить стандартное сообщение бэкенда "Записей не найдено", 
                        // вместо того чтобы оставлять пустую HTML-таблицу.
                        if ($row.parent().children('tr').length === 0) {
                            location.reload();
                        }
                    });
                } else {
                    toggleButton($btn, false);
                    showNotice(res.data?.message || 'Ошибка удаления группы.', 'error', $row.closest('.wrap'));
                }
            })
            .fail(() => {
                toggleButton($btn, false);
                apiError('Failed to delete group');
            });
    },
};