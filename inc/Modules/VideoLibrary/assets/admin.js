/**
 * Admin-JS модуля VideoLibrary (self-contained, вне core-бандла и вне ESLint src/js).
 * Секция «Видеозаписи занятий (S3)» в табе «Конфигурация»: генератор HMAC-секрета
 * и ручная привязка unmatched-записей к занятиям (V9).
 *
 * Глобал fsLmsVideoLibrary = { ajaxurl, nonce, actions: { list, lessons, attach, detach } } —
 * локализуется в VideoLibrarySettingsController.
 */
( function ( $ ) {
	'use strict';

	var VideoLibrary = {
		cfg: null,
		groups: [],

		init: function () {
			this.cfg = window.fsLmsVideoLibrary;
			this.bindEvents();
			this.loadList();
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

			$form.on( 'click', '[data-videolib-refresh]', function () {
				self.loadList();
			} );

			// Делегированные обработчики строк реестра.
			$form.on( 'change', '[data-videolib-group], [data-videolib-day]', function () {
				self.loadLessons( $( this ).closest( '[data-videolib-row]' ) );
			} );
			$form.on( 'click', '[data-videolib-attach]', function () {
				self.attach( $( this ).closest( '[data-videolib-row]' ) );
			} );
			$form.on( 'click', '[data-videolib-detach]', function () {
				self.detach( $( this ).data( 'videolib-detach' ) );
			} );
		},

		post: function ( action, data ) {
			return $.post( this.cfg.ajaxurl, $.extend( {
				action:   action,
				security: this.cfg.nonce,
			}, data || {} ) );
		},

		loadList: function () {
			var self = this;
			this.post( this.cfg.actions.list ).done( function ( res ) {
				if ( ! res || ! res.success ) {
					return;
				}
				self.groups = res.data.groups || [];
				self.renderUnmatched( res.data.unmatched || [] );
				self.renderMatched( res.data.matched || [] );
			} );
		},

		renderUnmatched: function ( rows ) {
			var $box = $( '#fs-videolib-unmatched' ).empty();
			$( '#fs-videolib-unmatched-count' ).text( rows.length ? '(' + rows.length + ')' : '' );

			if ( ! rows.length ) {
				$box.append( $( '<p>', { 'class': 'description', text: 'Непривязанных записей нет.' } ) );
				return;
			}

			var $table = this.table( [ 'Запись', 'Источник', 'Записано', 'Размер', 'Привязка' ] );
			var self   = this;

			$.each( rows, function ( _, row ) {
				var $tr = $( '<tr>', { 'data-videolib-row': row.id } );
				$tr.append( $( '<td>' ).append( $( '<code>', { text: row.s3_key } ) ) );
				$tr.append( $( '<td>', { text: row.teacher ? 'препод: ' + row.teacher : row.group_slug } ) );
				$tr.append( $( '<td>', { text: row.recorded_at } ) );
				$tr.append( $( '<td>', { text: row.size } ) );
				$tr.append( self.bindCell( row ) );
				$table.find( 'tbody' ).append( $tr );
			} );

			$box.append( $table );
		},

		bindCell: function ( row ) {
			var $group = $( '<select>', { 'data-videolib-group': '' } )
				.append( $( '<option>', { value: '', text: '— группа —' } ) );
			$.each( this.groups, function ( _, g ) {
				$group.append( $( '<option>', {
					value:    g.id,
					text:     g.name,
					selected: row.group_id === g.id,
				} ) );
			} );

			var day = ( row.recorded_at || '' ).slice( 0, 10 );

			return $( '<td>' )
				.append( $group )
				.append( ' ' )
				.append( $( '<input>', { type: 'date', value: day, 'data-videolib-day': '' } ) )
				.append( ' ' )
				.append( $( '<select>', { 'data-videolib-lesson': '' } )
					.append( $( '<option>', { value: '', text: '— занятие —' } ) ) )
				.append( ' ' )
				.append( $( '<button>', { type: 'button', 'class': 'button button-primary', text: 'Привязать', 'data-videolib-attach': '' } ) );
		},

		renderMatched: function ( rows ) {
			var $box = $( '#fs-videolib-matched' ).empty();

			if ( ! rows.length ) {
				$box.append( $( '<p>', { 'class': 'description', text: 'Привязанных записей нет.' } ) );
				return;
			}

			var $table = this.table( [ 'Запись', 'Записано', 'Занятие', '' ] );

			$.each( rows, function ( _, row ) {
				var lesson = '#' + row.group_lesson_id + ( row.lesson_scheduled_at ? ' (' + row.lesson_scheduled_at + ')' : '' );
				var $tr    = $( '<tr>' );
				$tr.append( $( '<td>' ).append( $( '<code>', { text: row.s3_key } ) ) );
				$tr.append( $( '<td>', { text: row.recorded_at } ) );
				$tr.append( $( '<td>', { text: lesson } ) );
				$tr.append( $( '<td>' ).append( $( '<button>', {
					type: 'button',
					'class': 'button',
					text: 'Отвязать',
					'data-videolib-detach': row.id,
				} ) ) );
				$table.find( 'tbody' ).append( $tr );
			} );

			$box.append( $table );
		},

		table: function ( headers ) {
			var $head = $( '<tr>' );
			$.each( headers, function ( _, h ) {
				$head.append( $( '<th>', { text: h } ) );
			} );
			return $( '<table>', { 'class': 'widefat striped' } )
				.append( $( '<thead>' ).append( $head ) )
				.append( $( '<tbody>' ) );
		},

		loadLessons: function ( $row ) {
			var groupId = $row.find( '[data-videolib-group]' ).val();
			var day     = $row.find( '[data-videolib-day]' ).val();
			var $lesson = $row.find( '[data-videolib-lesson]' )
				.empty()
				.append( $( '<option>', { value: '', text: '— занятие —' } ) );

			if ( ! groupId || ! day ) {
				return;
			}

			this.post( this.cfg.actions.lessons, { group_id: groupId, day: day } ).done( function ( res ) {
				if ( ! res || ! res.success ) {
					return;
				}
				$.each( res.data.lessons || [], function ( _, lesson ) {
					var label = ( lesson.scheduled_at || '' ) + ' — ' + lesson.title +
						( 'individual' === lesson.kind ? ' (индив.)' : '' ) +
						( 'cancelled' === lesson.status ? ' [отменено]' : '' );
					$lesson.append( $( '<option>', { value: lesson.id, text: label } ) );
				} );
			} );
		},

		attach: function ( $row ) {
			var self     = this;
			var lessonId = $row.find( '[data-videolib-lesson]' ).val();
			if ( ! lessonId ) {
				window.alert( 'Выберите занятие.' );
				return;
			}

			this.post( this.cfg.actions.attach, {
				recording_id:    $row.data( 'videolib-row' ),
				group_lesson_id: lessonId,
			} ).done( function ( res ) {
				if ( res && res.success ) {
					self.loadList();
				} else {
					window.alert( ( res && res.data && res.data.message ) || 'Не удалось привязать запись.' );
				}
			} );
		},

		detach: function ( recordingId ) {
			var self = this;
			if ( ! window.confirm( 'Отвязать запись от занятия?' ) ) {
				return;
			}

			this.post( this.cfg.actions.detach, { recording_id: recordingId } ).done( function ( res ) {
				if ( res && res.success ) {
					self.loadList();
				} else {
					window.alert( ( res && res.data && res.data.message ) || 'Не удалось отвязать запись.' );
				}
			} );
		},
	};

	$( function () {
		if ( $( '#fs-videolib-form' ).length && typeof window.fsLmsVideoLibrary !== 'undefined' ) {
			VideoLibrary.init();
		}
	} );
}( jQuery ) );
