/**
 * Оболочка плеера (D18): сворачивание сайдбара в кнопку (localStorage),
 * тост, data-toast заглушки топбара. UI-слой без AJAX.
 */

const MENU_KEY = 'fsPlayerMenuOff';

export function toast( msg ) {
	const el = document.getElementById( 'fsToast' );
	if ( ! el ) { return; }
	el.querySelector( 'span' ).textContent = msg;
	el.classList.add( 'show' );
	clearTimeout( toast._t );
	toast._t = setTimeout( () => el.classList.remove( 'show' ), 2200 );
}

function applyMenu( off ) {
	document.body.classList.toggle( 'menu-off', off );
}

export function initShell() {
	if ( ! document.getElementById( 'fsPlayerApp' ) ) { return; }

	// Серверные прогресс-бары: ширина приходит числом в data-width (0–100).
	document.querySelectorAll( '[data-width]' ).forEach( ( el ) => {
		el.style.width = `${ parseInt( el.dataset.width, 10 ) || 0 }%`;
	} );

	let off = false;
	try { off = '1' === localStorage.getItem( MENU_KEY ); } catch {}
	applyMenu( off );

	// Анимацию сворачивания включаем после первичной отрисовки,
	// чтобы восстановленное состояние меню не «проигрывалось» на загрузке.
	requestAnimationFrame( () => {
		requestAnimationFrame( () => document.body.classList.add( 'player-anim' ) );
	} );

	const toggle = () => {
		off = ! document.body.classList.contains( 'menu-off' );
		applyMenu( off );
		try { localStorage.setItem( MENU_KEY, off ? '1' : '0' ); } catch {}
	};

	document.getElementById( 'sCollapse' )?.addEventListener( 'click', toggle );
	document.getElementById( 'mtoggle' )?.addEventListener( 'click', toggle );

	document.querySelectorAll( '[data-toast]' ).forEach( ( el ) => {
		el.addEventListener( 'click', () => toast( el.dataset.toast ) );
	} );
}
