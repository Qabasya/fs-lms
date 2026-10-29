/**
 * Окно «Связаться» в плеере временного ученика мастер-класса.
 *
 * Self-contained, без зависимостей. Открывает и закрывает окно атрибутом `hidden`, а каждый клик отправляет
 * в журнал запросом с `keepalive`: переход по `tel:` или ссылке мессенджера не обрывает отправку.
 * Устаревший nonce подменяет перехватчик `nonce-refresh.js` бандла плеера (он оборачивает `fetch`).
 */
( function () {
	'use strict';

	var dim = document.querySelector( '[data-mk-contact]' );
	if ( ! dim ) {
		return;
	}

	function send( kind ) {
		var body = new URLSearchParams();
		body.set( 'action', dim.dataset.action );
		body.set( 'security', dim.dataset.nonce );
		body.set( 'kind', kind );

		fetch( dim.dataset.ajax, {
			method: 'POST',
			keepalive: true,
			credentials: 'same-origin',
			body: body,
		} ).catch( function () {} );
	}

	function close() {
		dim.hidden = true;
	}

	document.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( '[data-mk-open]' ) ) {
			e.preventDefault();
			dim.hidden = false;
			send( 'open' );
			return;
		}

		var link = e.target.closest( '[data-mk-kind]' );
		if ( link ) {
			send( link.dataset.mkKind );
			return;
		}

		if ( e.target === dim || e.target.closest( '[data-mk-close]' ) ) {
			close();
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && ! dim.hidden ) {
			close();
		}
	} );
}() );
