/**
 * Оболочка плеера (D18): тост, data-toast заглушки топбара. UI-слой без AJAX.
 */
import { ICO } from './icons.js';

/**
 * Всплывающее уведомление плеера.
 *
 * @param {string}           msg  Текст
 * @param {'ok'|'error'}     kind Ошибка — красный крестик вместо галочки и дольше на экране:
 *                                с неё снимают скриншот с кодом и номером инцидента.
 */
export function toast( msg, kind = 'ok' ) {
	const el = document.getElementById( 'fsToast' );
	if ( ! el ) { return; }
	const isError = 'error' === kind;

	el.querySelector( 'svg' )?.replaceWith( iconNode( isError ? ICO.cross( 14 ) : ICO.check( 14 ) ) );
	el.querySelector( 'span' ).textContent = msg;
	el.classList.toggle( 'is-error', isError );
	el.classList.add( 'show' );
	clearTimeout( toast._t );
	toast._t = setTimeout( () => el.classList.remove( 'show' ), isError ? 9000 : 2200 );
}

function iconNode( svgHtml ) {
	const tpl = document.createElement( 'template' );
	tpl.innerHTML = svgHtml.trim();
	return tpl.content.firstChild;
}

export function initShell() {
	if ( ! document.getElementById( 'fsPlayerApp' ) ) { return; }

	// Серверные прогресс-бары: ширина приходит числом в data-width (0–100).
	// Ширину задаёт CSS через var(--progress) — JS только выставляет переменную.
	document.querySelectorAll( '[data-width]' ).forEach( ( el ) => {
		el.style.setProperty( '--progress', `${ parseInt( el.dataset.width, 10 ) || 0 }%` );
	} );

	document.querySelectorAll( '[data-toast]' ).forEach( ( el ) => {
		el.addEventListener( 'click', () => toast( el.dataset.toast ) );
	} );

	initHeadroom();
}

/** Сдвиг прокрутки, после которого шапка реагирует на направление, px. */
const HEADROOM_DELTA = 8;

/**
 * Шапка плеера — как у сайта (Tasks.md З4): липкая, но прячется при прокрутке
 * вниз и возвращается при прокрутке вверх, так что «К курсу» всегда в одном
 * жесте. Класс на #fsPlayerApp двигает и шапку, и липкий воркбар работы
 * (он встаёт на место шапки, пока та спрятана) — стили в _shell.scss/_step-work.scss.
 */
function initHeadroom() {
	const app    = document.getElementById( 'fsPlayerApp' );
	const header = app?.querySelector( '.s-top' );
	if ( ! header ) { return; }

	let last    = window.scrollY;
	let pending = false;

	const update = () => {
		pending = false;
		const y = window.scrollY;
		if ( Math.abs( y - last ) < HEADROOM_DELTA ) { return; }
		// Пока шапка в собственной высоте от верха — не прячем: прятать ещё нечего.
		app.classList.toggle( 'top-hidden', y > last && y > header.offsetHeight );
		last = y;
	};

	window.addEventListener( 'scroll', () => {
		if ( ! pending ) {
			pending = true;
			window.requestAnimationFrame( update );
		}
	}, { passive: true } );
}
