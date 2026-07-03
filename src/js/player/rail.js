/**
 * Рейка-дерево (T14.4): пин разворота (localStorage), дорисовка шагов
 * текущего урока в дерево из панелей плеера, переходы по шагам.
 * Slim/hover-механика — на CSS (.rail:hover / .rail.pin), см. _rail.scss.
 */
import { getCore, onRefresh } from './core.js';
import { esc, ICO, typeIco, typeMeta } from './icons.js';
import { toast } from './shell.js';

const PIN_KEY = 'fsPlayerRailPin';

export function initRail() {
	const rail = document.getElementById( 'fsRail' );
	if ( ! rail ) { return; }

	let pinned = false;
	try { pinned = '1' === localStorage.getItem( PIN_KEY ); } catch {}

	const pinBtn = document.getElementById( 'fsRailPin' );

	const applyPin = () => {
		rail.classList.toggle( 'pin', pinned );
		if ( pinBtn ) { pinBtn.classList.toggle( 'on', pinned ); }
	};

	const setPin = ( value ) => {
		pinned = value;
		try { localStorage.setItem( PIN_KEY, pinned ? '1' : '0' ); } catch {}
		applyPin();
	};

	applyPin();

	if ( pinBtn ) { pinBtn.addEventListener( 'click', () => setPin( ! pinned ) ); }

	const expand = rail.querySelector( '.rs-x' );
	if ( expand ) { expand.addEventListener( 'click', () => setPin( true ) ); }

	renderRailSteps();
	onRefresh( renderRailSteps );
}

/** Шаги текущего урока в дереве: иконка типа, «N. Название», галка пройденного. */
function renderRailSteps() {
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
		} );
	} );
}
