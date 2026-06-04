import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

export const GroupModal = {
    $modal: null,
    _saveCallbacks: [],
    _initialized: false,

    $form: null,
    $titleInput: null,
    $periodSelect: null,
    $subjectSelect: null,
    $teacherSelect: null,
    $actionInput: null,
    $groupIdInput: null,

    $saveBtn: null,
    $titleEl: null,

    init() {
        if (this._initialized) return;

        this.$modal = $('#fs-lms-group-modal');
        if (!this.$modal.length) return;

        this._initialized = true;
        this._cacheElements();
        this._bindEvents();
    },

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

    _bindEvents() {
        this.$modal.on('click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .js-modal-close, .fs-close', (e) => {
            e.preventDefault();
            this.close();
        });

        this.$titleInput.on('input.fs', () => {
            if (/[a-zA-Zа-яёА-ЯЁ\d]/.test(this.$titleInput.val())) {
                this.$titleInput[0].setCustomValidity('');
            }
        });

        this.$modal.on('change.fs', '.js-schedule-day-cb', (e) => {
            const $cb  = $(e.currentTarget);
            const $row = $cb.closest('.fs-schedule-day-row');
            $row.find('.fs-schedule-day-times').toggleClass('hidden', !$cb.prop('checked'));
        });

        this.$form.on('submit.fs', (e) => {
            e.preventDefault();
            if (!this._validate()) return;
            const formData = this._collectFormData();
            this._saveCallbacks.forEach(cb => cb(formData));
        });
    },

    onSave(callback) {
        if (typeof callback === 'function') {
            this._saveCallbacks.push(callback);
        }
    },

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
        } else {
            this._resetForm();
        }

        openModal(this.$modal);
        bindEsc('student_group', () => this.close());

        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                this.$titleInput.trigger('focus');
            });
        });
    },

    close() {
        closeModal(this.$modal);
        unbindEsc('student_group');
        this._resetForm();
    },

    setSaveState(loading) {
        const isUpdate = this.$actionInput.length && this.$actionInput.val() === 'edit';
        this.$saveBtn
            .prop('disabled', loading)
            .text(loading ? 'Сохранение...' : (isUpdate ? 'Сохранить изменения' : 'Создать группу'));
    },

    _validate() {
        const title = this.$titleInput.val().trim();
        const hasSlugChars = /[a-zA-Zа-яёА-ЯЁ\d]/.test(title);

        if (!hasSlugChars) {
            this.$titleInput[0].setCustomValidity(
                'Название должно содержать хотя бы одну букву или цифру.'
            );
        } else {
            this.$titleInput[0].setCustomValidity('');
        }

        return this.$form[0].checkValidity();
    },

    _resetForm() {
        this.$form[0].reset();
        this.$titleInput[0].setCustomValidity('');
        if (this.$groupIdInput.length) this.$groupIdInput.val('');
        if (this.$actionInput.length) this.$actionInput.val('add');
        this.$modal.find('.fs-schedule-day-times').addClass('hidden');
    },

    _restoreSchedule(schedule) {
        this.$modal.find('.js-schedule-day-cb').prop('checked', false);
        this.$modal.find('.fs-schedule-day-times').addClass('hidden');

        const entries = Array.isArray(schedule) ? schedule : [];

        entries.forEach(({ day, start, end }) => {
            const $row = this.$modal.find(`.fs-schedule-day-row[data-day="${day}"]`);
            if (!$row.length) return;
            $row.find('.js-schedule-day-cb').prop('checked', true);
            $row.find('.js-day-start').val(start || '');
            $row.find('.js-day-end').val(end || '');
            $row.find('.fs-schedule-day-times').removeClass('hidden');
        });
    },

    _collectFormData() {
        const schedule = [];

        this.$modal.find('.fs-schedule-day-row').each((_, row) => {
            const $row = $(row);
            const $cb  = $row.find('.js-schedule-day-cb');
            if (!$cb.prop('checked')) return;
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
            schedule_json: JSON.stringify(schedule),
        };
    },
};