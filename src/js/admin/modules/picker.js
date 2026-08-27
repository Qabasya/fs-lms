import { escapeHtml as esc } from '../../common/utils.js';

/**
 * Открывает универсальный попап-пикер (поиск + список элементов).
 *
 * @param {HTMLElement} anchor      Элемент-якорь для позиционирования.
 * @param {Object}      opts
 * @param {string}     [opts.placeholder='Поиск…']
 * @param {string}     [opts.emptyText='Ничего не найдено']
 * @param {Function}    opts.fetchFn   (search: string, scope: string) => Promise<{id, title}[]>
 *                                     `scope` — 'subject' (дефолт, пустой поиск) | 'all' (после клика
 *                                     browseAllLabel — вызывающая сторона решает, что это значит:
 *                                     обычно «показать и банк тоже»); при непустом `search` вызывающая
 *                                     сторона обязана искать без ограничения предметом.
 * @param {Function}    opts.onPick    (id: number, title: string, source: string, item: Object) => void
 *                                     `item` — исходный элемент кандидата целиком (напр.
 *                                     `bundle_children` связки 19-21, см. slot-builder.js).
 * @param {string}     [opts.browseAllLabel] Текст пункта «Все задания» в конце пустого списка —
 *                                     упрощает поиск (по умолчанию список сужен до предмета), не
 *                                     запрещает выбор остального (оно и так находится через поиск).
 *                                     Без опции пункт не показывается (обратная совместимость).
 */
export function openPicker( anchor, { placeholder = 'Поиск…', emptyText = 'Ничего не найдено', fetchFn, onPick, placement = 'below', browseAllLabel = '' } ) {
	const pop = document.createElement( 'div' );
	pop.className = 'fs-cb-popover fs-cb-picker';
	pop.innerHTML = `<input type="text" class="field-input" data-search placeholder="${ esc( placeholder ) }"><div class="fs-cb-pick-results" data-results></div>`;
	document.body.appendChild( pop );
	const r = anchor.getBoundingClientRect();
	if ( 'above' === placement ) {
		pop.style.top = `${ window.scrollY + r.top }px`;
		pop.classList.add( 'is-above' ); // константный флип — в CSS-классе
	} else {
		pop.style.top = `${ window.scrollY + r.bottom + 6 }px`;
	}
	pop.style.left = `${ Math.min( r.left, window.innerWidth - 320 ) }px`;
	const results = pop.querySelector( '[data-results]' );
	const search  = pop.querySelector( '[data-search]' );
	let t = null;
	let scope = 'subject';
	const run = () => {
		const query = search.value.trim();
		return Promise.resolve( fetchFn( query, scope ) )
			.then( ( items ) => {
				results.innerHTML = '';
				if ( ! items.length ) { results.innerHTML = `<div class="fs-cb-pick-empty">${ esc( emptyText ) }</div>`; }
				items.forEach( ( it ) => {
					const opt = document.createElement( 'div' );
					opt.className = 'fs-cb-pick-opt';
					const titleSpan = document.createElement( 'span' );
					titleSpan.className = 'fs-cb-pick-title';
					titleSpan.textContent = it.title;
					opt.appendChild( titleSpan );
					if ( it.source ) {
						const badge = document.createElement( 'span' );
						badge.className = 'fs-cb-pick-origin';
						badge.textContent = 'bank' === it.source ? 'Банк' : 'Предмет';
						opt.appendChild( badge );
					}
					opt.addEventListener( 'click', () => { onPick( parseInt( it.id, 10 ), it.title, it.source || '', it ); pop.remove(); } );
					results.appendChild( opt );
				} );
				if ( browseAllLabel && 'subject' === scope && ! query ) {
					const allOpt = document.createElement( 'div' );
					allOpt.className = 'fs-cb-pick-opt fs-cb-pick-all';
					allOpt.textContent = browseAllLabel;
					allOpt.addEventListener( 'click', () => { scope = 'all'; run(); } );
					results.appendChild( allOpt );
				}
			} )
			.catch( () => { results.innerHTML = '<div class="fs-cb-pick-empty">Ошибка</div>'; } );
	};
	search.addEventListener( 'input', () => { clearTimeout( t ); t = setTimeout( run, 300 ); } );
	run();
	setTimeout( () => document.addEventListener( 'click', function once( ev ) {
		if ( ! pop.contains( ev.target ) ) { pop.remove(); } else { document.addEventListener( 'click', once, { once: true } ); }
	}, { once: true } ), 0 );
}
