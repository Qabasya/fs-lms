/**
 * @module SubjectBundleService
 * @description Экспорт и импорт полного пакета переноса предмета (Этап 6).
 *
 *              Сборка пакета с медиа занимает время, а импорт необратим —
 *              поэтому обе операции идут через явные шаги: выбор объёма →
 *              сборка → ссылка; загрузка файла → предпросмотр → подтверждение →
 *              импорт. Кнопка блокируется на всё время запроса: параллельно
 *              запущенные сборки одного предмета бессмысленны и только грузят сайт.
 *
 * @requires jQuery
 * @requires BundleExportModal — выбор объёма пакета
 * @requires ConfirmModal — подтверждение импорта по результатам предпросмотра
 */

import '../_types.js';
import { BundleExportModal } from '../modals/bundle-export-modal.js';
import { ConfirmModal } from '../modals/confirm-modal.js';
import { toggleButton, apiError, showNotice, escapeHtml } from '../modules/utils.js';

const $ = jQuery;

export const SubjectBundleService = {

    /** @type {boolean} Защита от повторной привязки обработчиков */
    _initialized: false,

    /**
     * Инициализация. Точка входа, вызывается из admin.js.
     */
    init() {
        if ( this._initialized ) { return; }
        this._initialized = true;

        $( document ).on( 'click', '.js-export-subject-bundle', ( e ) => {
            e.preventDefault();
            this._handleExport( $( e.currentTarget ) );
        } );

        $( '#fs-bundle-import-trigger' ).on( 'click', () => $( '#fs-bundle-import-file' ).trigger( 'click' ) );
        $( '#fs-bundle-import-file' ).on( 'change', ( e ) => this._handleImport( e ) );
    },

    /**
     * Сборка пакета: выбор объёма → запрос → скачивание.
     *
     * @private
     * @param {jQuery} $btn Кнопка «Экспорт пакета».
     */
    _handleExport( $btn ) {
        const key  = $btn.data( 'key' );
        const name = $btn.data( 'name' ) || key;

        BundleExportModal.confirm( { summary: `Предмет «${ name }» будет выгружен одним ZIP-архивом.` } )
            .then( ( scope ) => {
                toggleButton( $btn, true, 'Собираем...' );

                return $.post( fs_lms_vars.ajaxurl, {
                    action:             fs_lms_vars.ajax_actions.exportSubjectBundle,
                    key,
                    include_curriculum: scope.includeCurriculum ? 1 : 0,
                    include_media:      scope.includeMedia ? 1 : 0,
                    include_students:   scope.includeStudents ? 1 : 0,
                    security:           fs_lms_vars.nonces.subjectBundle,
                } )
                    .done( ( res ) => {
                        if ( ! res.success ) {
                            showNotice( res.data || 'Не удалось собрать пакет', 'error', $btn.closest( 'td' ) );
                            return;
                        }
                        this._download( res.data.url );
                        this._reportExport( res.data, $btn );
                    } )
                    .fail( () => apiError( 'Failed to export subject bundle' ) )
                    .always( () => toggleButton( $btn, false ) );
            } )
            // Отказ в модалке — штатный путь, не ошибка.
            .catch( () => {} );
    },

    /**
     * Импорт пакета: предпросмотр → подтверждение → импорт.
     *
     * @private
     * @param {Event} e Событие change у input[type=file].
     */
    _handleImport( e ) {
        const file = e.target.files[ 0 ];
        if ( ! file ) { return; }

        // Сбрасываем значение, чтобы повторный выбор того же файла снова дал change.
        e.target.value = '';

        const $anchor = $( '#fs-bundle-import-trigger' ).parent();

        this._post( fs_lms_vars.ajax_actions.previewSubjectBundle, file )
            .done( ( res ) => {
                if ( ! res.success ) {
                    showNotice( res.data || 'Не удалось прочитать пакет', 'error', $anchor );
                    return;
                }
                this._confirmImport( res.data, file, $anchor );
            } )
            .fail( () => apiError( 'Failed to preview subject bundle' ) );
    },

    /**
     * Подтверждение импорта по результатам предпросмотра.
     *
     * @private
     * @param {Object} preview Отчёт SubjectImportReportDTO.
     * @param {File}   file    Файл пакета для повторной отправки.
     * @param {jQuery} $anchor Куда показывать уведомления.
     */
    _confirmImport( preview, file, $anchor ) {
        const safeName = escapeHtml( preview.subject_name || preview.subject_key || 'предмет' );

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
            title: 'Импорт пакета предмета',
            message:
                `Импортировать «${ safeName }»?\n\nБудет создано (${ preview.total }):\n${ lines.join( '\n' ) }` +
                `${ warnings }\n\nПри ошибке в середине импорта всё созданное удаляется автоматически.`,
            size: 'lg',
            isDanger: false,
            confirmText: 'Импортировать',
            cancelText: 'Отмена',
        } )
            .then( () => {
                showNotice( 'Импортируем пакет — это может занять несколько минут...', 'info', $anchor );

                this._post( fs_lms_vars.ajax_actions.importSubjectBundle, file )
                    .done( ( res ) => {
                        if ( res.success ) {
                            location.reload();
                        } else {
                            showNotice( res.data || 'Ошибка импорта пакета', 'error', $anchor );
                        }
                    } )
                    .fail( () => apiError( 'Failed to import subject bundle' ) );
            } )
            .catch( () => {} );
    },

    /**
     * Показывает сводку собранного пакета и предупреждения экспорта.
     *
     * @private
     * @param {Object} data Ответ сервера (counts/warnings/size/filename).
     * @param {jQuery} $btn Кнопка, рядом с которой показывать уведомление.
     */
    _reportExport( data, $btn ) {
        if ( ! ( data.warnings || [] ).length ) { return; }

        showNotice(
            `Пакет собран (${ data.filename }), но есть замечания: ${ data.warnings.slice( 0, 5 ).join( '; ' ) }`,
            'warning',
            $btn.closest( 'td' )
        );
    },

    /**
     * Отправляет файл пакета на указанное действие.
     *
     * FormData вместо обычного POST: пакет — бинарный файл, а не JSON-строка,
     * поэтому processData/contentType отключены.
     *
     * @private
     * @param {string} action Имя AJAX-действия.
     * @param {File}   file   Файл пакета.
     * @returns {jQuery.jqXHR}
     */
    _post( action, file ) {
        const form = new FormData();
        form.append( 'action', action );
        form.append( 'security', fs_lms_vars.nonces.subjectBundle );
        form.append( 'bundle', file );

        return $.ajax( {
            url:         fs_lms_vars.ajaxurl,
            method:      'POST',
            data:        form,
            processData: false,
            contentType: false,
        } );
    },

    /**
     * Инициирует скачивание по одноразовой ссылке.
     *
     * @private
     * @param {string} url Ссылка на файл.
     */
    _download( url ) {
        if ( ! url ) { return; }

        const a = document.createElement( 'a' );
        a.href = url;
        document.body.appendChild( a );
        a.click();
        document.body.removeChild( a );
    },
};
