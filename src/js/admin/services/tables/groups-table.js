/**
 * @fileoverview Управление таблицей учебных групп.
 *
 * @module GroupsTable
 * @requires jQuery
 */

import { escapeHtml, apiError } from '../../modules/utils.js';
import { openModal, closeModal, bindEsc, unbindEsc } from '../../modules/modal-base.js';
import { fsBadge } from '../../modules/ui-helpers.js';

const $ = jQuery;

export const GroupsTable = {
    _initialized: false,

    /** @type {jQuery|null} */
    $studentsModal: null,

    init() {
        if (this._initialized) return;
        if (!$('#fs-group-students-modal').length) return;

        this._initialized = true;
        this.$studentsModal = $('#fs-group-students-modal');
        this._bindEvents();
    },

    _bindEvents() {
        $(document).on('click', '.js-view-group-students', (e) => this._handleViewStudents(e));
        $(document).on('click', '.js-export-groups', () => this._exportAll());

        if (this.$studentsModal.length) {
            this.$studentsModal.on('click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .js-modal-close', (e) => {
                e.preventDefault();
                this._closeStudentsModal();
            });
        }

        this._bindPeriodFilter();
    },

    _handleViewStudents(e) {
        e.preventDefault();
        e.stopPropagation();

        const $btn      = $(e.currentTarget);
        const groupId   = $btn.data('group-id');
        const groupName = $btn.data('group-name');

        this.$studentsModal.find('.fs-lms-modal-title').text('Ученики группы: ' + groupName);
        this.$studentsModal.find('.fs-group-students-count').text('');
        this.$studentsModal.find('.fs-group-students-content').html('<p class="description">Загрузка…</p>');

        openModal(this.$studentsModal);
        bindEsc('group_students', () => this._closeStudentsModal());

        $.post(fs_lms_vars.ajaxurl, {
            action:   fs_lms_vars.ajax_actions.getGroupStudentsDetail,
            group_id: groupId,
            security: fs_lms_vars.nonces.manager,
        })
            .done((res) => {
                if (!res.success) {
                    this.$studentsModal.find('.fs-group-students-content').html('<p class="description">Ошибка загрузки.</p>');
                    return;
                }
                this._renderStudents(res.data);
            })
            .fail(() => {
                this.$studentsModal.find('.fs-group-students-content').html('<p class="description">Ошибка сети.</p>');
                apiError('Failed to load group students');
            });
    },

    _renderStudents(data) {
        const students    = data.students || [];
        const activeCount = data.active_count || 0;

        this.$studentsModal.find('.fs-group-students-count').text('Активных учеников: ' + activeCount);

        if (!students.length) {
            this.$studentsModal.find('.fs-group-students-content').html('<p class="description">В группе нет учеников.</p>');
            return;
        }

        const badgeColor = (key) => {
            if (key === 'active')                            return 'green';
            if (key === 'expelled')                          return 'red';
            if (key === 'finished' || key === 'transferred') return 'gray';
            return 'gray';
        };

        let rows = '';
        students.forEach((s) => {
            rows += `<tr>
                <td>${ escapeHtml(s.name) }</td>
                <td>${ escapeHtml(s.parent_name) }</td>
                <td>${ fsBadge( escapeHtml(s.status), badgeColor(s.status_key) ) }</td>
                <td>${ escapeHtml(s.contract_no) }</td>
            </tr>`;
        });

        const html = `<table class="wp-list-table widefat fixed striped fs-group-students-table">
            <thead><tr>
                <th>ФИО ученика</th>
                <th>ФИО родителя</th>
                <th>Статус</th>
                <th>Номер договора</th>
            </tr></thead>
            <tbody>${ rows }</tbody>
        </table>`;

        this.$studentsModal.find('.fs-group-students-content').html(html);
    },

    _closeStudentsModal() {
        closeModal(this.$studentsModal);
        unbindEsc('group_students');
    },

    _exportAll() {
        $.post(fs_lms_vars.ajaxurl, {
            action:   fs_lms_vars.ajax_actions.exportGroups,
            security: fs_lms_vars.nonces.manager,
        }).done((r) => {
            if (r.success && r.data.url) {
                const a = document.createElement('a');
                a.href = r.data.url;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
            }
        });
    },

};
