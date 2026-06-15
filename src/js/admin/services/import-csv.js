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
        this.$form = $( '#fs-lms-import-form' );
        if ( ! this.$form.length ) {
            return;
        }
        this.$report = $( '#fs-import-report' );
        this.$submit = $( '#fs-import-submit' );
        this.bindEvents();
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
        const fileInput = document.getElementById( 'fs-import-file' );
        if ( ! fileInput || ! fileInput.files.length ) {
            showNotice( 'Выберите CSV-файл.', 'error' );
            return;
        }

        const data = new FormData();
        data.append( 'action', fs_lms_vars.ajax_actions.importStudentsCsv );
        data.append( 'security', fs_lms_vars.nonces.manager );
        data.append( 'subject_key', $( '#fs-import-subject' ).val() );
        data.append( 'period_id', $( '#fs-import-period' ).val() );
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
                    this.renderReport( response.data );
                } else {
                    showNotice( ( response && response.data ) || 'Ошибка импорта.', 'error' );
                }
            } )
            .fail( () => showNotice( 'Ошибка сети при импорте.', 'error' ) )
            .always( () => toggleButton( this.$submit, false ) );
    },

    /**
     * Рендерит отчёт импорта.
     *
     * @param {{created:number, skipped:number, errors:Object, dry_run:boolean}} report Отчёт.
     */
    renderReport( report ) {
        const errors = report.errors || {};
        const rows = Object.keys( errors );
        const mode = report.dry_run ? 'Проверка (dry-run)' : 'Импорт';

        let html = '<h2 class="fs-import-report__title">' + mode + ' завершён</h2>';
        html += '<ul class="fs-import-report__summary">';
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
