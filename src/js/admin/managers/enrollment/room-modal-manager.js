/**
 * @module RoomModalManager
 * @description Оркестратор модалки кабинета (Эпик 9) — близнец AcademicPeriodModalManager.
 *              Биндит «+»/изменить/удалить, шлёт AJAX (save_room/delete_room), после успеха
 *              перезагружает страницу (таблица рендерится сервером, как у периодов).
 */

import { RoomModal } from '../../modals/enrollment/room-modal.js';
import { ConfirmModal } from '../../modals/confirm-modal.js';
import { showNotice } from '../../modules/utils.js';

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
                    window.location.reload();
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
            }).done((res) => { if (res && res.success) { window.location.reload(); } });
        });
    },
};
