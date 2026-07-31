/**
 * @module RoomModalManager
 * @description Оркестратор модалки кабинета (Эпик 9) — близнец AcademicPeriodModalManager.
 *              Биндит «+»/изменить/удалить, шлёт AJAX (save_room/delete_room), после успеха
 *              перечитывает список через get_rooms и перерисовывает таблицу без перезагрузки.
 */

import { RoomModal } from '../../modals/enrollment/room-modal.js';
import { ConfirmModal } from '../../modals/confirm-modal.js';
import { showNotice } from '../../modules/utils.js';
import { escapeHtml } from '../../../common/utils.js';

const $ = jQuery;

export const RoomModalManager = {
    init() {
        RoomModal.init();
        ConfirmModal.init();
        this._bindEvents();
    },

    _bindEvents() {
        $(document).on('click', '.js-add-room', (e) => { e.preventDefault(); RoomModal.open('add'); });
        $(document).on('click', '.js-edit-room', (e) => this._handleEdit(e));
        $(document).on('click', '.js-delete-room', (e) => this._handleDelete(e));
        RoomModal.onSave((formData) => this._handleSave(formData));
    },

    _handleEdit(e) {
        e.preventDefault();
        const $link = $(e.currentTarget);
        RoomModal.open('edit', {
            id:       $link.data('id'),
            name:     $link.data('name'),
            subjects: $link.data('subjects'),
        });
    },

    _handleSave(formData) {
        if (!formData.name) { return; }
        RoomModal.setSaveState(true);
        $.post(fs_lms_vars.ajaxurl, {
            action:           fs_lms_vars.ajax_actions.saveRoom,
            security:         fs_lms_vars.nonces.room,
            room_id:          formData.id || 0,
            name:             formData.name,
            is_active:        '1',
            allowed_subjects: formData.subjects,
        })
            .done((res) => {
                if (res && res.success) {
                    RoomModal.close();
                    this.refresh();
                } else {
                    showNotice((res && res.data) || 'Не удалось сохранить кабинет.', 'error', RoomModal.$modal.find('.fs-lms-modal-body'));
                    RoomModal.setSaveState(false);
                }
            })
            .fail(() => {
                showNotice('Ошибка сети.', 'error', RoomModal.$modal.find('.fs-lms-modal-body'));
                RoomModal.setSaveState(false);
            });
    },

    _handleDelete(e) {
        e.preventDefault();
        const $link = $(e.currentTarget);
        const id    = $link.data('id');
        const name  = $link.data('name');
        ConfirmModal.confirm({
            title:       'Удалить кабинет?',
            message:     `Кабинет «${name}» будет удалён. Занятия сохранятся, но кабинет исчезнет из списка.`,
            size:        'sm',
            isDanger:    true,
            confirmText: 'Удалить',
            cancelText:  'Отмена',
        }).then(() => {
            $.post(fs_lms_vars.ajaxurl, {
                action:   fs_lms_vars.ajax_actions.deleteRoom,
                security: fs_lms_vars.nonces.room,
                room_id:  id,
            }).done((res) => { if (res && res.success) { this.refresh(); } });
        });
    },

    /**
     * Перечитывает список кабинетов и перерисовывает таблицу без перезагрузки страницы.
     *
     * Данные берём из AjaxHook::GetRooms (кабинеты + группы с их room_id) — так
     * список остаётся актуальным после добавления, правки и удаления.
     *
     * @return void
     */
    refresh() {
        $.post(fs_lms_vars.ajaxurl, {
            action:   fs_lms_vars.ajax_actions.getRooms,
            security: fs_lms_vars.nonces.room,
        })
            .done((res) => {
                if (!res || !res.success) {
                    window.location.reload();
                    return;
                }

                this._renderRows(res.data.rooms || [], res.data.groups || []);
            })
            .fail(() => window.location.reload());
    },

    /**
     * Рисует строки таблицы кабинетов.
     *
     * Разметка повторяет settings-9-rooms.php: название-ссылка, группы кабинета,
     * действия. Расписание группы сервер здесь не отдаёт, поэтому подсказку
     * показываем только для строк, отрисованных PHP (после F5).
     *
     * @param {Array<Object>} rooms  Кабинеты.
     * @param {Array<Object>} groups Группы с полем room_id.
     * @return {void}
     */
    _renderRows(rooms, groups) {
        const $list = $('#the-list');
        if (!$list.length) { return; }

        if (!rooms.length) {
            $list.html('<tr class="no-items"><td colspan="3">Кабинеты не заданы.</td></tr>');
            return;
        }

        $list.html(rooms.map((room) => {
            const subjects = (room.allowed_subjects || []).join(',');
            const inRoom   = groups
                .filter((g) => Number(g.room_id) === Number(room.id))
                .map((g) => `<span>${escapeHtml(g.name)}</span>`)
                .join(', ');

            return `<tr id="room-row-${room.id}">
                <td class="column-title">
                    <strong>
                        <a class="row-title js-edit-room" href="#"
                            data-id="${room.id}"
                            data-name="${escapeHtml(room.name)}"
                            data-subjects="${escapeHtml(subjects)}">${escapeHtml(room.name)}</a>
                    </strong>
                </td>
                <td>${inRoom || '<span class="fs-dashicon fs-dashicon--muted">—</span>'}</td>
                <td class="column-actions">
                    <div class="row-actions visible">
                        <span class="edit"><a href="#" class="js-edit-room"
                            data-id="${room.id}"
                            data-name="${escapeHtml(room.name)}"
                            data-subjects="${escapeHtml(subjects)}">Изменить</a></span> |
                        <span class="trash"><a href="#" class="js-delete-room"
                            data-id="${room.id}"
                            data-name="${escapeHtml(room.name)}">Удалить</a></span>
                    </div>
                </td>
            </tr>`;
        }).join(''));
    },
};
