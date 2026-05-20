/**
 * Модуль управления учебными периодами (AcademicYearManager) для плагина FS-LMS.
 * @requires jQuery
 * @requires ../modules/utils.js
 * @requires ../components/confirm-modal.js
 * @requires ./academic-period-modal.js
 */

import {
    toggleButton,
    apiError,
    showNotice,
    escapeHtml,
} from '../modules/utils.js';
import { ConfirmModal } from '../components/confirm-modal.js';
import { AcademicPeriodModal } from '../components/academic-period-modal';

const $ = jQuery;

/**
 * Управление учебными периодами: CRUD, управление модалками периода и подтверждения.
 * @namespace AcademicPeriodManager
 */
export const AcademicPeriodManager = {
    /**
     * Инициализация модуля.
     */
    init() {
        // Инициализируем пассивные модули окон
        AcademicPeriodModal.init();
        ConfirmModal.init();

        this._bindEvents();
    },

    /**
     * Привязка обработчиков событий.
     * @private
     */
    _bindEvents() {
        $('.js-add-period').on('click', (e) => this._handleOpenAddModal(e));
        $(document).on('click', '.js-edit-period', (e) => this._handleOpenEditModal(e));
        $(document).on('click', '.js-delete-period', (e) => this._handleDelete(e));

        // Подписываемся на событие сохранения формы в модалке периода
        AcademicPeriodModal.onSave((formData) => this._handleSave(formData));
    },

    /**
     * Обработка открытия модалки для добавления нового периода.
     * @param {Event} e
     * @private
     */
    _handleOpenAddModal(e) {
        e.preventDefault();
        AcademicPeriodModal.open('add');
    },

    /**
     * Обработка открытия модалки редактирования периода с заполнением данных.
     * @param {Event} e
     * @private
     */
    _handleOpenEditModal(e) {
        e.preventDefault();
        const $link = $(e.currentTarget);

        AcademicPeriodModal.open('edit', {
            id:         $link.data('id'),
            name:       $link.data('name'),
            start_date: $link.data('start-date'),
            end_date:   $link.data('end-date'),
            is_current: parseInt($link.data('current'), 10) === 1
        });
    },

    /**
     * Обработка AJAX сохранения (создание или обновление) периода.
     * @param {Object} formData Данные из формы AcademicPeriodModal
     * @private
     */
    _handleSave(formData) {
        AcademicPeriodModal.setSaveState(true);

        const requestData = {
            action:      'save_academic_period',
            security:    fs_lms_vars.manager_nonce,
            action_type: formData.action_type,
            id:          formData.id,
            name:        formData.name,
            start_date:  formData.start_date,
            end_date:    formData.end_date,
            is_current:  formData.is_current
        };

        $.post(fs_lms_vars.ajaxurl, requestData)
            .done((res) => {
                if (res.success) {
                    location.reload();
                } else {
                    // Так как форма внутри кастомного враппера, выводим нативный alert или привязываем к контейнеру модалки
                    alert(res.data?.message || 'Ошибка сохранения.');
                    AcademicPeriodModal.setSaveState(false);
                }
            })
            .fail(() => {
                apiError('Failed to save academic period');
                AcademicPeriodModal.setSaveState(false);
            });
    },

    /**
     * Начало процесса удаления: показ красивого модального окна подтверждения.
     * @param {Event} e
     * @private
     */
    _handleDelete(e) {
        e.preventDefault();
        const $btn = $(e.currentTarget);
        const id = $btn.data('key');
        const name = $btn.data('name');
        const $row = $btn.closest('tr');

        const safeName = escapeHtml(name);

        ConfirmModal.confirm({
            title: 'Подтвердите удаление',
            message: `Вы уверены, что хотите полностью удалить учебный период «${safeName}»?\nЭто действие необратимо.`,
            confirmText: 'Да, удалить',
            cancelText: 'Отмена',
            size: 'sm',
            isDanger: true
        })
            .then(() => {
                this._doDelete(id, $btn, $row);
            })
            .catch(() => {
                // Отмена — ничего не делаем
            });
    },

    /**
     * Выполнение AJAX удаления на сервере с последующей скрывающей анимацией строки таблицы.
     * @param {string|number} id
     * @param {JQuery} $btn
     * @param {JQuery} $row
     * @private
     */
    _doDelete(id, $btn, $row) {
        toggleButton($btn, true, '...');

        $.post(fs_lms_vars.ajaxurl, {
            action: 'delete_academic_period',
            security: fs_lms_vars.manager_nonce,
            id: id
        })
            .done((res) => {
                if (res.success) {
                    $row.fadeOut(400, () => {
                        $row.remove();
                        // Если это была последняя строка в таблице — перезагружаем для отображения "Периоды не найдены"
                        if ($row.parent().children('tr').length === 0) {
                            location.reload();
                        }
                    });
                } else {
                    toggleButton($btn, false);
                    alert(res.data?.message || 'Ошибка удаления');
                }
            })
            .fail(() => {
                toggleButton($btn, false);
                apiError('Failed to delete academic period');
            });
    }
};