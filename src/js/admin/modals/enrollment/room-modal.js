/**
 * @module RoomModal
 * @description UI модалки кабинета (Эпик 9) — близнец AcademicPeriodModal.
 *              Поля: название + чекбоксы допустимых предметов. Pub/Sub onSave.
 *
 * @requires jQuery
 * @requires openModal, closeModal, bindEsc, unbindEsc
 */

import { openModal, closeModal, bindEsc, unbindEsc } from '../../modules/modal-base.js';

const $ = jQuery;

export const RoomModal = {
    $modal: null,
    _saveCallbacks: [],
    _initialized: false,
    $idInput: null,
    $nameInput: null,
    $saveBtn: null,
    $titleEl: null,
    $form: null,

    init() {
        if (this._initialized) return;
        this.$modal = $('#fs-room-modal');
        if (!this.$modal.length) return;
        this._initialized = true;
        this.$idInput   = $('#room_id');
        this.$nameInput = $('#room_name');
        this.$saveBtn   = $('#room-submit-btn');
        this.$titleEl   = $('#room-modal-title');
        this.$form      = this.$modal.find('form');
        this._bindEvents();
    },

    _bindEvents() {
        this.$modal.on('click.fs', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .js-modal-close, .fs-close', (e) => {
            e.preventDefault();
            this.close();
        });
        this.$form.on('submit.fs', (e) => {
            e.preventDefault();
            this._saveCallbacks.forEach((cb) => cb(this._collectFormData()));
        });
    },

    onSave(callback) {
        if (typeof callback === 'function') { this._saveCallbacks.push(callback); }
    },

    /**
     * @param {'add'|'edit'} action
     * @param {{id?:string|number, name?:string, subjects?:string}} [data]
     */
    open(action, data = {}) {
        const isUpdate = action === 'edit';
        this.$titleEl.text(isUpdate ? 'Редактировать кабинет' : 'Создать кабинет');
        this.$saveBtn.text(isUpdate ? 'Сохранить изменения' : 'Создать кабинет');

        this._resetForm();
        if (isUpdate) {
            this.$idInput.val(data.id ?? '');
            this.$nameInput.val(data.name ?? '');
            const subs = String(data.subjects ?? '').split(',').map((s) => s.trim()).filter(Boolean);
            this.$modal.find('.room-subject-cb').each((i, el) => { el.checked = subs.includes(el.value); });
        }

        openModal(this.$modal);
        bindEsc('room', () => this.close());
        requestAnimationFrame(() => requestAnimationFrame(() => this.$nameInput.trigger('focus')));
    },

    close() {
        closeModal(this.$modal);
        unbindEsc('room');
        this._resetForm();
    },

    setSaveState(loading) {
        const isUpdate = '' !== this.$idInput.val();
        this.$saveBtn
            .prop('disabled', loading)
            .text(loading ? 'Сохранение...' : (isUpdate ? 'Сохранить изменения' : 'Создать кабинет'));
    },

    _resetForm() {
        this.$idInput.val('');
        this.$nameInput.val('');
        this.$modal.find('.room-subject-cb').prop('checked', false);
    },

    _collectFormData() {
        const subjects = this.$modal.find('.room-subject-cb:checked').map((i, el) => el.value).get();
        return {
            id:       this.$idInput.val().trim(),
            name:     this.$nameInput.val().trim(),
            subjects: subjects,
        };
    },
};
