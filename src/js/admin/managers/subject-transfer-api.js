/**
 * @module SubjectTransferApi
 * @description Сетевой слой переноса предмета: JSON-выгрузка структуры и
 *              ZIP-пакет со всем содержимым. Только запросы и скачивание —
 *              решения пользователя собирает модалка, уведомления рисует сервис.
 *
 * Два формата живут рядом намеренно: JSON — быстрый снимок структуры и банка,
 * ZIP — полный перенос с медиа (и, по требованию, с учениками).
 *
 * @requires jQuery
 */

import '../_types.js';

const $ = jQuery;

/** Разворачивает ответ WP AJAX в промис: success → data, иначе reject с текстом. */
function unwrap( request ) {
    return request.then(
        ( res ) => ( res && res.success )
            ? res.data
            : Promise.reject( new Error( ( res && ( res.data?.message || res.data ) ) || 'Ошибка запроса' ) ),
        () => Promise.reject( new Error( 'Ошибка сети' ) )
    );
}

/** Обычный POST формы. */
function post( payload ) {
    return unwrap( $.post( fs_lms_vars.ajaxurl, payload ) );
}

/**
 * POST файлом: пакет — бинарник, поэтому processData/contentType отключены.
 */
function postFile( action, nonce, file ) {
    const form = new FormData();
    form.append( 'action', action );
    form.append( 'security', nonce );
    form.append( 'bundle', file );

    return unwrap( $.ajax( {
        url:         fs_lms_vars.ajaxurl,
        method:      'POST',
        data:        form,
        processData: false,
        contentType: false,
    } ) );
}

export const SubjectTransferApi = {

    /** Формат файла по его имени: 'zip' | 'json' | null. */
    detectFormat( fileName ) {
        const name = String( fileName || '' ).toLowerCase();
        if ( name.endsWith( '.zip' ) ) { return 'zip'; }
        if ( name.endsWith( '.json' ) ) { return 'json'; }
        return null;
    },

    // ── Экспорт ───────────────────────────────────────────────

    /** JSON-структура предмета (таксономии, метабоксы, boilerplate, банк заданий и статей). */
    exportJson( key ) {
        return post( {
            action:   fs_lms_vars.ajax_actions.exportSubject,
            key,
            security: fs_lms_vars.nonces.subject,
        } );
    },

    /** ZIP-пакет: состав определяется галочками модалки. */
    exportBundle( key, scope ) {
        return post( {
            action:             fs_lms_vars.ajax_actions.exportSubjectBundle,
            key,
            include_curriculum: scope.includeCurriculum ? 1 : 0,
            include_media:      scope.includeMedia ? 1 : 0,
            include_students:   scope.includeStudents ? 1 : 0,
            security:           fs_lms_vars.nonces.subjectBundle,
        } );
    },

    // ── Импорт ────────────────────────────────────────────────

    previewJsonImport( json ) {
        return post( {
            action:   fs_lms_vars.ajax_actions.previewSubjectImport,
            json,
            security: fs_lms_vars.nonces.subject,
        } );
    },

    importJson( json ) {
        return post( {
            action:   fs_lms_vars.ajax_actions.importSubject,
            json,
            security: fs_lms_vars.nonces.subject,
        } );
    },

    previewBundle( file ) {
        return postFile( fs_lms_vars.ajax_actions.previewSubjectBundle, fs_lms_vars.nonces.subjectBundle, file );
    },

    importBundle( file ) {
        return postFile( fs_lms_vars.ajax_actions.importSubjectBundle, fs_lms_vars.nonces.subjectBundle, file );
    },

    // ── Скачивание ────────────────────────────────────────────

    /** Скачивание по готовой ссылке (ZIP лежит на сервере). */
    downloadUrl( url ) {
        if ( ! url ) { return; }

        const a = document.createElement( 'a' );
        a.href = url;
        document.body.appendChild( a );
        a.click();
        document.body.removeChild( a );
    },

    /** Скачивание JSON, собранного в браузере из ответа сервера. */
    downloadJson( payload, fileName ) {
        const blob = new Blob( [ JSON.stringify( payload, null, 2 ) ], { type: 'application/json' } );
        const url  = URL.createObjectURL( blob );
        const a    = document.createElement( 'a' );

        a.href     = url;
        a.download = fileName;
        a.click();

        URL.revokeObjectURL( url );
    },

    /** Читает файл как текст (JSON-ветка импорта). */
    readText( file ) {
        return new Promise( ( resolve, reject ) => {
            const reader = new FileReader();
            reader.onload  = ( ev ) => resolve( String( ev.target.result ) );
            reader.onerror = () => reject( new Error( 'Ошибка чтения файла' ) );
            reader.readAsText( file );
        } );
    },
};
