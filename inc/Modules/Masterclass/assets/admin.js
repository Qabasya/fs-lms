/**
 * Admin-JS страницы «FS LMS → Мастер-классы» (self-contained, вне core-бандла и вне ESLint src/js).
 *
 * Форма создания и правки, список уроков по предмету, добор аккаунтов порциями с прогрессом «создано X из Y»,
 * продление, снятие блокировок класса, удаление. Показ и скрытие — атрибутом `hidden`; стили из JS не задаются.
 *
 * Глобал fsLmsMasterclass = { ajaxurl, nonce, actions, chunkMax, defaults } — локализуется в MasterclassAdminController.
 * Ответ об ошибке — строка (`wp_send_json_error( $message )`) либо `{message, code}`; устаревший nonce чинит не бандл
 * ядра (у этого скрипта перехватчика нет), поэтому на 403 страница сама просит её обновить.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.fsLmsMasterclass;
	if ( typeof cfg === 'undefined' ) {
		return;
	}

	/** Предел «сколько аккаунтов» в форме (как `max` у поля и `batch_size` в настройках). */
	var COUNT_MAX = 500;

	/** Подряд пустых порций, после которых добор прекращается (иначе цикл крутился бы вечно). */
	var IDLE_LIMIT = 3;

	/** Через сколько показанное сообщение сменяется обновлением таблицы. */
	var RELOAD_DELAY_MS = 1200;

	var busy = false;

	var $notice     = $( '#fs-masterclass-notice' );
	var $noticeText = $( '#fs-masterclass-notice-text' );
	var $formCard   = $( '#fs-masterclass-form-card' );
	var $form       = $( '#fs-masterclass-form' );
	var $panel      = $( '#fs-masterclass-panel' );
	var $panelOk    = $( '#fs-masterclass-panel-ok' );

	var $id      = $form.find( '[name="id"]' );
	var $school  = $( '#fs-masterclass-school' );
	var $subject = $( '#fs-masterclass-subject' );
	var $lesson  = $( '#fs-masterclass-lesson' );
	var $teacher = $( '#fs-masterclass-teacher' );
	var $date    = $( '#fs-masterclass-date' );
	var $start   = $( '#fs-masterclass-start' );
	var $end     = $( '#fs-masterclass-end' );
	var $count   = $( '#fs-masterclass-count' );

	/** Что сделает кнопка «Выполнить» панели; задаётся при открытии панели. */
	var panelHandler = null;

	function post( action, data ) {
		return $.post( cfg.ajaxurl, $.extend( { action: action, security: cfg.nonce }, data ) );
	}

	/** Текст ошибки из ответа с `success: false`. */
	function responseMessage( res ) {
		var data = res && res.data;
		if ( data && data.message ) {
			return data.message;
		}

		return typeof data === 'string' && data ? data : 'Ошибка.';
	}

	/** Текст ошибки из упавшего запроса: 403 с кодом E-SESSION — устаревший nonce. */
	function failMessage( xhr ) {
		var data = xhr && xhr.responseJSON && xhr.responseJSON.data;
		if ( xhr && xhr.status === 403 ) {
			return data && data.code === 'E-SESSION' ? 'Сессия устарела — обновите страницу.' : ( ( data && data.message ) || 'Недостаточно прав.' );
		}

		return ( data && data.message ) || 'Ошибка сети.';
	}

	function notice( text, kind ) {
		$notice.removeClass( 'notice-info notice-success notice-error' ).addClass( 'notice-' + kind );
		$noticeText.text( text );
		$notice.prop( 'hidden', false );
	}

	function setBusy( state ) {
		busy = state;
		$( '#fs-masterclass-save, #fs-masterclass-new, #fs-masterclass-panel-ok' ).prop( 'disabled', state );
		$( '#fs-masterclass-table [data-mk-action]' ).prop( 'disabled', state );
	}

	function fail( text ) {
		setBusy( false );
		notice( text, 'error' );
	}

	/** Успех: показать сообщение и обновить страницу, чтобы таблица показала новое состояние. */
	function finish( text ) {
		notice( text, 'success' );
		window.setTimeout( function () {
			window.location.reload();
		}, RELOAD_DELAY_MS );
	}

	function stamp( date, time ) {
		return date + ' ' + ( time.length === 5 ? time + ':00' : time );
	}

	function clampCount( raw, min ) {
		var value = parseInt( raw, 10 );
		if ( isNaN( value ) || value < min ) {
			return min;
		}

		return Math.min( value, COUNT_MAX );
	}

	// ─── уроки по предмету ──────────────────────────────────────────────

	function resetLessons( placeholder ) {
		$lesson.empty().append( $( '<option>', { value: '', text: placeholder } ) ).prop( 'disabled', true );
	}

	/** Подгружает уроки предмета; непригодные неактивны и называют причину. */
	function loadLessons( subject, selected ) {
		if ( ! subject ) {
			resetLessons( 'Сначала выберите предмет' );
			return;
		}

		resetLessons( 'Загрузка…' );
		post( cfg.actions.lessons, { subject_key: subject } ).done( function ( res ) {
			if ( ! res.success ) {
				resetLessons( responseMessage( res ) );
				return;
			}

			resetLessons( 'Выберите урок' );
			$.each( res.data, function ( _, lesson ) {
				var usable = ! lesson.problems.length;
				$lesson.append( $( '<option>', {
					value: lesson.id,
					text: usable ? lesson.title : lesson.title + ' — нельзя: ' + lesson.problems.join( '; ' ),
					disabled: ! usable
				} ) );
			} );
			$lesson.prop( 'disabled', false );

			if ( selected ) {
				$lesson.val( String( selected ) );
			}
		} ).fail( function ( xhr ) {
			resetLessons( failMessage( xhr ) );
		} );
	}

	// ─── форма ──────────────────────────────────────────────────────────

	function closeForm() {
		$formCard.prop( 'hidden', true );
	}

	function closePanel() {
		$panel.prop( 'hidden', true );
		panelHandler = null;
	}

	/** Открывает форму: без `$row` — создание, с ним — правка. */
	function openForm( $row ) {
		closePanel();
		$form[ 0 ].reset();
		$start.val( cfg.defaults.startTime );
		$end.val( cfg.defaults.endTime );
		$count.val( cfg.defaults.batchSize );

		var editing = $row && $row.length;
		$id.val( editing ? $row.attr( 'data-id' ) : '' );
		$( '#fs-masterclass-form-title' ).text( editing ? 'Изменить мастер-класс' : 'Новый мастер-класс' );
		$( '#fs-masterclass-count-field' ).prop( 'hidden', !! editing );
		$( '#fs-masterclass-subject-note' ).prop( 'hidden', ! editing );
		$subject.prop( 'disabled', !! editing );

		if ( editing ) {
			$school.val( $row.attr( 'data-school' ) );
			$subject.val( $row.attr( 'data-subject' ) );
			$teacher.val( $row.attr( 'data-teacher' ) );
			$date.val( $row.attr( 'data-date' ) );
			$start.val( $row.attr( 'data-start' ) );
			$end.val( $row.attr( 'data-end' ) );
			loadLessons( $row.attr( 'data-subject' ), $row.attr( 'data-lesson' ) );
		} else {
			resetLessons( 'Сначала выберите предмет' );
		}

		$formCard.prop( 'hidden', false );
		$school.trigger( 'focus' );
	}

	/**
	 * Добирает аккаунты порциями по `chunkMax` и показывает «создано X из Y». Порция может создать меньше, чем просили
	 * (занятый логин, сбой), поэтому цикл идёт по набранному числу, а не по числу порций.
	 */
	function addAccounts( id, wanted, onDone, onFail ) {
		var created = 0;
		var idle    = 0;

		( function next() {
			var left = wanted - created;
			if ( left <= 0 ) {
				onDone( created );
				return;
			}

			post( cfg.actions.addAccounts, { id: id, count: Math.min( cfg.chunkMax, left ) } ).done( function ( res ) {
				if ( ! res.success ) {
					onFail( responseMessage( res ), created );
					return;
				}

				created += res.data.created;
				idle     = res.data.created > 0 ? 0 : idle + 1;
				notice( 'Создано аккаунтов: ' + created + ' из ' + wanted + '…', 'info' );

				if ( idle >= IDLE_LIMIT ) {
					onFail( 'Аккаунты не создаются — подробности в журнале ошибок.', created );
					return;
				}

				next();
			} ).fail( function ( xhr ) {
				onFail( failMessage( xhr ), created );
			} );
		}() );
	}

	function submitForm( e ) {
		e.preventDefault();
		if ( busy ) {
			return;
		}

		var id      = $id.val();
		var payload = {
			school_name: $.trim( $school.val() ),
			subject_key: $subject.val(),
			lesson_id: $lesson.val(),
			teacher_user_id: $teacher.val(),
			starts_at: stamp( $date.val(), $start.val() ),
			ends_at: stamp( $date.val(), $end.val() )
		};
		if ( id ) {
			payload.id = id;
		}

		setBusy( true );
		notice( 'Сохраняем…', 'info' );

		post( id ? cfg.actions.update : cfg.actions.create, payload ).done( function ( res ) {
			if ( ! res.success ) {
				fail( responseMessage( res ) );
				return;
			}

			if ( id ) {
				finish( 'Сохранено.' );
				return;
			}

			var wanted = clampCount( $count.val(), 0 );
			addAccounts(
				res.data.id,
				wanted,
				function ( created ) {
					finish( 'Мастер-класс создан. Аккаунтов: ' + created + '.' );
				},
				function ( text, created ) {
					fail( 'Мастер-класс создан (аккаунтов: ' + created + '), но добор прервался: ' + text + ' Обновите страницу и докиньте остальные кнопкой «Докинуть».' );
				}
			);
		} ).fail( function ( xhr ) {
			fail( failMessage( xhr ) );
		} );
	}

	// ─── действия строки ────────────────────────────────────────────────

	/** Открывает панель с числом (продление, добор): `handler( value )` вызывается по «Выполнить». */
	function openPanel( title, hint, value, min, max, handler ) {
		closeForm();
		$( '#fs-masterclass-panel-title' ).text( title );
		$( '#fs-masterclass-panel-hint' ).text( hint );
		$( '#fs-masterclass-panel-number' ).attr( { min: min, max: max } ).val( value );
		panelHandler = handler;
		$panel.prop( 'hidden', false );
		$( '#fs-masterclass-panel-number' ).trigger( 'focus' );
	}

	function runPanel() {
		if ( busy || ! panelHandler ) {
			return;
		}

		var $number = $( '#fs-masterclass-panel-number' );
		var value   = clampCount( $number.val(), parseInt( $number.attr( 'min' ), 10 ) );
		var handler = panelHandler;
		closePanel();
		handler( value );
	}

	function actionAdd( id, school ) {
		openPanel( 'Сколько аккаунтов докинуть в «' + school + '»?', 'От 1 до ' + COUNT_MAX + '. Создаются порциями.', cfg.defaults.batchSize, 1, COUNT_MAX, function ( wanted ) {
			setBusy( true );
			notice( 'Создаём аккаунты…', 'info' );
			addAccounts(
				id,
				wanted,
				function ( created ) {
					finish( 'Добавлено аккаунтов: ' + created + '. Флаеры можно напечатать заново.' );
				},
				function ( text, created ) {
					fail( 'Добавлено аккаунтов: ' + created + ', затем добор прервался: ' + text );
				}
			);
		} );
	}

	function actionExtend( id, school ) {
		openPanel( 'На сколько дней продлить «' + school + '»?', 'От 1 до 60. Сдвигаются заморозка и сроки удаления.', 7, 1, 60, function ( days ) {
			setBusy( true );
			notice( 'Продлеваем…', 'info' );
			post( cfg.actions.extend, { id: id, days: days } ).done( function ( res ) {
				if ( ! res.success ) {
					fail( responseMessage( res ) );
					return;
				}

				finish( 'Продлено. Доступ закроется ' + res.data.freeze_at + ' (местное время).' );
			} ).fail( function ( xhr ) {
				fail( failMessage( xhr ) );
			} );
		} );
	}

	function actionUnlock( id ) {
		setBusy( true );
		notice( 'Снимаем блокировки…', 'info' );
		post( cfg.actions.unlock, { id: id } ).done( function ( res ) {
			setBusy( false );
			if ( ! res.success ) {
				notice( responseMessage( res ), 'error' );
				return;
			}

			notice( 'Блокировок входа снято: ' + res.data.unlocked + '.', 'success' );
		} ).fail( function ( xhr ) {
			fail( failMessage( xhr ) );
		} );
	}

	function actionDelete( id, school ) {
		if ( ! window.confirm( 'Удалить мастер-класс «' + school + '»? Аккаунты детей и группа будут вычищены, отменить это нельзя.' ) ) {
			return;
		}

		setBusy( true );
		notice( 'Помечаем к удалению…', 'info' );
		post( cfg.actions.delete, { id: id } ).done( function ( res ) {
			if ( ! res.success ) {
				fail( responseMessage( res ) );
				return;
			}

			var detached = res.data.detached;
			finish( 'Мастер-класс удаляется.' + ( detached > 0 ? ' Аккаунтов с поданной заявкой: ' + detached + ' — они будут отвязаны, а не удалены.' : '' ) );
		} ).fail( function ( xhr ) {
			fail( failMessage( xhr ) );
		} );
	}

	$( function () {
		if ( ! $form.length ) {
			return;
		}

		$( '#fs-masterclass-new' ).on( 'click', function () {
			openForm( null );
		} );
		$( '#fs-masterclass-cancel' ).on( 'click', closeForm );
		$( '#fs-masterclass-panel-cancel' ).on( 'click', closePanel );
		$panelOk.on( 'click', runPanel );
		$form.on( 'submit', submitForm );
		$subject.on( 'change', function () {
			loadLessons( $subject.val(), null );
		} );

		$( '#fs-masterclass-table' ).on( 'click', '[data-mk-action]', function () {
			if ( busy ) {
				return;
			}

			var $row   = $( this ).closest( '[data-mk-row]' );
			var id     = $row.attr( 'data-id' );
			var school = $row.attr( 'data-school' );

			switch ( $( this ).attr( 'data-mk-action' ) ) {
				case 'add':
					actionAdd( id, school );
					break;
				case 'extend':
					actionExtend( id, school );
					break;
				case 'unlock':
					actionUnlock( id );
					break;
				case 'edit':
					openForm( $row );
					break;
				case 'delete':
					actionDelete( id, school );
					break;
			}
		} );
	} );
}( jQuery ) );
