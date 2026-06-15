/**
 * @fileoverview Импорт учеников из CSV (таб «Импорт» в Настройках).
 *
 * @module ImportCsv
 * @description Отправляет выбранный CSV вместе с предметом/периодом и флагом dry-run
 *              на сервер (FormData), затем рендерит отчёт created/skipped/ошибки.
 *
 * @requires jQuery
 * @requires escapeHtml, showNotice, toggleButton — утилиты
 */

import '../_types.js';
import { escapeHtml, showNotice, toggleButton } from '../modules/utils.js';

const $ = jQuery;

export const ImportCsv = {

    /**
     * Точка входа. Подключается только при наличии формы импорта.
     */
    init() {
        // Контейнер таба — для уведомлений внутри вкладки.
        this.$container = $( '.fs-lms-import' );

        // Кнопка шаблона доступна всегда (даже без предметов/периодов).
        this.bindTemplate();

        this.$form = $( '#fs-lms-import-form' );
        if ( ! this.$form.length ) {
            return;
        }
        this.$report = $( '#fs-import-report' );
        this.$submit = $( '#fs-import-submit' );
        this.bindEvents();
    },

    /**
     * Привязка скачивания шаблона CSV (генерируется на клиенте из заголовков).
     */
    bindTemplate() {
        const $btn = $( '#fs-import-template' );
        if ( ! $btn.length ) {
            return;
        }
        $btn.on( 'click', () => this.downloadTemplate( $btn ) );
    },

    /**
     * Генерирует и скачивает CSV-шаблон (BOM + строка заголовков, разделитель «;»).
     *
     * @param {jQuery} $btn Кнопка с data-headers.
     */
    downloadTemplate( $btn ) {
        const headers = String( $btn.data( 'headers' ) || '' );
        if ( '' === headers ) {
            return;
        }

        // data-examples \u2014 JSON-\u043C\u0430\u0441\u0441\u0438\u0432 \u0441\u0442\u0440\u043E\u043A-\u043E\u0431\u0440\u0430\u0437\u0446\u043E\u0432; jQuery \u043C\u043E\u0436\u0435\u0442 \u0432\u0435\u0440\u043D\u0443\u0442\u044C \u0435\u0433\u043E
        // \u0443\u0436\u0435 \u0440\u0430\u0441\u043F\u0430\u0440\u0441\u0435\u043D\u043D\u044B\u043C (\u043C\u0430\u0441\u0441\u0438\u0432) \u043B\u0438\u0431\u043E \u0441\u0442\u0440\u043E\u043A\u043E\u0439 \u2014 \u043E\u0431\u0440\u0430\u0431\u0430\u0442\u044B\u0432\u0430\u0435\u043C \u043E\u0431\u0430 \u0441\u043B\u0443\u0447\u0430\u044F.
        let examples = $btn.data( 'examples' ) || [];
        if ( 'string' === typeof examples ) {
            try {
                examples = JSON.parse( examples );
            } catch {
                examples = [];
            }
        }

        let csv = '\uFEFF' + headers + '\r\n';
        ( Array.isArray( examples ) ? examples : [] ).forEach( ( rowValues ) => {
            if ( Array.isArray( rowValues ) ) {
                csv += rowValues.join( ';' ) + '\r\n';
            }
        } );

        const blob = new Blob( [ csv ], { type: 'text/csv;charset=utf-8;' } );
        const url = URL.createObjectURL( blob );
        const link = document.createElement( 'a' );

        link.href = url;
        link.download = 'fs-lms-import-template.csv';
        document.body.appendChild( link );
        link.click();
        link.remove();
        URL.revokeObjectURL( url );
    },

    /**
     * Привязка обработчиков.
     */
    bindEvents() {
        this.$form.on( 'submit', ( e ) => {
            e.preventDefault();
            this.submit();
        } );
    },

    /**
     * Собирает FormData и отправляет запрос импорта.
     */
    submit() {
        const fileInput = document.getElementById( 'fs-import-csv-file' );
        if ( ! fileInput || ! fileInput.files.length ) {
            showNotice( 'Выберите CSV-файл.', 'error', this.$container );
            return;
        }

        const $subject = $( '#fs-import-subject' );
        const $period  = $( '#fs-import-period' );

        this._subjectName = $subject.find( 'option:selected' ).text().trim();
        this._periodName  = $period.find( 'option:selected' ).text().trim();

        const data = new FormData();
        data.append( 'action', fs_lms_vars.ajax_actions.importStudentsCsv );
        data.append( 'security', fs_lms_vars.nonces.manager );
        data.append( 'subject_key', $subject.val() );
        data.append( 'period_id', $period.val() );
        data.append( 'dry_run', $( '#fs-import-dry-run' ).is( ':checked' ) ? '1' : '0' );
        data.append( 'file', fileInput.files[ 0 ] );

        toggleButton( this.$submit, true, 'Импорт…' );
        this.$report.prop( 'hidden', true ).empty();

        $.ajax( {
            url: fs_lms_vars.ajaxurl,
            method: 'POST',
            data,
            processData: false,
            contentType: false,
        } )
            .done( ( response ) => {
                if ( response && response.success ) {
                    this.renderReport( response.data, this._subjectName, this._periodName );
                } else {
                    showNotice( ( response && response.data ) || 'Ошибка импорта.', 'error', this.$container );
                }
            } )
            .fail( () => showNotice( 'Ошибка сети при импорте.', 'error', this.$container ) )
            .always( () => toggleButton( this.$submit, false ) );
    },

    /**
     * Рендерит отчёт импорта.
     *
     * @param {{created:number, skipped:number, errors:Object, dry_run:boolean}} report   Отчёт.
     * @param {string} subjectName Название предмета.
     * @param {string} periodName  Название периода.
     */
    renderReport( report, subjectName = '', periodName = '' ) {
        const errors = report.errors || {};
        const rows = Object.keys( errors );
        const mode = report.dry_run ? 'Проверка (dry-run)' : 'Импорт';

        let html = '<h2 class="fs-import-report__title">' + escapeHtml( mode ) + ' завершён</h2>';
        html += '<ul class="fs-import-report__summary">';
        if ( subjectName ) {
            html += '<li>Предмет: <strong>' + escapeHtml( subjectName ) + '</strong></li>';
        }
        if ( periodName ) {
            html += '<li>Период: <strong>' + escapeHtml( periodName ) + '</strong></li>';
        }
        html += '<li>Создано: <strong>' + ( report.created || 0 ) + '</strong></li>';
        html += '<li>Пропущено: <strong>' + ( report.skipped || 0 ) + '</strong></li>';
        html += '<li>Ошибок: <strong>' + rows.length + '</strong></li>';
        html += '</ul>';

        if ( rows.length ) {
            html += '<ul class="fs-import-report__errors">';
            rows.forEach( ( row ) => {
                html += '<li>Строка ' + escapeHtml( String( row ) ) + ': ' + escapeHtml( String( errors[ row ] ) ) + '</li>';
            } );
            html += '</ul>';
        }

        this.$report.html( html ).prop( 'hidden', false );
    },
};
