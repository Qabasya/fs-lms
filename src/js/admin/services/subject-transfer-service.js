/**
 * @module SubjectTransferService
 * @description Перенос предмета между сайтами — одна кнопка «Экспорт» и одна
 *              «Импортировать предмет» на оба формата (JSON-структура и
 *              ZIP-пакет).
 *
 *              Формат выгрузки выбирается в модалке, формат загрузки
 *              определяется по расширению выбранного файла — администратору не
 *              нужно помнить, какой кнопкой он пользовался при экспорте.
 *
 *              Сборка пакета с медиа занимает время, а импорт необратим, поэтому
 *              обе операции идут через явные шаги: выбор формата → сборка →
 *              скачивание; файл → предпросмотр → подтверждение → импорт. Кнопка
 *              блокируется на всё время запроса: параллельные сборки одного
 *              предмета бессмысленны и только грузят сайт.
 *
 * @requires jQuery
 * @requires BundleExportModal — выбор формата и объёма
 * @requires ConfirmModal — подтверждение импорта по результатам предпросмотра
 * @requires SubjectTransferApi — запросы и скачивание
 */

import '../_types.js';
import { BundleExportModal } from '../modals/bundle-export-modal.js';
import { ConfirmModal } from '../modals/confirm-modal.js';
import { SubjectTransferApi } from '../managers/subject-transfer-api.js';
import { toggleButton, showNotice, escapeHtml } from '../modules/utils.js';

const $ = jQuery;

export const SubjectTransferService = {

    /** @type {boolean} Защита от повторной привязки обработчиков */
    _initialized: false,

    /**
     * Инициализация. Точка входа, вызывается из admin.js.
     */
    init() {
        if ( this._initialized ) { return; }
        this._initialized = true;

        $( document ).on( 'click', '.js-export-subject', ( e ) => {
            e.preventDefault();
            this._handleExport( $( e.currentTarget ) );
        } );

        $( '#fs-import-trigger' ).on( 'click', () => $( '#fs-import-file' ).trigger( 'click' ) );
        $( '#fs-import-file' ).on( 'change', ( e ) => this._handleImport( e ) );
    },

    /** Куда вешать уведомления импорта. */
    _importAnchor() {
        return $( '#fs-import-trigger' ).parent();
    },

    // ── Экспорт ───────────────────────────────────────────────

    /**
     * Выгрузка: выбор формата → запрос → скачивание.
     *
     * @private
     * @param {jQuery} $btn Ссылка «Экспорт» в строке предмета.
     */
    _handleExport( $btn ) {
        const key  = $btn.data( 'key' );
        const name = $btn.data( 'name' ) || key;

        BundleExportModal.confirm( { summary: `Предмет «${ name }» будет выгружен в файл.` } )
            .then( ( scope ) => {
                toggleButton( $btn, true, 'zip' === scope.format ? 'Собираем...' : 'Экспорт...' );

                const request = 'zip' === scope.format
                    ? SubjectTransferApi.exportBundle( key, scope ).then( ( data ) => {
                        SubjectTransferApi.downloadUrl( data.url );
                        this._reportExport( data, $btn );
                    } )
                    : SubjectTransferApi.exportJson( key ).then( ( data ) => {
                        SubjectTransferApi.downloadJson( data, `subject_${ key }_export.json` );
                    } );

                return request
                    .catch( ( err ) => showNotice( err.message, 'error', $btn.closest( 'td' ) ) )
                    .then( () => toggleButton( $btn, false ) );
            } )
            // Отказ в модалке — штатный путь, не ошибка.
            .catch( () => {} );
    },

    /**
     * Показывает замечания сборки пакета (например, пропущенные медиафайлы).
     *
     * @private
     * @param {Object} data Ответ сервера (counts/warnings/size/filename).
     * @param {jQuery} $btn Ссылка, рядом с которой показывать уведомление.
     */
    _reportExport( data, $btn ) {
        if ( ! ( data.warnings || [] ).length ) { return; }

        showNotice(
            `Пакет собран (${ data.filename }), но есть замечания: ${ data.warnings.slice( 0, 5 ).join( '; ' ) }`,
            'warning',
            $btn.closest( 'td' )
        );
    },

    // ── Импорт ────────────────────────────────────────────────

    /**
     * Загрузка: формат определяется по расширению файла, дальше — общий путь
     * «предпросмотр → подтверждение → импорт».
     *
     * @private
     * @param {Event} e Событие change у input[type=file].
     */
    _handleImport( e ) {
        const file = e.target.files[ 0 ];
        if ( ! file ) { return; }

        // Сбрасываем значение, чтобы повторный выбор того же файла снова дал change.
        e.target.value = '';

        const format = SubjectTransferApi.detectFormat( file.name );

        if ( 'zip' === format ) {
            this._importBundle( file );
            return;
        }

        if ( 'json' === format ) {
            this._importJson( file );
            return;
        }

        showNotice( 'Неизвестный формат файла: нужен .json (структура) или .zip (пакет).', 'error', this._importAnchor() );
    },

    /**
     * JSON-ветка: файл читается в браузере, чтобы битый файл отсеялся до запроса.
     *
     * @private
     * @param {File} file Выбранный файл.
     */
    _importJson( file ) {
        SubjectTransferApi.readText( file )
            .then( ( raw ) => {
                try {
                    JSON.parse( raw );
                } catch {
                    return Promise.reject( new Error( 'Не удалось прочитать файл. Убедитесь, что это корректный JSON.' ) );
                }

                return SubjectTransferApi.previewJsonImport( raw ).then( ( preview ) => {
                    this._confirmImport( preview, {
                        title: 'Импорт предмета',
                        run:   () => SubjectTransferApi.importJson( raw ),
                    } );
                } );
            } )
            .catch( ( err ) => showNotice( err.message, 'error', this._importAnchor() ) );
    },

    /**
     * ZIP-ветка: пакет уходит на сервер целиком — там же он и распаковывается.
     *
     * @private
     * @param {File} file Выбранный файл.
     */
    _importBundle( file ) {
        SubjectTransferApi.previewBundle( file )
            .then( ( preview ) => {
                this._confirmImport( preview, {
                    title:   'Импорт пакета предмета',
                    waiting: 'Импортируем пакет — это может занять несколько минут...',
                    run:     () => SubjectTransferApi.importBundle( file ),
                } );
            } )
            .catch( ( err ) => showNotice( err.message, 'error', this._importAnchor() ) );
    },

    /**
     * Подтверждение импорта по результатам dry-run — общее для обоих форматов.
     *
     * @private
     * @param {Object}   preview          Отчёт предпросмотра (counts/collisions/warnings/importable).
     * @param {Object}   options          Параметры ветки.
     * @param {string}   options.title    Заголовок окна подтверждения.
     * @param {string}   [options.waiting] Уведомление на время долгого импорта.
     * @param {Function} options.run      Запуск реального импорта, возвращает промис.
     */
    _confirmImport( preview, { title, waiting = '', run } ) {
        const $anchor  = this._importAnchor();
        const safeName = escapeHtml( preview.subject_name || preview.subject_key || 'предмет' );

        // Блокирующие конфликты: импорт невозможен, показываем причину и выходим.
        if ( ! preview.importable ) {
            ConfirmModal.confirm( {
                title: 'Импорт невозможен',
                message: preview.collisions.join( '\n\n' ),
                size: 'lg',
                isDanger: true,
                confirmText: 'Понятно',
                cancelText: 'Закрыть',
            } ).catch( () => {} );
            return;
        }

        const lines = Object.entries( preview.counts || {} )
            .filter( ( [ , count ] ) => count > 0 )
            .map( ( [ section, count ] ) => `• ${ escapeHtml( section ) }: ${ count }` );

        const warnings = ( preview.warnings || [] ).length
            ? `\n\nОбратите внимание:\n${ preview.warnings.slice( 0, 10 ).join( '\n' ) }`
            : '';

        ConfirmModal.confirm( {
            title,
            message:
                `Импортировать «${ safeName }»?\n\nБудет создано (${ preview.total }):\n${ lines.join( '\n' ) }` +
                `${ warnings }\n\nПри ошибке в середине импорта всё созданное удаляется автоматически.`,
            size: 'lg',
            isDanger: false,
            confirmText: 'Импортировать',
            cancelText: 'Отмена',
        } )
            .then( () => {
                if ( waiting ) { showNotice( waiting, 'info', $anchor ); }

                run()
                    .then( () => location.reload() )
                    .catch( ( err ) => showNotice( err.message, 'error', $anchor ) );
            } )
            .catch( () => {} );
    },
};
