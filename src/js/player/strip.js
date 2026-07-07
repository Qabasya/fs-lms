/**
 * Лента шагов (T14.5): горизонтальные квадратики с иконками/цветами типов,
 * done-галки, текущий залит цветом типа. Номер/тип/название текущего шага
 * показывает топбар (#fsNavPos), не сама лента.
 * Перерисовывается ядром при каждой смене шага/статуса.
 */
import { esc, typeIco, typeMeta } from './icons.js';
import { toast } from './shell.js';

export function renderStrip( ctx ) {
	const strip = document.getElementById( 'fsStrip' );
	if ( ! strip ) { return; }

	let h = '';
	ctx.panels.forEach( ( p, i ) => {
		const type = p.dataset.stepType;
		const meta = typeMeta( type );
		const cur  = i === ctx.active;
		const done = 'completed' === p.dataset.status;
		h += `<div class="stp${ cur ? ' cur' : '' }${ done ? ' done' : '' }" data-step-type="${ esc( type ) }" data-goto="${ i }" title="${ esc( p.dataset.title ) }">` +
			`<span class="sq"><span class="num">${ i + 1 }</span>${ typeIco( type, cur ? '#fff' : meta.c, 22 ) }</span>` +
			`<span class="lbl">${ esc( meta.label ) }</span></div>`;
	} );

	strip.innerHTML = h;

	strip.querySelectorAll( '[data-goto]' ).forEach( ( el ) => {
		el.addEventListener( 'click', () => {
			const i = parseInt( el.dataset.goto, 10 );
			if ( 'locked' === ctx.panels[ i ].dataset.gate ) {
				toast( 'Откроется после предыдущего шага' );
				return;
			}
			ctx.onGoto( i );
		} );
	} );
}
