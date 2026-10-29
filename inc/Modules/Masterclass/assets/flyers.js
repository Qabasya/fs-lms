/**
 * Печатная форма флаеров мастер-класса (self-contained, вне core-бандла и вне ESLint src/js).
 * Кнопка «Печать» открывает системное окно печати.
 */
( function () {
	'use strict';

	var button = document.getElementById( 'flyers-print' );
	if ( button ) {
		button.addEventListener( 'click', function () {
			window.print();
		} );
	}
}() );
