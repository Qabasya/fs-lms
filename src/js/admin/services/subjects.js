import {Utils} from '../modules/utils.js';

export const Subjects = {
    init() {
        this.bindEvents();
    },

    bindEvents() {
        const $ = jQuery;

        $('#fs-add-subject-form').on('submit', (e) => this.handleSave(e));

        $(document).on('click', '.open-quick-edit', (e) => this.handleQuickEdit(e));
        $(document).on('click', '.delete-subject', (e) => this.handleDelete(e));
        $(document).on('click', '.js-export-subject', (e) => this.handleRowExport(e));

        $('#fs-import-trigger').on('click', () => $('#fs-import-file').trigger('click'));
        $('#fs-import-file').on('change', (e) => this.handleImport(e));
    },

    handleSave(e) {
        e.preventDefault();
        const $form = jQuery(e.target);
        const $btn = $form.find('.button-primary');

        Utils.toggleButton($btn, true, 'Сохранение...');

        jQuery.post(ajaxurl, $form.serialize() + '&action=store_subject', (res) => {
            if (res.success) location.reload();
            else {
                alert(res.data || 'Ошибка сохранения');
                Utils.toggleButton($btn, false);
            }
        }).fail(Utils.apiError);
    },

    handleQuickEdit(e) {
        e.preventDefault();
        const $btn = jQuery(e.target);
        const data = $btn.data();
        const $row = $btn.closest('tr');

        const $editRow = jQuery('#fs-quick-edit-row').clone().show();
        $editRow.find('input[name="name"]').val(data.name);
        $editRow.find('input[name="tasks_count"]').val(data.count);
        $editRow.find('input[name="key"]').val(data.key);

        $row.hide().after($editRow);

        $editRow.find('.cancel').on('click', () => {
            $editRow.remove();
            $row.show();
        });

        $editRow.find('#fs-quick-edit-form').on('submit', (event) => {
            event.preventDefault();
            const $saveBtn = $editRow.find('.save');
            Utils.toggleButton($saveBtn, true, '...');

            jQuery.post(ajaxurl, jQuery(event.target).serialize() + '&action=update_subject', (res) => {
                if (res.success) location.reload();
                else alert('Ошибка');
            }).fail(Utils.apiError);
        });
    },

    handleDelete(e) {
        e.preventDefault();
        const $btn = jQuery(e.target);
        const key = $btn.data('key');
        const $row = $btn.closest('tr');
        const name = $row.find('strong a').text().trim();
        const security = this._nonce();

        this._showWarningModal(name, key, security, $btn, $row);
    },

    handleRowExport(e) {
        e.preventDefault();
        const $link = jQuery(e.target);
        const key = $link.data('key');
        this._exportSubject(key, this._nonce(), $link);
    },

    handleImport(e) {
        const file = e.target.files[0];
        if (!file) return;

        e.target.value = '';

        const reader = new FileReader();
        reader.onload = (ev) => {
            let data;
            try {
                data = JSON.parse(ev.target.result);
            } catch (_) {
                alert('Не удалось прочитать файл. Убедитесь, что это корректный JSON.');
                return;
            }

            const name = data?.subject?.name || data?.subject?.key || 'предмет';

            const $modal = this._createModal(
                `<p>Импортировать <strong>${name}</strong>?</p>` +
                `<p>Будут восстановлены: таксономии, термины, шаблоны, boilerplates и записи.</p>` +
                `<div class="fs-modal-actions">` +
                    `<button class="button" data-action="cancel">Отмена</button>` +
                    `<button class="button button-primary" data-action="confirm">Импортировать</button>` +
                `</div>`
            );

            $modal.find('[data-action="cancel"]').on('click', () => $modal.remove());

            $modal.find('[data-action="confirm"]').on('click', (ev2) => {
                const $btn = jQuery(ev2.target);
                Utils.toggleButton($btn, true, 'Импорт...');

                jQuery.post(ajaxurl, {
                    action: 'import_subject',
                    json: ev.target.result,
                    security: this._nonce(),
                }, (res) => {
                    $modal.remove();
                    if (res.success) {
                        location.reload();
                    } else {
                        alert(res.data || 'Ошибка импорта');
                    }
                }).fail(() => {
                    $modal.remove();
                    Utils.apiError();
                });
            });
        };

        reader.readAsText(file);
    },

    _showWarningModal(name, key, security, $btn, $row) {
        const $modal = this._createModal(
            `<p>Вы собираетесь удалить предмет <strong>${name}</strong>.</p>` +
            `<p>Будут безвозвратно удалены все связанные таксономии, термины, привязки шаблонов, boilerplates и записи.</p>` +
            `<p>Рекомендуем экспортировать данные перед удалением.</p>` +
            `<div class="fs-modal-actions">` +
                `<button class="button" data-action="cancel">Отмена</button>` +
                `<button class="button button-secondary" data-action="export">Экспорт</button>` +
                `<button class="button" data-action="proceed" style="background:#d63638;border-color:#d63638;color:#fff;">Удалить всё равно</button>` +
            `</div>`
        );

        $modal.find('[data-action="cancel"]').on('click', () => $modal.remove());

        $modal.find('[data-action="export"]').on('click', (ev) => {
            this._exportSubject(key, security, jQuery(ev.target));
        });

        $modal.find('[data-action="proceed"]').on('click', () => {
            $modal.remove();
            this._showConfirmModal(name, key, security, $btn, $row);
        });
    },

    _showConfirmModal(name, key, security, $btn, $row) {
        const $modal = this._createModal(
            `<p><strong>Точно удалить «${name}»?</strong></p>` +
            `<p>Это действие необратимо.</p>` +
            `<div class="fs-modal-actions">` +
                `<button class="button" data-action="cancel">Отмена</button>` +
                `<button class="button" data-action="confirm" style="background:#d63638;border-color:#d63638;color:#fff;">Точно удалить предмет</button>` +
            `</div>`
        );

        $modal.find('[data-action="cancel"]').on('click', () => $modal.remove());

        $modal.find('[data-action="confirm"]').on('click', () => {
            $modal.remove();
            this._doDelete(key, security, $btn, $row);
        });
    },

    _createModal(content) {
        const $overlay = jQuery(
            `<div class="fs-modal-overlay" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.6);z-index:160000;display:flex;align-items:center;justify-content:center;">` +
                `<div class="fs-modal-box" style="background:#fff;padding:24px;max-width:480px;width:90%;border-radius:4px;box-shadow:0 4px 20px rgba(0,0,0,.3);">` +
                    content +
                `</div>` +
            `</div>`
        );
        jQuery('body').append($overlay);
        return $overlay;
    },

    _exportSubject(key, security, $btn) {
        const origText = $btn.text();
        Utils.toggleButton($btn, true, 'Экспорт...');

        jQuery.post(ajaxurl, {
            action: 'export_subject',
            key: key,
            security: security,
        }, (res) => {
            Utils.toggleButton($btn, false, origText);
            if (!res.success) {
                alert(res.data || 'Ошибка экспорта');
                return;
            }
            const blob = new Blob([JSON.stringify(res.data, null, 2)], {type: 'application/json'});
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'subject_' + key + '_export.json';
            a.click();
            URL.revokeObjectURL(url);
        }).fail(Utils.apiError);
    },

    _doDelete(key, security, $btn, $row) {
        Utils.toggleButton($btn, true, '...');

        jQuery.post(ajaxurl, {
            action: 'delete_subject',
            key: key,
            security: security,
        }, (res) => {
            if (res.success) {
                $row.fadeOut(400, () => {
                    $row.remove();
                    if (jQuery('#tab-1 table.wp-list-table tbody').find('tr').length === 0) location.reload();
                });
            } else {
                Utils.toggleButton($btn, false);
                alert(res.data || 'Ошибка удаления');
            }
        }).fail(Utils.apiError);
    },

    _nonce() {
        return jQuery('#fs-add-subject-form [name="security"]').val()
            || jQuery('#fs-quick-edit-form [name="security"]').val();
    },
};