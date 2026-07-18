/**
 * Admin-JS модуля VideoLibrary (self-contained, вне core-бандла и вне ESLint src/js).
 * Секция «Видеозаписи занятий (S3)» в табе «Конфигурация»: генератор HMAC-секрета
 * и экспорт groups.yaml для сервиса fs-video-uploader.
 *
 * Привязка записей к занятиям — полностью автоматическая (по дате/времени, см.
 * VideoLessonResolver); ручной разбор списка записей на странице конфигурации
 * намеренно не показывается (при росте числа записей это ломало страницу).
 *
 * Глобал fsLmsVideoLibrary = { ajaxurl, nonce, actions: { save, exportGroups, list, lessons, attach, detach } } —
 * локализуется в VideoLibrarySettingsController.
 */
( function ( $ ) {
	'use strict';

	var VideoLibrary = {
		cfg: null,

		init: function () {
			this.cfg = window.fsLmsVideoLibrary;
			this.bindEvents();
		},

		bindEvents: function () {
			var self  = this;
			var $form = $( '#fs-videolib-form' );

			// Генерация секрета HMAC на клиенте (crypto.getRandomValues) — строка define() для wp-config.php.
			// Копирование — через core-класс .js-copy-key. Секрет в БД не сохраняется.
			$form.on( 'click', '[data-videolib-generate-secret]', function () {
				var bytes = new Uint8Array( 32 );
				( window.crypto || window.msCrypto ).getRandomValues( bytes );
				var hex = '';
				for ( var i = 0; i < bytes.length; i++ ) {
					hex += ( '0' + bytes[ i ].toString( 16 ) ).slice( -2 );
				}
				$( '#fs-videolib-secret-value' ).val( "define( 'FS_LMS_VIDEO_HMAC_SECRET', '" + hex + "' );" );
				$( '#fs-videolib-secret-raw' ).val( hex );
				$( '#fs-videolib-secret-output' ).removeAttr( 'hidden' );
			} );

			$form.on( 'click', '[data-videolib-export-groups]', function () {
				self.exportGroups();
			} );

			$form.on( 'click', '[data-videolib-download-groups]', function () {
				self.downloadGroupsYaml();
			} );
		},

		post: function ( action, data ) {
			return $.post( this.cfg.ajaxurl, $.extend( {
				action:   action,
				security: this.cfg.nonce,
			}, data || {} ) );
		},

		exportGroups: function () {
			this.post( this.cfg.actions.exportGroups ).done( function ( res ) {
				if ( ! res || ! res.success ) {
					window.alert( ( res && res.data && res.data.message ) || 'Не удалось сформировать экспорт.' );
					return;
				}

				var data    = res.data;
				var summary = data.count + ' ' + 'групп(ы) с курсом и преподавателем';
				if ( data.skipped ) {
					summary += ', пропущено без курса/преподавателя: ' + data.skipped;
				}

				$( '#fs-videolib-groups-summary' ).text( summary );
				$( '#fs-videolib-groups-value' ).val( data.yaml );
				$( '#fs-videolib-groups-output' ).removeAttr( 'hidden' );
			} );
		},

		downloadGroupsYaml: function () {
			var yaml = $( '#fs-videolib-groups-value' ).val() || '';
			var blob = new Blob( [ yaml ], { type: 'text/yaml' } );
			var url  = URL.createObjectURL( blob );
			var $a   = $( '<a>', { href: url, download: 'groups.yaml' } ).appendTo( 'body' );
			$a[ 0 ].click();
			$a.remove();
			URL.revokeObjectURL( url );
		},
	};

	$( function () {
		if ( $( '#fs-videolib-form' ).length && typeof window.fsLmsVideoLibrary !== 'undefined' ) {
			VideoLibrary.init();
		}
	} );
}( jQuery ) );
