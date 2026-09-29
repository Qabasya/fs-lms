/**
 * Рейка шагов урока (Tasks.md З4): квадраты-номера вместо ленты шагов и кнопок
 * «Назад/Далее». Клик — переход к шагу (закрытый гейт — тост), наведение или
 * фокус — подсказка с иконкой, типом и названием шага.
 *
 * Подсказка одна на рейку и позиционируется fixed по квадрату: рейка сама
 * прокручивается (много шагов), и подсказка внутри неё обрезалась бы. JS
 * выставляет только CSS-переменные координат — сами стили в _rail.scss.
 *
 * На телефоне рейка — горизонтальная полоса над контентом; наведения там нет,
 * поэтому тип и название текущего шага пишутся строкой под полосой.
 */
import { getCore, onRefresh } from './core.js';
import { esc, ICO, typeIco, typeMeta } from './icons.js';
import { toast } from './shell.js';

export function initRail() {
	const rail = document.getElementById( 'fsRail' );
	const core = getCore();
	if ( ! rail || ! core ) { return; }

	const pop = document.getElementById( 'fsRailPop' );
	const cur = document.getElementById( 'fsRailCur' );

	const render = () => {
		// Перерисовка не должна выбивать фокус клавиатуры с квадрата.
		const focused = rail.contains( document.activeElement ) ? document.activeElement.dataset.goto : null;
		rail.innerHTML = core.panels.map( ( p, i ) => stepHtml( p, i, i === core.activeIndex() ) ).join( '' );
		const active = core.panels[ core.activeIndex() ];
		if ( cur && active ) {
			cur.textContent = `${ core.activeIndex() + 1 }. ${ typeMeta( active.dataset.stepType ).label } · ${ active.dataset.title || '' }`;
		}
		keepVisible( rail, rail.querySelector( '.rs-step.cur' ) );
		if ( null !== focused ) { rail.querySelector( `[data-goto="${ focused }"]` )?.focus(); }
	};

	rail.addEventListener( 'click', ( e ) => {
		const btn = e.target.closest( '[data-goto]' );
		if ( ! btn ) { return; }
		const i = parseInt( btn.dataset.goto, 10 );
		if ( 'locked' === core.panels[ i ].dataset.gate ) {
			toast( 'Шаг откроется, когда будет решено предыдущее задание' );
			return;
		}
		core.show( i );
	} );

	if ( pop ) { attachPopout( rail, pop, core ); }

	render();
	onRefresh( render );
}

function stepHtml( panel, i, isCurrent ) {
	const type   = panel.dataset.stepType;
	const done   = [ 'completed', 'failed' ].includes( panel.dataset.status );
	const locked = 'locked' === panel.dataset.gate;
	const cls    = [ 'rs-step', isCurrent ? 'cur' : '', done ? 'done' : '', locked ? 'lk' : '' ].filter( Boolean ).join( ' ' );
	const label  = `Шаг ${ i + 1 }: ${ typeMeta( type ).label }${ panel.dataset.title ? ` — ${ panel.dataset.title }` : '' }`;

	return `<button type="button" class="${ cls }" data-step-type="${ esc( type ) }" data-goto="${ i }"` +
		` aria-label="${ esc( label ) }"${ isCurrent ? ' aria-current="step"' : '' }>` +
		`<span class="rs-n">${ i + 1 }</span>` +
		( done ? `<span class="rs-tick">${ ICO.check( 10 ) }</span>` : '' ) +
		( locked && ! isCurrent ? `<span class="rs-lock">${ ICO.lock( 10 ) }</span>` : '' ) +
		'</button>';
}

/** Подсказка «иконка · тип · название» у квадрата — по наведению и по фокусу с клавиатуры. */
function attachPopout( rail, pop, core ) {
	const show = ( btn ) => {
		const panel = core.panels[ parseInt( btn.dataset.goto, 10 ) ];
		if ( ! panel ) { return; }
		const type = panel.dataset.stepType;
		const meta = typeMeta( type );

		pop.dataset.stepType = type;
		pop.innerHTML = `<span class="rp-type">${ typeIco( type, meta.c, 16 ) }${ esc( meta.label ) }</span>` +
			( panel.dataset.title ? `<span class="rp-title">${ esc( panel.dataset.title ) }</span>` : '' ) +
			( 'locked' === panel.dataset.gate ? '<span class="rp-note">Откроется после решения предыдущего задания</span>' : '' );

		const r = btn.getBoundingClientRect();
		pop.style.setProperty( '--pop-x', `${ r.right }px` );
		pop.style.setProperty( '--pop-y', `${ r.top + ( r.height / 2 ) }px` );
		pop.hidden = false;
	};
	const hide = () => { pop.hidden = true; };

	// Только там, где наведение есть: на тач-экране подсказку заменяет строка #fsRailCur.
	if ( window.matchMedia( '(hover: hover)' ).matches ) {
		rail.addEventListener( 'pointerover', ( e ) => {
			const btn = e.target.closest( '[data-goto]' );
			if ( btn ) { show( btn ); }
		} );
		rail.addEventListener( 'pointerleave', hide );
	}
	rail.addEventListener( 'focusin', ( e ) => {
		const btn = e.target.closest( '[data-goto]' );
		if ( btn && btn.matches( ':focus-visible' ) ) { show( btn ); }
	} );
	rail.addEventListener( 'focusout', hide );
	rail.addEventListener( 'scroll', hide, { passive: true } );
	window.addEventListener( 'scroll', hide, { passive: true } );
}

/**
 * Текущий квадрат — в видимой части рейки (прокрутка самой рейки, не страницы:
 * scrollIntoView дёрнул бы и окно). Рейка — position: relative, поэтому
 * offsetTop/offsetLeft квадрата считаются от неё.
 */
function keepVisible( rail, el ) {
	if ( ! el ) { return; }
	if ( rail.scrollHeight > rail.clientHeight ) {
		const top = el.offsetTop;
		if ( top < rail.scrollTop || top + el.offsetHeight > rail.scrollTop + rail.clientHeight ) {
			rail.scrollTop = top - ( ( rail.clientHeight - el.offsetHeight ) / 2 );
		}
	}
	if ( rail.scrollWidth > rail.clientWidth ) {
		const left = el.offsetLeft;
		if ( left < rail.scrollLeft || left + el.offsetWidth > rail.scrollLeft + rail.clientWidth ) {
			rail.scrollLeft = left - ( ( rail.clientWidth - el.offsetWidth ) / 2 );
		}
	}
}
