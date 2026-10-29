import assert from 'node:assert/strict';
import { test } from 'node:test';

import { FakeElement, installFakeDocument } from './helpers/fake-dom.mjs';

installFakeDocument();
const { initTaskWidget } = await import( '../../src/js/frontend/components/task-widget.js' );

const SEGMENTS = [
	{ type: 'text', content: 'Это ' },
	{ type: 'gap', index: 0 },
	{ type: 'text', content: ' и ' },
	{ type: 'gap', index: 1 },
];

function mount( data ) {
	const container = new FakeElement();
	container.dataset.widget = JSON.stringify( { type: 'fill', segments: SEGMENTS, ...data } );
	const panel = { querySelector: () => container };
	return { container, api: initTaskWidget( panel ) };
}

const tilesMode = { answer_mode: 'tiles', tiles: [ 'кот', 'пёс', 'ёж' ] };
const tile      = ( container, text ) => container.byClass( 'fs-tile' ).find( ( t ) => t.textContent === text );

test( 'режим ввода: поля input, плиток нет', () => {
	const { container } = mount( {} );
	assert.equal( container.byClass( 'fs-widget-fill-gap' ).length, 2 );
	assert.equal( container.byClass( 'fs-tile' ).length, 0 );
} );

test( 'режим плиток: слоты вместо input и банк плиток', () => {
	const { container } = mount( tilesMode );
	assert.equal( container.byClass( 'fs-widget-fill-gap' ).length, 0 );
	assert.equal( container.byClass( 'fs-widget-fill-slot' ).length, 2 );
	assert.equal( container.byClass( 'fs-tile' ).length, 3 );
} );

test( 'плитка в слоте: слот заполнен, в банке её нет, текст слота — текст плитки', () => {
	const { container } = mount( tilesMode );
	tile( container, 'пёс' ).emit( 'click' );
	container.byClass( 'fs-widget-fill-slot' )[ 1 ].emit( 'click' );
	const slot = container.byClass( 'fs-widget-fill-slot' )[ 1 ];
	assert.equal( slot.classList.contains( 'is-filled' ), true );
	assert.equal( slot.textContent, 'пёс' );
	assert.equal( container.byClass( 'fs-tile-bank' )[ 0 ].byClass( 'fs-tile' ).length, 2 );
} );

test( 'collectAnswer отдаёт {индекс: слово}, setAnswer восстанавливает', () => {
	const { container, api } = mount( tilesMode );
	tile( container, 'кот' ).emit( 'click' );
	container.byClass( 'fs-widget-fill-slot' )[ 0 ].emit( 'click' );
	assert.deepEqual( JSON.parse( api.collectAnswer() ), { 0: 'кот', 1: '' } );
	assert.equal( api.hasAnswer(), true );

	const second = mount( tilesMode );
	second.api.setAnswer( { 0: 'ёж', 1: 'кот' } );
	assert.deepEqual( JSON.parse( second.api.collectAnswer() ), { 0: 'ёж', 1: 'кот' } );
} );

test( 'без плиток в слотах hasAnswer ложен, onChange срабатывает на каждую постановку', () => {
	const { container, api } = mount( tilesMode );
	let calls = 0;
	api.onChange( () => calls++ );
	assert.equal( api.hasAnswer(), false );
	tile( container, 'кот' ).emit( 'click' );
	container.byClass( 'fs-widget-fill-slot' )[ 0 ].emit( 'click' );
	assert.equal( calls, 1 );
} );

test( 'lock после проверки: плитки неактивны, слоты не принимают', () => {
	const { container, api } = mount( tilesMode );
	api.lock();
	tile( container, 'кот' ).emit( 'click' );
	container.byClass( 'fs-widget-fill-slot' )[ 0 ].emit( 'click' );
	assert.equal( api.hasAnswer(), false );
} );

test( 'шаг, уже сданный (data-done), открывается залоченным', () => {
	const container = new FakeElement();
	container.dataset.widget = JSON.stringify( { type: 'fill', segments: SEGMENTS, ...tilesMode } );
	container.dataset.done   = '1';
	const api = initTaskWidget( { querySelector: () => container } );
	tile( container, 'кот' ).emit( 'click' );
	container.byClass( 'fs-widget-fill-slot' )[ 0 ].emit( 'click' );
	assert.equal( api.hasAnswer(), false );
} );
