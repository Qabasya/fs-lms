import assert from 'node:assert/strict';
import { test } from 'node:test';

import { FakeElement, installFakeDocument } from './helpers/fake-dom.mjs';

installFakeDocument();
const { createBoard } = await import( '../../src/js/common/tile-board.js' );
const { mountTileBoard } = await import( '../../src/js/common/tile-board-view.js' );

function setup( tiles = [ 'h', 'w', 'i' ], slotCount = 2 ) {
	const board = createBoard( { tiles, slots: slotCount } );
	const bank  = new FakeElement();
	const slots = Array.from( { length: slotCount }, () => new FakeElement( 'span' ) );
	const view  = mountTileBoard( { board, bank, slots } );
	return { board, bank, slots, view };
}

const bankTexts = ( bank ) => bank.byClass( 'fs-tile' ).map( ( t ) => t.textContent );

test( 'банк рисует плитку на каждый элемент: кнопка, перетаскиваемая', () => {
	const { bank } = setup();
	const tiles = bank.byClass( 'fs-tile' );
	assert.deepEqual( bankTexts( bank ), [ 'h', 'w', 'i' ] );
	assert.equal( tiles[ 0 ].tagName, 'BUTTON' );
	assert.equal( tiles[ 0 ].draggable, true );
} );

test( 'слот доступен с клавиатуры: role=button и tabindex', () => {
	const { slots } = setup();
	assert.equal( slots[ 0 ].attributes.role, 'button' );
	assert.equal( slots[ 0 ].attributes.tabindex, '0' );
} );

test( 'тап по плитке и по слоту кладёт плитку: слот заполнен, плитка ушла из банка', () => {
	const { bank, slots } = setup();
	bank.byClass( 'fs-tile' )[ 1 ].emit( 'click' );
	assert.equal( bank.byClass( 'fs-tile' )[ 1 ].classList.contains( 'is-selected' ), true );
	slots[ 0 ].emit( 'click' );
	assert.equal( slots[ 0 ].classList.contains( 'is-filled' ), true );
	assert.equal( slots[ 0 ].textContent, 'w' );
	assert.deepEqual( bankTexts( bank ), [ 'h', 'i' ] );
} );

test( 'Enter и Space на слоте работают как тап', () => {
	const { bank, slots, board } = setup();
	bank.byClass( 'fs-tile' )[ 0 ].emit( 'click' );
	slots[ 1 ].emit( 'keydown', { key: 'Enter' } );
	assert.equal( board.slot( 1 ).text, 'h' );
	slots[ 1 ].emit( 'keydown', { key: ' ' } );
	assert.equal( board.slot( 1 ), null );
} );

test( 'перетаскивание: dragstart по плитке и drop на слот', () => {
	const { bank, slots, board } = setup();
	bank.byClass( 'fs-tile' )[ 2 ].emit( 'dragstart' );
	slots[ 1 ].emit( 'drop' );
	assert.equal( board.slot( 1 ).text, 'i' );
	assert.equal( slots[ 1 ].textContent, 'i' );
} );

test( 'drop на банк возвращает плитку, положенную в слот', () => {
	const { bank, slots } = setup();
	bank.byClass( 'fs-tile' )[ 0 ].emit( 'click' );
	slots[ 0 ].emit( 'click' );
	slots[ 0 ].byClass( 'fs-tile' )[ 0 ].emit( 'dragstart' );
	bank.emit( 'drop' );
	assert.equal( slots[ 0 ].classList.contains( 'is-filled' ), false );
	assert.equal( bankTexts( bank ).length, 3 );
} );

test( 'пустой слот не помечен заполненным, плитка в слоте — вложенная', () => {
	const { bank, slots } = setup();
	assert.equal( slots[ 0 ].classList.contains( 'is-filled' ), false );
	bank.byClass( 'fs-tile' )[ 0 ].emit( 'click' );
	slots[ 0 ].emit( 'click' );
	assert.equal( slots[ 0 ].byClass( 'is-placed' ).length, 1 );
} );

test( 'lock: плитки неактивны и не перетаскиваются, слоты не реагируют', () => {
	const { bank, slots, board, view } = setup();
	view.lock();
	const tiles = bank.byClass( 'fs-tile' );
	assert.equal( tiles.every( ( t ) => t.disabled && ! t.draggable ), true );
	tiles[ 0 ].emit( 'click' );
	slots[ 0 ].emit( 'click' );
	assert.equal( board.slot( 0 ), null );
	assert.equal( slots[ 0 ].attributes.tabindex, '-1' );
} );

test( 'restore из ответа перерисовывает слоты и банк', () => {
	const { bank, slots, board, view } = setup();
	board.restore( { 0: 'i', 1: 'h' } );
	view.refresh();
	assert.equal( slots[ 0 ].textContent, 'i' );
	assert.equal( slots[ 1 ].textContent, 'h' );
	assert.deepEqual( bankTexts( bank ), [ 'w' ] );
} );

test( 'onChange доски вызывается на place из интерфейса', () => {
	let calls = 0;
	const board = createBoard( { tiles: [ 'a' ], slots: 1, onChange: () => calls++ } );
	const bank  = new FakeElement();
	const slot  = new FakeElement( 'span' );
	mountTileBoard( { board, bank, slots: [ slot ] } );
	bank.byClass( 'fs-tile' )[ 0 ].emit( 'click' );
	slot.emit( 'click' );
	assert.equal( calls, 1 );
} );

test( 'плитка с индексом: sub/sup рисуются тегами, чужая разметка экранируется, значение для ответа остаётся исходным', () => {
	const board = createBoard( { tiles: [ 'x<sub>1</sub>', '<b>y</b>' ], slots: 1 } );
	const bank  = new FakeElement();
	const slot  = new FakeElement( 'span' );
	mountTileBoard( { board, bank, slots: [ slot ] } );

	const [ first, second ] = bank.byClass( 'fs-tile' );
	assert.equal( first.innerHTML, 'x<sub>1</sub>' );
	assert.equal( second.innerHTML, '&lt;b&gt;y&lt;/b&gt;' );

	first.emit( 'click' );
	slot.emit( 'click' );
	assert.equal( slot.byClass( 'is-placed' )[ 0 ].innerHTML, 'x<sub>1</sub>' );
	assert.deepEqual( board.collect(), { 0: 'x<sub>1</sub>' } );
} );
