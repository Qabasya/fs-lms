/**
 * @fileoverview Разовый перенос заданий со старой версии сайта (скрытая страница
 *               admin.php?page=fs_lms_legacy_task_import).
 *
 * @module LegacyTaskImport
 * @description Читает выбранный JSON-файл в браузере и отправляет записи батчами
 *              через LegacyTaskImportCallbacks, копит created/skipped/warnings по всем
 *              батчам и рендерит итоговый отчёт. Один батч — один AJAX-запрос; размер
 *              батча отдаёт сервер в data-batch-size (LegacyTaskImportService::BATCH_SIZE).
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

        this.$file     = $( '#fs-legacy-import-file' );
        this.$subject  = $( '#fs-legacy-import-subject' );
        this.$status   = $( '#fs-legacy-import-status' );
        this.$progress = $( '#fs-legacy-import-progress' );
        this.$report   = $( '#fs-legacy-import-report' );
        this.batchSize = Number( this.$start.data( 'batch-size' ) ) || 15;

        this.$start.on( 'click', () => this.start() );
        this.$subject.on( 'change', () => this.fillTaxonomySelects() );
        this.fillTaxonomySelects();
    },

    /**
     * Заполняет списки таксономий автора/года/сложности таксономиями выбранного предмета.
     * По умолчанию выбирается таксономия со слагом «{ключ}_{суффикс}», если она у предмета есть.
     */
    fillTaxonomySelects() {
        const subjectKey = this.$subject.val() || '';
        const all        = this.$subject.data( 'taxonomies' ) || {};
        const taxonomies = Array.isArray( all[ subjectKey ] ) ? all[ subjectKey ] : [];

        $( '.js-legacy-import-tax' ).each( ( _, select ) => {
            const suffix   = String( $( select ).data( 'suffix' ) || '' );
            const expected = `${ subjectKey }_${ suffix }`;
            const options  = [ `<option value="">По умолчанию (${ escapeHtml( expected ) })</option>` ];

            taxonomies.forEach( ( tax ) => {
                options.push( `<option value="${ escapeHtml( tax.slug ) }">${ escapeHtml( tax.name ) } (${ escapeHtml( tax.slug ) })</option>` );
            } );

            $( select ).html( options.join( '' ) );

            if ( taxonomies.some( ( tax ) => tax.slug === expected ) ) {
                $( select ).val( expected );
            }
        } );
    },

    /** Запускает перенос: читает файл, затем гонит батчи по очереди. */
    start() {
        const file = this.$file.prop( 'files' )?.[ 0 ];
        if ( ! file ) {
            showNotice( 'Выберите файл переноса.', 'error' );
            return;
        }

        toggleButton( this.$start, true, 'Перенос…' );
        this.$file.prop( 'disabled', true );
        this.$report.empty();
        this.$status.text( 'Чтение файла…' );
        this.$progress.prop( { value: 0, max: 100, hidden: true } );

        file.text()
            .then( ( text ) => {
                const rows = this.parseRows( text );
                if ( ! rows ) {
                    this.fail( 'Файл не является JSON-массивом записей.' );
                    return;
                }

                if ( ! rows.length ) {
                    this.fail( 'Файл переноса пуст.' );
                    return;
                }

                this.$progress.prop( { max: rows.length, hidden: false } );
                this.runBatch( this.readParams(), rows, 0, { created: 0, skipped: 0, warnings: [] } );
            } )
            .catch( () => this.fail( 'Не удалось прочитать файл.' ) );
    },

    /**
     * Разбирает содержимое файла.
     *
     * @param {string} text Текст файла.
     * @return {Array|null} Массив записей; null — не JSON или не массив.
     */
    parseRows( text ) {
        try {
            const rows = JSON.parse( text );
            return Array.isArray( rows ) ? rows : null;
        } catch ( e ) {
            return null;
        }
    },

    /**
     * Собирает параметры предмета/таксономий из формы.
     *
     * @return {{subject_key:string, author_taxonomy:string, year_taxonomy:string, level_taxonomy:string}}
     */
    readParams() {
        return {
            subject_key: this.$subject.val(),
            author_taxonomy: $( '#fs-legacy-import-author-tax' ).val() || '',
            year_taxonomy: $( '#fs-legacy-import-year-tax' ).val() || '',
            level_taxonomy: $( '#fs-legacy-import-level-tax' ).val() || '',
        };
    },

    /**
     * Отправляет один батч и рекурсивно продолжает, пока записи не кончатся.
     *
     * @param {Object} params Параметры предмета/таксономий.
     * @param {Array}  rows   Все записи файла.
     * @param {number} offset Позиция первой записи батча.
     * @param {{created:number, skipped:number, warnings:string[]}} totals Накопленный итог.
     */
    runBatch( params, rows, offset, totals ) {
        const batch = rows.slice( offset, offset + this.batchSize );

        $.post( fs_lms_vars.ajaxurl, {
            action: fs_lms_vars.ajax_actions.legacyTaskImportBatch,
            security: fs_lms_vars.nonces.manager,
            offset,
            rows: JSON.stringify( batch ),
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

                const nextOffset = offset + batch.length;
                this.$progress.prop( 'value', nextOffset );
                this.$status.text( `${ nextOffset } / ${ rows.length }` );

                if ( nextOffset >= rows.length ) {
                    this.finish( totals );
                } else {
                    this.runBatch( params, rows, nextOffset, totals );
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
        this.unlock();
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
        this.unlock();
        showNotice( message, 'error' );

        if ( totals ) {
            this.renderReport( totals );
        } else {
            this.$status.text( '' );
        }
    },

    /** Возвращает форму в исходное состояние после завершения или сбоя. */
    unlock() {
        toggleButton( this.$start, false );
        this.$file.prop( 'disabled', false );
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
