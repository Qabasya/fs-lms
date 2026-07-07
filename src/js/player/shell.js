/**
 * Оболочка плеера (D18): тост, data-toast заглушки топбара. UI-слой без AJAX.
 */

export function toast( msg ) {
	const el = document.getElementById( 'fsToast' );
	if ( ! el ) { return; }
	el.querySelector( 'span' ).textContent = msg;
	el.classList.add( 'show' );
	clearTimeout( toast._t );
	toast._t = setTimeout( () => el.classList.remove( 'show' ), 2200 );
}

export function initShell() {
	if ( ! document.getElementById( 'fsPlayerApp' ) ) { return; }

	// Серверные прогресс-бары: ширина приходит числом в data-width (0–100).
	document.querySelectorAll( '[data-width]' ).forEach( ( el ) => {
		el.style.width = `${ parseInt( el.dataset.width, 10 ) || 0 }%`;
	} );

	document.querySelectorAll( '[data-toast]' ).forEach( ( el ) => {
		el.addEventListener( 'click', () => toast( el.dataset.toast ) );
	} );
}
