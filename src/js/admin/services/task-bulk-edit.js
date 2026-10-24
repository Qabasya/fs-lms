/**
 * @fileoverview Массовая правка заданий через CSV (Предметы → Менеджер заданий).
 *
 * @module TaskBulkEdit
 * @description Шаблон CSV, проверка загруженного файла («было → стало»), применение и
 *              список снимков партий: откат к снимку и выгрузка снимка в CSV.
 *              Серверная часть — TaskBulkEditCallbacks.
 *
 * @requires jQuery
 * @requires escapeHtml, showNotice, toggleButton — утилиты
 * @requires ConfirmModal — подтверждение применения и отката
 */

import '../_types.js';
import { escapeHtml, showNotice, toggleButton } from '../modules/utils.js';
import { ConfirmModal } from '../modals/confirm-modal.js';

const $ = jQuery;

const STATUS_LABELS = {
    applied: 'Применён',
    rolled_back: 'Откатан',
};

export const TaskBulkEdit = {

    /** Содержимое проверенного файла: применяется ровно оно. */
    csv: '',

    /**
     * Точка входа. Подключается только на вкладке «Менеджер заданий».
     */
    init() {
        this.$root = $( '#fs-task-bulk' );
        if ( ! this.$root.length ) {
            return;
        }

        this.subject    = String( this.$root.data( 'subject' ) || '' );
        this.$file      = $( '#fs-task-bulk-file' );
        this.$preview   = $( '#fs-task-bulk-preview' );
        this.$apply     = $( '#fs-task-bulk-apply' );
        this.$report    = $( '#fs-task-bulk-report' );
        this.$snapshots = $( '#fs-task-bulk-snapshots' );

        $( '#fs-task-bulk-template' ).on( 'click', ( e ) => this.downloadTemplate( $( e.currentTarget ) ) );
        this.$preview.on( 'click', () => this.preview() );
        this.$apply.on( 'click', () => this.confirmApply() );
        this.$file.on( 'change', () => this.resetPreview() );
        this.$snapshots.on( 'click', '.js-task-bulk-csv', ( e ) => this.downloadSnapshot( $( e.currentTarget ) ) );
        this.$snapshots.on( 'click', '.js-task-bulk-rollback', ( e ) => this.confirmRollback( $( e.currentTarget ) ) );

        this.loadSnapshots();
    },

    /**
     * POST в админский AJAX с nonce менеджера.
     *
     * @param {string} action Ключ из fs_lms_vars.ajax_actions.
     * @param {Object} data   Параметры запроса.
     * @return {Promise<Object>} data ответа; reject — текст ошибки.
     */
    post( action, data ) {
        return new Promise( ( resolve, reject ) => {
            $.post( fs_lms_vars.ajaxurl, {
                action: fs_lms_vars.ajax_actions[ action ],
                security: fs_lms_vars.nonces.manager,
                subject_key: this.subject,
                ...data,
            } )
                .done( ( response ) => {
                    if ( response && response.success ) {
                        resolve( response.data );
                    } else {
                        const err = response && response.data;
                        reject( ( err && err.message ) || ( 'string' === typeof err ? err : 'Ошибка запроса.' ) );
                    }
                } )
                .fail( () => reject( 'Ошибка сети.' ) );
        } );
    },

    /** Отдаёт браузеру CSV как файл. */
    saveCsv( filename, csv ) {
        const url  = URL.createObjectURL( new Blob( [ csv ], { type: 'text/csv;charset=utf-8' } ) );
        const link = document.createElement( 'a' );
        link.href     = url;
        link.download = filename;
        document.body.appendChild( link );
        link.click();
        link.remove();
        URL.revokeObjectURL( url );
    },

    downloadTemplate( $btn ) {
        toggleButton( $btn, true, 'Готовим…' );
        this.post( 'taskBulkTemplate', { task_number: $( '#fs-task-bulk-type' ).val() || '' } )
            .then( ( data ) => this.saveCsv( data.filename, data.csv ) )
            .catch( ( message ) => showNotice( message, 'error' ) )
            .finally( () => toggleButton( $btn, false ) );
    },

    /** Новый файл — старый предпросмотр недействителен. */
    resetPreview() {
        this.csv = '';
        this.$apply.prop( 'hidden', true );
        this.$report.empty();
    },

    preview() {
        const file = this.$file.prop( 'files' )?.[ 0 ];
        if ( ! file ) {
            showNotice( 'Выберите CSV-файл.', 'error' );
            return;
        }

        toggleButton( this.$preview, true, 'Проверяем…' );
        this.resetPreview();

        file.text()
            .then( ( text ) => this.post( 'taskBulkPreview', { csv: text } ).then( ( data ) => {
                this.csv = text;
                this.renderReport( data, false );
                this.$apply.prop( 'hidden', 0 === data.changes );
            } ) )
            .catch( ( message ) => showNotice( String( message ), 'error' ) )
            .finally( () => toggleButton( this.$preview, false ) );
    },

    confirmApply() {
        if ( ! this.csv ) {
            return;
        }

        ConfirmModal.confirm( {
            title: 'Применить изменения?',
            message: 'Задания будут изменены. Перед записью сохранится снимок — по нему можно вернуть всё как было.',
            confirmText: 'Применить',
            cancelText: 'Отмена',
            isDanger: false,
        } )
            .then( () => this.apply() )
            .catch( () => {} );
    },

    apply() {
        toggleButton( this.$apply, true, 'Применяем…' );

        this.post( 'taskBulkApply', { csv: this.csv } )
            .then( ( data ) => {
                this.renderReport( data, true );
                this.csv = '';
                this.$apply.prop( 'hidden', true );
                this.$file.val( '' );
                this.loadSnapshots();
            } )
            .catch( ( message ) => showNotice( String( message ), 'error' ) )
            .finally( () => toggleButton( this.$apply, false ) );
    },

    /**
     * Отчёт проверки или применения.
     *
     * @param {Object}  data    Ответ сервера (items, total, changes, errors, unchanged; при применении — applied, snapshot_id, links).
     * @param {boolean} applied true — это итог применения, а не предпросмотр.
     */
    renderReport( data, applied ) {
        let html = '<div class="fs-import-report">';
        html += `<h2 class="fs-import-report__title">${ applied ? 'Изменения применены' : 'Предпросмотр' }</h2>`;
        html += '<ul class="fs-import-report__summary">';
        html += `<li>Строк в файле: <strong>${ data.total }</strong></li>`;
        html += `<li>${ applied ? 'Изменено' : 'Будет изменено' }: <strong>${ applied ? data.applied : data.changes }</strong></li>`;
        html += `<li>Без изменений: <strong>${ data.unchanged }</strong></li>`;
        html += `<li>С ошибками: <strong>${ data.errors }</strong></li>`;
        if ( applied && data.snapshot_id ) {
            html += `<li>Снимок: <strong>№${ data.snapshot_id }</strong></li>`;
        }
        if ( applied && data.links ) {
            html += `<li>Ссылок http → https: <strong>${ data.links }</strong></li>`;
        }
        html += '</ul>';

        const rows = data.items.filter( ( item ) => 'skip' !== item.status );
        if ( rows.length ) {
            html += '<table class="wp-list-table widefat fixed striped fs-task-bulk__table"><thead><tr>';
            html += '<th class="column-line">Строка</th><th class="column-number">Номер</th><th>Что изменится</th></tr></thead><tbody>';
            rows.forEach( ( item ) => {
                const number = item.old === item.new ? item.old : `${ item.old } → ${ item.new }`;
                const notes  = [
                    ...item.changes.map( ( text ) => `<li>${ escapeHtml( text ) }</li>` ),
                    ...item.warnings.map( ( text ) => `<li class="fs-task-bulk__warning">${ escapeHtml( text ) }</li>` ),
                    ...item.errors.map( ( text ) => `<li class="fs-task-bulk__error">${ escapeHtml( text ) }</li>` ),
                ].join( '' );
                const cls = 'error' === item.status ? ' class="fs-task-bulk__row--error"' : '';
                html += `<tr${ cls }><td>${ item.line }</td><td>${ escapeHtml( number ) }</td><td><ul class="fs-task-bulk__notes">${ notes }</ul></td></tr>`;
            } );
            html += '</tbody></table>';
        }

        html += '</div>';
        this.$report.html( html );
    },

    // ------------------------------------------------------------------ снимки

    loadSnapshots() {
        this.post( 'taskBulkSnapshots', {} )
            .then( ( data ) => this.renderSnapshots( data.snapshots ) )
            .catch( ( message ) => this.$snapshots.html( `<tr class="no-items"><td colspan="6">${ escapeHtml( String( message ) ) }</td></tr>` ) );
    },

    renderSnapshots( snapshots ) {
        if ( ! snapshots.length ) {
            this.$snapshots.html( '<tr class="no-items"><td colspan="6">Снимков пока нет.</td></tr>' );
            return;
        }

        this.$snapshots.html( snapshots.map( ( snap ) => {
            const canRollback = 'applied' === snap.status;
            const status      = STATUS_LABELS[ snap.status ] || snap.status;
            const rolledAt    = snap.rolled_back_at ? ` (${ escapeHtml( snap.rolled_back_at ) } UTC)` : '';

            return `<tr data-id="${ snap.id }">
                <td><strong>№${ snap.id }</strong></td>
                <td>${ escapeHtml( snap.created_at ) } UTC</td>
                <td>${ escapeHtml( snap.author ) }</td>
                <td>${ snap.items_count }</td>
                <td>${ escapeHtml( status ) }${ rolledAt }</td>
                <td>
                    <button type="button" class="button button-small js-task-bulk-csv" data-id="${ snap.id }">Скачать CSV</button>
                    ${ canRollback ? `<button type="button" class="button button-small js-task-bulk-rollback" data-id="${ snap.id }">Откатить</button>` : '' }
                </td>
            </tr>`;
        } ).join( '' ) );
    },

    downloadSnapshot( $btn ) {
        toggleButton( $btn, true, '…' );
        this.post( 'taskBulkSnapshotCsv', { snapshot_id: $btn.data( 'id' ) } )
            .then( ( data ) => this.saveCsv( data.filename, data.csv ) )
            .catch( ( message ) => showNotice( String( message ), 'error' ) )
            .finally( () => toggleButton( $btn, false ) );
    },

    confirmRollback( $btn ) {
        const id = Number( $btn.data( 'id' ) );

        ConfirmModal.confirm( {
            title: `Откатить к снимку №${ id }?`,
            message: 'Номера, названия, даты, таксономии и файлы вернутся к состоянию до правки. Задания, изменённые после этой правки, не затрагиваются.',
            confirmText: 'Откатить',
            cancelText: 'Отмена',
            isDanger: true,
        } )
            .then( () => this.rollback( $btn, id ) )
            .catch( () => {} );
    },

    rollback( $btn, id ) {
        toggleButton( $btn, true, 'Откатываем…' );

        this.post( 'taskBulkRollback', { snapshot_id: id } )
            .then( ( data ) => {
                const skipped = data.skipped.length ? ` Пропущено: ${ data.skipped.join( '; ' ) }.` : '';
                showNotice( `Возвращено заданий: ${ data.restored }.${ skipped }`, data.skipped.length ? 'warning' : 'success' );
                this.loadSnapshots();
            } )
            .catch( ( message ) => {
                showNotice( String( message ), 'error' );
                toggleButton( $btn, false );
            } );
    },
};
