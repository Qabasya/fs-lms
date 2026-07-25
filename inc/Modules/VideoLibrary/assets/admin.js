/**
 * Admin-JS модуля VideoLibrary (self-contained, вне core-бандла и вне ESLint src/js).
 * Секция «Видеозаписи занятий (S3)» в табе «Конфигурация»: генератор HMAC-секрета
 * и экспорт groups.yaml для сервиса fs-video-uploader.
 *
 * Привязка записей к занятиям — автоматическая (по дате/времени, см. VideoLessonResolver).
 * Полный список записей на странице конфигурации намеренно не показывается (при росте
 * числа записей это ломало страницу) — вместо него блок «Занятия без записи» (З3):
 * только те занятия, где авто-матч не сработал, с привязкой в один клик или ручной ссылкой.
 *
 * Глобал fsLmsVideoLibrary = { ajaxurl, nonce, actions: { save, exportGroups, lessons, attach, detach, pending, setUrl } } —
 * локализуется в VideoLibrarySettingsController.
 */
( function ( $ ) {
	'use strict';

	var VideoLibrary = {
		cfg: null,

		init: function () {
			this.cfg = window.fsLmsVideoLibrary;
			this.bindEvents();
			this.loadPending();
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
				self.exportGroups( $( this ) );
			} );

			// З3: привязка выбранной записи / ручная ссылка в блоке «Занятия без записи».
			$form.on( 'click', '[data-pending-attach]', function () {
				var $row = $( this ).closest( '[data-pending-row]' );
				var recId = $row.find( '[data-pending-select]' ).val();
				if ( ! recId ) { return; }
				self.pendingAction( $( this ), self.cfg.actions.attach, {
					recording_id:    recId,
					group_lesson_id: $row.data( 'glid' ),
				} );
			} );

			$form.on( 'click', '[data-pending-save-url]', function () {
				var $row = $( this ).closest( '[data-pending-row]' );
				var url  = $.trim( $row.find( '[data-pending-url]' ).val() );
				if ( ! url ) { return; }
				self.pendingAction( $( this ), self.cfg.actions.setUrl, {
					group_lesson_id: $row.data( 'glid' ),
					recording_url:   url,
				} );
			} );
		},

		post: function ( action, data ) {
			return $.post( this.cfg.ajaxurl, $.extend( {
				action:   action,
				security: this.cfg.nonce,
			}, data || {} ) );
		},

		esc: function ( value ) {
			return $( '<i>' ).text( value == null ? '' : String( value ) ).html();
		},

		// ── З3: занятия без записи ─────────────────────────────────────────
		loadPending: function () {
			var self  = this;
			var $list = $( '[data-videolib-pending-list]' );
			if ( ! $list.length ) { return; }

			this.post( this.cfg.actions.pending ).done( function ( res ) {
				if ( ! res || ! res.success ) {
					$list.html( '<p class="description">Не удалось загрузить список.</p>' );
					return;
				}
				self.renderPending( $list, res.data );
			} ).fail( function () {
				$list.html( '<p class="description">Не удалось загрузить список.</p>' );
			} );
		},

		renderPending: function ( $list, data ) {
			var self    = this;
			var lessons = data.lessons || [];
			var $badge  = $( '[data-videolib-pending-badge]' );

			$badge.html( data.count
				? '<span class="fs-badge is-red">Без записи: ' + self.esc( data.count ) + '</span>'
				: '<span class="fs-badge is-green">Все записи на месте</span>' );

			if ( ! lessons.length ) {
				$list.html( '<p class="description">Занятий без записи нет.</p>' );
				return;
			}

			var html = lessons.map( function ( l ) {
				var options = ( l.candidates || [] ).map( function ( c ) {
					return '<option value="' + self.esc( c.id ) + '">'
						+ self.esc( c.s3_key ) + ' · ' + self.esc( c.recorded_at ) + '</option>';
				} ).join( '' );

				return '<div class="fs-videolib-pending-row" data-pending-row data-glid="' + self.esc( l.id ) + '">'
					+ '<div class="fs-videolib-pending-row__meta">'
					+ '<strong>' + self.esc( l.group_name ) + '</strong>'
					+ '<span>' + self.esc( l.scheduled_at ) + '</span>'
					+ '<span>' + self.esc( l.topic ) + '</span>'
					+ '</div>'
					+ '<div class="fs-videolib-pending-row__actions">'
					+ ( options
						? '<select data-pending-select><option value="">Выберите запись группы…</option>' + options + '</select>'
							+ '<button type="button" class="button" data-pending-attach>Привязать</button>'
						: '<span class="description">Непривязанных записей этой группы нет.</span>' )
					+ '</div>'
					+ '<div class="fs-videolib-pending-row__actions">'
					+ '<input type="text" data-pending-url placeholder="https://… или s3://bucket/key">'
					+ '<button type="button" class="button" data-pending-save-url>Сохранить ссылку</button>'
					+ '</div>'
					+ '</div>';
			} ).join( '' );

			$list.html( html );
		},

		// Общий пост-обработчик действий строки: блокирует кнопку и перечитывает список.
		pendingAction: function ( $btn, action, data ) {
			var self = this;
			$btn.prop( 'disabled', true );
			this.post( action, data ).done( function ( res ) {
				if ( ! res || ! res.success ) {
					window.alert( ( res && res.data && res.data.message ) || res && res.data || 'Не удалось выполнить действие.' );
					$btn.prop( 'disabled', false );
					return;
				}
				self.loadPending();
			} ).fail( function () {
				window.alert( 'Ошибка сети.' );
				$btn.prop( 'disabled', false );
			} );
		},

		// Клик сразу качает файл — без промежуточного показа/копирования.
		exportGroups: function ( $btn ) {
			$btn.prop( 'disabled', true );
			this.post( this.cfg.actions.exportGroups ).done( function ( res ) {
				if ( ! res || ! res.success ) {
					window.alert( ( res && res.data && res.data.message ) || 'Не удалось сформировать экспорт.' );
					return;
				}

				var blob = new Blob( [ res.data.yaml ], { type: 'text/yaml' } );
				var url  = URL.createObjectURL( blob );
				var $a   = $( '<a>', { href: url, download: 'groups.yaml' } ).appendTo( 'body' );
				$a[ 0 ].click();
				$a.remove();
				URL.revokeObjectURL( url );
			} ).always( function () {
				$btn.prop( 'disabled', false );
			} );
		},
	};

	$( function () {
		if ( $( '#fs-videolib-form' ).length && typeof window.fsLmsVideoLibrary !== 'undefined' ) {
			VideoLibrary.init();
		}
	} );
}( jQuery ) );
