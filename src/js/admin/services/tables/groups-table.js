/**
 * @fileoverview Управление таблицей учебных групп.
 *
 * @module GroupsTable
 * @requires jQuery
 */

import { escapeHtml, apiError, showNotice } from '../../modules/utils.js';
import { openModal, closeModal, bindEsc, unbindEsc } from '../../modules/modal-base.js';
import { fsBadge } from '../../modules/ui-helpers.js';

const $ = jQuery;

export const GroupsTable = {
    _initialized: false,

    /** @type {jQuery|null} */
    $studentsModal: null,

    /** @type {number} ID группы, открытой в модалке состава */
    _groupId: 0,

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

            // Эпик 15: добавление существующих учеников в открытую группу.
            this.$studentsModal.on('click', '.js-add-student-search', () => this._searchStudents());
            this.$studentsModal.on('keydown', '.js-add-student-query', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this._searchStudents();
                }
            });
            this.$studentsModal.on('change', '.js-add-student-cb', () => this._syncSubmitState());
            this.$studentsModal.on('click', '.js-add-students-submit', () => this._submitAddStudents());
        }

        this._bindPeriodFilter();
    },

    _handleViewStudents(e) {
        e.preventDefault();
        e.stopPropagation();

        const $btn      = $(e.currentTarget);
        const groupName = $btn.data('group-name');
        const isOpen    = $btn.data('access-mode') === 'open';

        this._groupId = $btn.data('group-id');

        this.$studentsModal.find('.fs-lms-modal-title').text('Ученики группы: ' + groupName);
        this.$studentsModal.find('.fs-group-students-count').text('');

        // Панель добавления — только для открытых групп (лёгкая запись без документов).
        const $addPanel = this.$studentsModal.find('.fs-group-add-students');
        $addPanel.toggleClass('hidden', !isOpen);
        $addPanel.find('.js-add-student-query').val('');
        $addPanel.find('.fs-group-add-students__results').empty();
        this._syncSubmitState();

        openModal(this.$studentsModal);
        bindEsc('group_students', () => this._closeStudentsModal());

        this._loadStudents();
    },

    /** Загружает состав группы в модалку (первый показ и обновление после добавления). */
    _loadStudents() {
        this.$studentsModal.find('.fs-group-students-content').html('<p class="description">Загрузка…</p>');

        $.post(fs_lms_vars.ajaxurl, {
            action:   fs_lms_vars.ajax_actions.getGroupStudentsDetail,
            group_id: this._groupId,
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

    /** Поиск учеников для добавления (уже активные в группе отфильтрованы сервером). */
    _searchStudents() {
        const $results = this.$studentsModal.find('.fs-group-add-students__results');
        $results.html('<p class="description">Поиск…</p>');

        $.post(fs_lms_vars.ajaxurl, {
            action:   fs_lms_vars.ajax_actions.searchStudentsForGroup,
            group_id: this._groupId,
            query:    this.$studentsModal.find('.js-add-student-query').val() || '',
            security: fs_lms_vars.nonces.manager,
        })
            .done((res) => {
                if (!res.success) {
                    $results.html('<p class="description">Ошибка поиска.</p>');
                    return;
                }
                const students = res.data || [];
                if (!students.length) {
                    $results.html('<p class="description">Никого не найдено (или все уже в группе).</p>');
                    this._syncSubmitState();
                    return;
                }
                const items = students.map((s) => `<label>
                    <input type="checkbox" class="js-add-student-cb" value="${ Number(s.person_id) }">
                    ${ escapeHtml(s.name) }
                </label>`).join('');
                $results.html(items);
                this._syncSubmitState();
            })
            .fail(() => {
                $results.html('<p class="description">Ошибка сети.</p>');
                apiError('Failed to search students');
            });
    },

    /** Кнопка «Добавить выбранных» активна только при отмеченных учениках. */
    _syncSubmitState() {
        const checked = this.$studentsModal.find('.js-add-student-cb:checked').length;
        this.$studentsModal.find('.js-add-students-submit')
            .prop('disabled', !checked)
            .text(checked ? `Добавить выбранных (${checked})` : 'Добавить выбранных');
    },

    _submitAddStudents() {
        const ids = this.$studentsModal.find('.js-add-student-cb:checked')
            .map((_, el) => el.value)
            .get()
            .join(',');
        if (!ids) return;

        const $submit = this.$studentsModal.find('.js-add-students-submit');
        $submit.prop('disabled', true).text('Добавление…');

        $.post(fs_lms_vars.ajaxurl, {
            action:             fs_lms_vars.ajax_actions.addStudentsToOpenGroup,
            group_id:           this._groupId,
            student_person_ids: ids,
            security:           fs_lms_vars.nonces.manager,
        })
            .done((res) => {
                if (!res.success) {
                    showNotice(res.data?.message || res.data || 'Ошибка добавления.', 'error', this.$studentsModal.find('.fs-lms-modal-body'));
                    this._syncSubmitState();
                    return;
                }
                this.$studentsModal.find('.fs-group-add-students__results').empty();
                this._syncSubmitState();
                this._loadStudents();
            })
            .fail(() => {
                apiError('Failed to add students to open group');
                this._syncSubmitState();
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
