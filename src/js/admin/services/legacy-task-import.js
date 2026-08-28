/**
 * @fileoverview Разовый перенос заданий со старой версии сайта (скрытая страница
 *               admin.php?page=fs_lms_legacy_task_import).
 *
 * @module LegacyTaskImport
 * @description Гоняет батчи через LegacyTaskImportCallbacks, пока сервер не вернёт
 *              done:true, копит created/skipped/warnings по всем батчам и рендерит
 *              итоговый отчёт. Один батч — один AJAX-запрос (см. BATCH_SIZE в
 *              LegacyTaskImportCallbacks — держит запрос в пределах max_execution_time).
 *
 * @requires jQuery
 * @requires escapeHtml, showNotice, toggleButton — утилиты
 */

import '../_types.js';
import { escapeHtml, showNotice, toggleButton } from '../modules/utils.js';

const $ = jQuery;

export const LegacyTaskImport = {

    /**
     * Точка входа. Подключается только на странице переноса.
     */
    init() {
        this.$start = $( '#fs-legacy-import-start' );
        if ( ! this.$start.length ) {
            return;
        }

        this.$status   = $( '#fs-legacy-import-status' );
        this.$progress = $( '#fs-legacy-import-progress' );
        this.$report   = $( '#fs-legacy-import-report' );

        this.$start.on( 'click', () => this.start() );
    },

    /** Запускает перенос: узнаёт общее число записей, затем гонит батчи по очереди. */
    start() {
        toggleButton( this.$start, true, 'Перенос…' );
        this.$report.empty();
        this.$status.text( '' );
        this.$progress.prop( { value: 0, max: 100, hidden: false } );

        const params = this.readParams();

        $.post( fs_lms_vars.ajaxurl, {
            action: fs_lms_vars.ajax_actions.legacyTaskImportStatus,
            security: fs_lms_vars.nonces.manager,
        } )
            .done( ( response ) => {
                if ( ! response || ! response.success ) {
                    this.fail( ( response && response.data ) || 'Не удалось получить число записей.' );
                    return;
                }

                const total = Number( response.data.total ) || 0;
                if ( total <= 0 ) {
                    this.fail( 'Файл переноса пуст.' );
                    return;
                }

                this.$progress.prop( 'max', total );
                this.runBatch( params, 0, total, { created: 0, skipped: 0, warnings: [] } );
            } )
            .fail( () => this.fail( 'Ошибка сети при запросе числа записей.' ) );
    },

    /**
     * Собирает параметры предмета/таксономий из формы.
     *
     * @return {{subject_key:string, author_taxonomy:string, year_taxonomy:string, level_taxonomy:string}}
     */
    readParams() {
        return {
            subject_key: $( '#fs-legacy-import-subject' ).val(),
            author_taxonomy: $( '#fs-legacy-import-author-tax' ).val().trim(),
            year_taxonomy: $( '#fs-legacy-import-year-tax' ).val().trim(),
            level_taxonomy: $( '#fs-legacy-import-level-tax' ).val().trim(),
        };
    },

    /**
     * Выполняет один батч и рекурсивно продолжает, пока сервер не вернёт done:true.
     *
     * @param {Object} params Параметры предмета/таксономий.
     * @param {number} offset Смещение текущего батча.
     * @param {number} total  Общее число записей (для прогресс-бара).
     * @param {{created:number, skipped:number, warnings:string[]}} totals Накопленный итог.
     */
    runBatch( params, offset, total, totals ) {
        $.post( fs_lms_vars.ajaxurl, {
            action: fs_lms_vars.ajax_actions.legacyTaskImportBatch,
            security: fs_lms_vars.nonces.manager,
            offset,
            ...params,
        } )
            .done( ( response ) => {
                if ( ! response || ! response.success ) {
                    this.fail( ( response && response.data ) || 'Ошибка переноса.', totals );
                    return;
                }

                const report = response.data;
                totals.created += Number( report.created ) || 0;
                totals.skipped += Number( report.skipped ) || 0;
                totals.warnings.push( ...( Array.isArray( report.warnings ) ? report.warnings : [] ) );

                const nextOffset = Number( report.next_offset ) || total;
                this.$progress.prop( 'value', Math.min( nextOffset, total ) );
                this.$status.text( `${ Math.min( nextOffset, total ) } / ${ total }` );

                if ( report.done ) {
                    this.finish( totals );
                } else {
                    this.runBatch( params, nextOffset, total, totals );
                }
            } )
            .fail( () => this.fail( 'Ошибка сети при переносе батча.', totals ) );
    },

    /**
     * Завершает перенос успешно: рендерит итоговый отчёт.
     *
     * @param {{created:number, skipped:number, warnings:string[]}} totals Итог по всем батчам.
     */
    finish( totals ) {
        toggleButton( this.$start, false );
        this.$status.text( 'Готово' );
        this.renderReport( totals );
    },

    /**
     * Прерывает перенос из-за ошибки: показывает уведомление и то, что успело накопиться.
     *
     * @param {string} message Текст ошибки.
     * @param {{created:number, skipped:number, warnings:string[]}} [totals] Итог, накопленный до сбоя.
     */
    fail( message, totals = null ) {
        toggleButton( this.$start, false );
        showNotice( message, 'error' );

        if ( totals ) {
            this.renderReport( totals );
        }
    },

    /**
     * Рендерит отчёт переноса: создано/пропущено + список предупреждений.
     *
     * @param {{created:number, skipped:number, warnings:string[]}} totals Итог.
     */
    renderReport( totals ) {
        let html = '<h2 class="fs-import-report__title">Перенос завершён</h2>';
        html += '<ul class="fs-import-report__summary">';
        html += '<li>Создано: <strong>' + totals.created + '</strong></li>';
        html += '<li>Пропущено: <strong>' + totals.skipped + '</strong></li>';
        html += '</ul>';

        if ( totals.warnings.length ) {
            html += '<ul class="fs-import-report__errors">';
            totals.warnings.forEach( ( warning ) => {
                html += '<li>' + escapeHtml( String( warning ) ) + '</li>';
            } );
            html += '</ul>';
        }

        this.$report.html( html );
    },
};
