import assert from 'node:assert/strict';
import { test } from 'node:test';

import { createBoard } from '../../src/js/common/tile-board.js';

const make = () => createBoard( { tiles: [ 'h', 'w', 'i' ], slots: 2 } );
const idOf = ( board, text ) => board.bank().find( ( t ) => t.text === text ).id;

test( 'place кладёт плитку в пустой слот, и она уходит из банка', () => {
	const board = make();
	board.place( idOf( board, 'h' ), 0 );
	assert.equal( board.slot( 0 ).text, 'h' );
	assert.deepEqual( board.bank().map( ( t ) => t.text ), [ 'w', 'i' ] );
} );

test( 'place в занятый слот возвращает прежнюю плитку в банк', () => {
	const board = make();
	board.place( idOf( board, 'h' ), 0 );
	board.place( idOf( board, 'w' ), 0 );
	assert.equal( board.slot( 0 ).text, 'w' );
	assert.deepEqual( board.bank().map( ( t ) => t.text ).sort(), [ 'h', 'i' ] );
} );

test( 'плитка, переложенная из слота в слот, освобождает прежний слот', () => {
	const board = make();
	const id    = idOf( board, 'h' );
	board.place( id, 0 );
	board.place( id, 1 );
	assert.equal( board.slot( 0 ), null );
	assert.equal( board.slot( 1 ).text, 'h' );
} );

test( 'release возвращает плитку в банк', () => {
	const board = make();
	board.place( idOf( board, 'h' ), 1 );
	board.release( 1 );
	assert.equal( board.slot( 1 ), null );
	assert.equal( board.bank().length, 3 );
} );

test( 'тап: выбор плитки, затем тап по слоту кладёт её', () => {
	const board = make();
	const id    = idOf( board, 'w' );
	board.select( id );
	assert.equal( board.selected(), id );
	board.tapSlot( 1 );
	assert.equal( board.slot( 1 ).text, 'w' );
	assert.equal( board.selected(), null );
} );

test( 'тап: повторный тап по выбранной плитке снимает выбор', () => {
	const board = make();
	const id    = idOf( board, 'w' );
	board.select( id );
	board.select( id );
	assert.equal( board.selected(), null );
} );

test( 'тап по занятому слоту без выбранной плитки возвращает её в банк', () => {
	const board = make();
	board.place( idOf( board, 'h' ), 0 );
	board.tapSlot( 0 );
	assert.equal( board.slot( 0 ), null );
} );

test( 'тап по пустому слоту без выбора ничего не делает', () => {
	const board = make();
	board.tapSlot( 0 );
	assert.equal( board.slot( 0 ), null );
	assert.equal( board.bank().length, 3 );
} );

test( 'drag: dragStart запоминает плитку, drop на слот кладёт её', () => {
	const board = make();
	const id    = idOf( board, 'i' );
	board.dragStart( id );
	board.drop( { slot: 0 } );
	assert.equal( board.slot( 0 ).text, 'i' );
	assert.equal( board.dragging(), null );
} );

test( 'drag: drop на банк возвращает плитку из слота', () => {
	const board = make();
	const id    = idOf( board, 'i' );
	board.place( id, 0 );
	board.dragStart( id );
	board.drop( { bank: true } );
	assert.equal( board.slot( 0 ), null );
	assert.equal( board.bank().length, 3 );
} );

test( 'drop без dragStart ничего не делает', () => {
	const board = make();
	board.drop( { slot: 0 } );
	assert.equal( board.slot( 0 ), null );
} );

test( 'collect отдаёт слот → текст, пустые — пустая строка', () => {
	const board = make();
	board.place( idOf( board, 'w' ), 1 );
	assert.deepEqual( board.collect(), { 0: '', 1: 'w' } );
} );

test( 'restore — обратная операция collect и не трогает неизвестное', () => {
	const board = make();
	board.restore( { 0: 'i', 1: 'zzz' } );
	assert.equal( board.slot( 0 ).text, 'i' );
	assert.equal( board.slot( 1 ), null );
	assert.deepEqual( board.bank().map( ( t ) => t.text ), [ 'h', 'w' ] );
} );

test( 'lock отключает все операции', () => {
	const board = make();
	board.place( idOf( board, 'h' ), 0 );
	board.lock();
	assert.equal( board.locked(), true );
	board.place( idOf( board, 'w' ), 1 );
	board.release( 0 );
	board.select( idOf( board, 'w' ) );
	board.dragStart( idOf( board, 'w' ) );
	assert.equal( board.slot( 0 ).text, 'h' );
	assert.equal( board.slot( 1 ), null );
	assert.equal( board.selected(), null );
	assert.equal( board.dragging(), null );
} );

test( 'дубли с одинаковым текстом различаются по id', () => {
	const board = createBoard( { tiles: [ 'h', 'h', 'i' ], slots: 2 } );
	const [ a, b ] = board.bank().filter( ( t ) => t.text === 'h' );
	assert.notEqual( a.id, b.id );
	board.place( a.id, 0 );
	board.place( b.id, 1 );
	assert.deepEqual( board.collect(), { 0: 'h', 1: 'h' } );
	board.restore( { 0: 'h', 1: 'h' } );
	assert.deepEqual( board.collect(), { 0: 'h', 1: 'h' } );
	assert.deepEqual( board.bank().map( ( t ) => t.text ), [ 'i' ] );
} );

test( 'onChange вызывается на каждое изменение, но не на пустые операции', () => {
	let calls = 0;
	const board = createBoard( { tiles: [ 'h', 'w' ], slots: 1, onChange: () => calls++ } );
	board.place( board.bank()[ 0 ].id, 0 );
	board.release( 0 );
	board.release( 0 );
	assert.equal( calls, 2 );
} );
