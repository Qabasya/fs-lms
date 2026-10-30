import { tileHtml } from './tile-html.js';

/**
 * DOM-привязка к состоянию `createBoard()` (tile-board.js): рисует плитки банка и содержимое слотов,
 * навешивает перетаскивание, тап и клавиатуру. Слоты и банк — готовые элементы разметки виджета.
 * Общая для режима плиток «Пропусков в тексте» и типа «Перетаскивание пропусков».
 *
 * Плитка банка — `button.fs-tile`; плитка в слоте — вложенный `span.fs-tile.is-placed` (без рамки и фона,
 * кегль окружающего текста); слот — `role=button` с `tabindex`, Enter/Space = тап.
 *
 * Текст плитки выводится через `tileHtml()`: индексы и степени (`<sub>`/`<sup>`) рисуются тегами, остальное экранируется.
 *
 * @param {{board: object, bank: HTMLElement, slots: HTMLElement[]}} options
 * @returns {{refresh: function, lock: function}}
 */
export function mountTileBoard( { board, bank, slots } ) {
	const draggable = ( el, id ) => {
		el.draggable = true;
		el.addEventListener( 'dragstart', ( e ) => {
			board.dragStart( id );
			e.dataTransfer?.setData( 'text/plain', String( id ) );
		} );
	};

	function tileEl( tile, placed ) {
		const el       = document.createElement( placed ? 'span' : 'button' );
		el.className   = placed ? 'fs-tile is-placed' : 'fs-tile';
		el.innerHTML   = tileHtml( tile.text );
		el.dataset.tileId = String( tile.id );
		if ( ! placed ) {
			el.type = 'button';
			el.classList.toggle( 'is-selected', board.selected() === tile.id );
			el.addEventListener( 'click', () => {
				board.select( tile.id );
				refresh();
			} );
		}
		if ( ! board.locked() ) {
			draggable( el, tile.id );
		}
		el.disabled = board.locked();

		return el;
	}

	function refresh() {
		bank.replaceChildren( ...board.bank().map( ( tile ) => tileEl( tile, false ) ) );
		slots.forEach( ( slot, index ) => {
			const tile = board.slot( index );
			slot.classList.toggle( 'is-filled', null !== tile );
			slot.setAttribute( 'tabindex', board.locked() ? '-1' : '0' );
			slot.replaceChildren( ...( tile ? [ tileEl( tile, true ) ] : [] ) );
		} );
	}

	const tapSlot = ( index ) => {
		board.tapSlot( index );
		refresh();
	};

	slots.forEach( ( slot, index ) => {
		slot.setAttribute( 'role', 'button' );
		slot.addEventListener( 'click', () => tapSlot( index ) );
		slot.addEventListener( 'keydown', ( e ) => {
			if ( 'Enter' === e.key || ' ' === e.key ) {
				e.preventDefault();
				tapSlot( index );
			}
		} );
		slot.addEventListener( 'dragover', ( e ) => e.preventDefault() );
		slot.addEventListener( 'drop', ( e ) => {
			e.preventDefault();
			board.drop( { slot: index } );
			refresh();
		} );
	} );

	bank.addEventListener( 'dragover', ( e ) => e.preventDefault() );
	bank.addEventListener( 'drop', ( e ) => {
		e.preventDefault();
		board.drop( { bank: true } );
		refresh();
	} );

	refresh();

	return {
		refresh,
		lock: () => {
			board.lock();
			refresh();
		},
	};
}
