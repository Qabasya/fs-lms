/**
 * Рейка-дерево (T14.4): пин разворота (localStorage), дорисовка шагов
 * текущего урока в дерево из панелей плеера, переходы по шагам.
 * Slim/hover-механика — на CSS (.rail:hover / .rail.pin), см. _rail.scss.
 *
 * Мобильный (Tasks.md, п. 1): дерево открывается тапом по rs-x и работает
 * оверлеем, поэтому закрывать его должно всё привычное — тап по затемнению
 * (свободному месту), свайп влево по самой рейке и Escape. Попадать пальцем в
 * маленькую булавку в шапке дерева для этого не нужно.
 */
import { getCore, onRefresh } from './core.js';
import { esc, ICO, typeIco, typeMeta } from './icons.js';
import { toast } from './shell.js';

const PIN_KEY = 'fsPlayerRailPin';

/** Тот же порог, что $bp-mobile в SCSS: ниже него рейка — оверлей. */
const MOBILE_QUERY = '(max-width: 640px)';

/** Минимальный горизонтальный сдвиг свайпа, px; ниже — это тап или скролл. */
const SWIPE_MIN = 45;

export function initRail() {
	const rail = document.getElementById( 'fsRail' );
	if ( ! rail ) { return; }

	let pinned = false;
	try { pinned = '1' === localStorage.getItem( PIN_KEY ); } catch {}

	const pinBtn = document.getElementById( 'fsRailPin' );
	const scrim  = document.getElementById( 'fsRailScrim' );

	const isMobile = () => window.matchMedia( MOBILE_QUERY ).matches;

	const applyPin = () => {
		rail.classList.toggle( 'pin', pinned );
		if ( pinBtn ) { pinBtn.classList.toggle( 'on', pinned ); }
		// Затемнение существует всегда, показывает его CSS только на мобильном.
		if ( scrim ) { scrim.hidden = ! pinned; }
	};

	const setPin = ( value ) => {
		pinned = value;
		try { localStorage.setItem( PIN_KEY, pinned ? '1' : '0' ); } catch {}
		applyPin();
	};

	/** Закрыть дерево — только там, где оно перекрывает контент. */
	const closeOnMobile = () => {
		if ( pinned && isMobile() ) { setPin( false ); }
	};

	applyPin();

	if ( pinBtn ) { pinBtn.addEventListener( 'click', () => setPin( ! pinned ) ); }

	const expand = rail.querySelector( '.rs-x' );
	if ( expand ) { expand.addEventListener( 'click', () => setPin( true ) ); }

	if ( scrim ) { scrim.addEventListener( 'click', closeOnMobile ); }

	document.addEventListener( 'keydown', ( e ) => {
		if ( 'Escape' === e.key ) { closeOnMobile(); }
	} );

	attachSwipe( rail, closeOnMobile );

	renderRailSteps( closeOnMobile );
	onRefresh( () => renderRailSteps( closeOnMobile ) );
}

/**
 * Свайп влево по развёрнутой рейке закрывает дерево. Вертикальное движение
 * игнорируем — иначе жест конфликтовал бы со скроллом длинного дерева.
 */
function attachSwipe( rail, close ) {
	let startX = 0;
	let startY = 0;
	let tracking = false;

	rail.addEventListener( 'touchstart', ( e ) => {
		const touch = e.touches[ 0 ];
		if ( ! touch ) { return; }
		startX   = touch.clientX;
		startY   = touch.clientY;
		tracking = true;
	}, { passive: true } );

	rail.addEventListener( 'touchend', ( e ) => {
		if ( ! tracking ) { return; }
		tracking = false;
		const touch = e.changedTouches[ 0 ];
		if ( ! touch ) { return; }
		const dx = touch.clientX - startX;
		const dy = touch.clientY - startY;
		if ( dx < -SWIPE_MIN && Math.abs( dx ) > Math.abs( dy ) ) { close(); }
	}, { passive: true } );
}

/** Шаги текущего урока в дереве: иконка типа, «N. Название», галка пройденного. */
function renderRailSteps( closeOnMobile ) {
	const host = document.getElementById( 'fsRailSteps' );
	const core = getCore();
	if ( ! host || ! core ) { return; }

	host.innerHTML = core.panels.map( ( p, i ) => {
		const type = p.dataset.stepType;
		const on   = i === core.activeIndex();
		const done = 'completed' === p.dataset.status;
		return `<div class="t-step${ on ? ' on' : '' }" data-rail-step="${ i }">` +
			`<span class="tsi">${ typeIco( type, typeMeta( type ).c, 15 ) }</span>` +
			`<span class="txt">${ i + 1 }. ${ esc( p.dataset.title ) }</span>` +
			( done ? `<span class="tick">${ ICO.check( 13 ) }</span>` : '' ) +
			'</div>';
	} ).join( '' );

	host.querySelectorAll( '[data-rail-step]' ).forEach( ( el ) => {
		el.addEventListener( 'click', () => {
			const i = parseInt( el.dataset.railStep, 10 );
			if ( 'locked' === core.panels[ i ].dataset.gate ) {
				toast( 'Шаг откроется после предыдущего' );
				return;
			}
			core.show( i );
			// Шаг выбран — дерево-оверлей своё дело сделало и уступает контенту.
			closeOnMobile();
		} );
	} );
}
