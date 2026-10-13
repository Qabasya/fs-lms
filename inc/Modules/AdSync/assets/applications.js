/**
 * Admin-JS модуля AdSync для таба «Заявки» (self-contained, вне core-бандла и вне ESLint src/js).
 * Кнопка «Создать учётку» в подвале окна заявки: учётку в домене заявке, поданной не из
 * доверенной сети, создаёт сотрудник.
 *
 * Глобал fsLmsAdAccount = { ajaxurl, actions: {state, provision}, nonce } —
 * локализуется в AdAccountController. Ответ обоих действий — { state, label, hint }.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var cfg = window.fsLmsAdAccount;
		if ( typeof cfg === 'undefined' ) {
			return;
		}

		// Кнопка есть в обоих окнах заявки («Ждёт родителя» и «Готово к проверке»);
		// заявку ей передаёт ссылка, которой окно открыли.
		var BUTTONS       = '.fs-lms-modal .js-ad-account';
		var applicationId = 0;

		function post( action ) {
			return $.post( cfg.ajaxurl, { action: action, security: cfg.nonce, application_id: applicationId } );
		}

		// creatable — можно нажать; pending/done/failed — показываем состоянием; none — кнопки нет.
		function render( data ) {
			var state = ( data && data.state ) || 'none';

			$( BUTTONS )
				.text( ( data && data.label ) || '' )
				.attr( 'title', ( data && data.hint ) || '' )
				.prop( 'disabled', 'creatable' !== state )
				.prop( 'hidden', 'none' === state );
		}

		function fail( message ) {
			$( BUTTONS ).text( 'Создать учётку' ).attr( 'title', message ).prop( 'disabled', false );
			window.alert( message );
		}

		$( document ).on( 'click', '.js-edit-application, .js-review-application', function () {
			var id = parseInt( $( this ).data( 'id' ), 10 ) || 0;

			applicationId = id;
			render( null );
			if ( ! id ) {
				return;
			}

			post( cfg.actions.state ).done( function ( res ) {
				// Пока шёл запрос, могли открыть другую заявку.
				if ( id === applicationId && res && res.success ) {
					render( res.data );
				}
			} );
		} );

		$( document ).on( 'click', '.js-ad-account', function ( e ) {
			e.preventDefault();
			if ( ! applicationId ) {
				return;
			}

			var id = applicationId;
			$( BUTTONS ).prop( 'disabled', true ).text( 'Создаём…' );

			post( cfg.actions.provision )
				.done( function ( res ) {
					if ( id !== applicationId ) {
						return;
					}
					if ( res && res.success ) {
						render( res.data );
						return;
					}
					var data = res && res.data;
					fail( ( data && data.message ) || ( 'string' === typeof data && data ) || 'Не удалось создать учётку.' );
				} )
				.fail( function () {
					if ( id === applicationId ) {
						fail( 'Нет связи с сервером — проверьте соединение и повторите.' );
					}
				} );
		} );
	} );
} )( jQuery );
