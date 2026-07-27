/**
 * @fileoverview Импорт учеников из CSV (таб «Импорт» в Настройках).
 *
 * @module ImportCsv
 * @description Отправляет выбранный CSV вместе с предметом/периодом, режимом импорта
 *              (архив / полное зачисление), флагами dry-run и «отправить письма»
 *              на сервер (FormData), затем рендерит отчёт created/skipped/ошибки.
 *              В режиме полного зачисления отчёт дополняется таблицей созданных
 *              логинов/паролей с выгрузкой в CSV.
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
     * Текущий режим импорта: значение выбранного радио, фолбэк — архив
     * (когда формы нет, доступна только кнопка шаблона).
     *
     * @return {string} 'archive' | 'enrolled'
     */
    currentMode() {
        const mode = this.$container.find( 'input[name="mode"]:checked' ).val();
        return 'enrolled' === mode ? 'enrolled' : 'archive';
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
     * Генерирует и скачивает CSV-шаблон выбранного режима
     * (BOM + строка заголовков + строки-образцы, разделитель «;»).
     *
     * @param {jQuery} $btn Кнопка с data-headers-{mode} / data-examples-{mode}.
     */
    downloadTemplate( $btn ) {
        const mode = this.currentMode();
        const headers = String( $btn.data( 'headers-' + mode ) || '' );
        if ( '' === headers ) {
            return;
        }

        // data-examples-{mode} — JSON-массив строк-образцов; jQuery может вернуть его
        // уже распарсенным (массив) либо строкой — обрабатываем оба случая.
        let examples = $btn.data( 'examples-' + mode ) || [];
        if ( 'string' === typeof examples ) {
            try {
                examples = JSON.parse( examples );
            } catch {
                examples = [];
            }
        }

        let csv = headers + '\r\n';
        ( Array.isArray( examples ) ? examples : [] ).forEach( ( rowValues ) => {
            if ( Array.isArray( rowValues ) ) {
                csv += rowValues.join( ';' ) + '\r\n';
            }
        } );

        this.downloadCsv( 'fs-lms-import-template-' + mode + '.csv', csv );
    },

    /**
     * Скачивает CSV-содержимое файлом (добавляет BOM для Excel).
     *
     * @param {string} filename Имя файла.
     * @param {string} csv      Содержимое без BOM.
     */
    downloadCsv( filename, csv ) {
        const blob = new Blob( [ '\uFEFF' + csv ], { type: 'text/csv;charset=utf-8;' } );
        const url = URL.createObjectURL( blob );
        const link = document.createElement( 'a' );

        link.href = url;
        link.download = filename;
        document.body.appendChild( link );
        link.click();
        link.remove();
        URL.revokeObjectURL( url );
    },

    /**
     * Экранирует значение ячейки CSV (кавычки — при «;», кавычках, переводах строк).
     *
     * @param {*} value Значение.
     * @return {string} Ячейка CSV.
     */
    csvCell( value ) {
        const s = String( value ?? '' );
        return /[";\r\n]/.test( s ) ? '"' + s.replace( /"/g, '""' ) + '"' : s;
    },

    /**
     * Привязка обработчиков.
     */
    bindEvents() {
        this.$form.on( 'submit', ( e ) => {
            e.preventDefault();
            this.submit();
        } );

        this.$form.find( 'input[name="mode"]' ).on( 'change', () => this.applyMode() );
        this.applyMode();
    },

    /**
     * Показывает элементы выбранного режима: чекбокс писем — только для
     * полного зачисления, заметка про колонки отчисления — только для архива.
     */
    applyMode() {
        const enrolled = 'enrolled' === this.currentMode();
        $( '#fs-import-send-emails-field' ).prop( 'hidden', ! enrolled );
        $( '#fs-import-archive-note' ).prop( 'hidden', enrolled );
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
        data.append( 'mode', this.currentMode() );
        data.append( 'send_emails', $( '#fs-import-send-emails' ).is( ':checked' ) ? '1' : '0' );
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
     * @param {{created:number, skipped:number, errors:Object, dry_run:boolean, credentials:Array<Object>}} report Отчёт.
     * @param {string} subjectName Название предмета.
     * @param {string} periodName  Название периода.
     */
    renderReport( report, subjectName = '', periodName = '' ) {
        const errors = report.errors || {};
        const rows = Object.keys( errors );
        const mode = report.dry_run ? 'Проверка (dry-run)' : 'Импорт';
        const credentials = Array.isArray( report.credentials ) ? report.credentials : [];

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

        if ( credentials.length ) {
            html += this.renderCredentials( credentials );
        }

        this.$report.html( html ).prop( 'hidden', false );

        this.$report.find( '#fs-import-creds-download' )
            .on( 'click', () => this.downloadCredentials( credentials ) );
    },

    /**
     * Таблица созданных учётных данных + кнопка выгрузки в CSV.
     *
     * @param {Array<{student_name:string, student_login:string, student_password:string, parent_login:?string, parent_password:?string}>} credentials Креды строк.
     * @return {string} HTML.
     */
    renderCredentials( credentials ) {
        let html = '<h3 class="fs-import-report__title">Учётные данные</h3>';
        html += '<p><button type="button" class="button" id="fs-import-creds-download">';
        html += '<span class="dashicons dashicons-download"></span> Скачать логины и пароли (CSV)</button></p>';
        html += '<table class="widefat striped fs-import-report__creds"><thead><tr>';
        html += '<th>Ученик</th><th>Логин</th><th>Пароль</th><th>Родитель: логин</th><th>Родитель: пароль</th>';
        html += '</tr></thead><tbody>';

        credentials.forEach( ( c ) => {
            html += '<tr>';
            html += '<td>' + escapeHtml( String( c.student_name || '' ) ) + '</td>';
            html += '<td>' + escapeHtml( String( c.student_login || '' ) ) + '</td>';
            html += '<td>' + escapeHtml( String( c.student_password || '' ) ) + '</td>';
            html += '<td>' + escapeHtml( String( c.parent_login || '' ) ) + '</td>';
            html += '<td>' + escapeHtml( String( c.parent_password || '' ) ) + '</td>';
            html += '</tr>';
        } );

        html += '</tbody></table>';
        return html;
    },

    /**
     * Скачивает таблицу учётных данных CSV-файлом (BOM + «;», как шаблон).
     *
     * @param {Array<Object>} credentials Креды строк.
     */
    downloadCredentials( credentials ) {
        let csv = 'Ученик;Логин;Пароль;Родитель: логин;Родитель: пароль\r\n';
        credentials.forEach( ( c ) => {
            csv += [
                this.csvCell( c.student_name ),
                this.csvCell( c.student_login ),
                this.csvCell( c.student_password ),
                this.csvCell( c.parent_login ),
                this.csvCell( c.parent_password ),
            ].join( ';' ) + '\r\n';
        } );

        this.downloadCsv( 'fs-lms-import-credentials.csv', csv );
    },
};
