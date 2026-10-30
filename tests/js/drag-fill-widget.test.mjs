import assert from 'node:assert/strict';
import { test } from 'node:test';

import { FakeElement, installFakeDocument } from './helpers/fake-dom.mjs';

installFakeDocument();
const { initTaskWidget } = await import( '../../src/js/frontend/components/task-widget.js' );

const SEGMENTS = [
	{ type: 'text', content: 'V = ' },
	{ type: 'slot', index: 0 },
	{ type: 'text', content: ' * ' },
	{ type: 'slot', index: 1 },
	{ type: 'text', content: ' * ' },
	{ type: 'slot', index: 2 },
];
const DATA = { type: 'drag_fill', segments: SEGMENTS, tiles: [ 'h', 'w', 'i', 'k', 'd' ] };

function mount( data = DATA, done = false ) {
	const container = new FakeElement();
	container.dataset.widget = JSON.stringify( data );
	if ( done ) { container.dataset.done = '1'; }
	return { container, api: initTaskWidget( { querySelector: () => container } ) };
}

const slots = ( c ) => c.byClass( 'fs-drag-fill-slot' );
const tile  = ( c, text ) => c.byClass( 'fs-tile-bank' )[ 0 ].byClass( 'fs-tile' ).find( ( t ) => t.textContent === text );
const put   = ( c, text, index ) => {
	tile( c, text ).emit( 'click' );
	slots( c )[ index ].emit( 'click' );
};

test( 'формула разбирается на текст и слоты; число слотов = слотам сегментов', () => {
	const { container } = mount();
	assert.equal( slots( container ).length, 3 );
	assert.equal( container.byClass( 'fs-drag-fill-text' ).map( ( t ) => t.textContent ).join( '' ), 'V =  *  * ' );
} );

test( 'формула — в своём блоке крупным шрифтом, банк отдельно', () => {
	const { container } = mount();
	assert.equal( container.byClass( 'fs-drag-fill-formula' ).length, 1 );
	assert.equal( container.byClass( 'fs-tile-bank' ).length, 1 );
} );

test( 'банк содержит все плитки, включая лишние; слотов меньше плиток', () => {
	const { container } = mount();
	assert.equal( container.byClass( 'fs-tile-bank' )[ 0 ].byClass( 'fs-tile' ).length, 5 );
} );

test( 'collectAnswer и setAnswer работают через общую доску; дубли плиток различаются', () => {
	const { container, api } = mount( { ...DATA, tiles: [ 'h', 'h', 'i' ] } );
	put( container, 'i', 2 );
	tile( container, 'h' ).emit( 'click' );
	slots( container )[ 0 ].emit( 'click' );
	tile( container, 'h' ).emit( 'click' );
	slots( container )[ 1 ].emit( 'click' );
	assert.deepEqual( JSON.parse( api.collectAnswer() ), { 0: 'h', 1: 'h', 2: 'i' } );

	const second = mount();
	second.api.setAnswer( { 0: 'w', 1: 'h', 2: 'i' } );
	assert.deepEqual( JSON.parse( second.api.collectAnswer() ), { 0: 'w', 1: 'h', 2: 'i' } );
	assert.equal( slots( second.container )[ 0 ].textContent, 'w' );
} );

test( 'hasAnswer ложен, пока ни один слот не заполнен', () => {
	const { container, api } = mount();
	assert.equal( api.hasAnswer(), false );
	put( container, 'k', 1 );
	assert.equal( api.hasAnswer(), true );
} );

test( 'onChange вызывается на каждое place и release', () => {
	const { container, api } = mount();
	let calls = 0;
	api.onChange( () => calls++ );
	put( container, 'h', 0 );
	slots( container )[ 0 ].emit( 'click' );
	assert.equal( calls, 2 );
} );

test( 'lock отключает ввод; сданный шаг (data-done) открывается залоченным', () => {
	const { container, api } = mount();
	api.lock();
	tile( container, 'h' ).emit( 'click' );
	slots( container )[ 0 ].emit( 'click' );
	assert.equal( api.hasAnswer(), false );

	const done = mount( DATA, true );
	tile( done.container, 'h' ).emit( 'click' );
	slots( done.container )[ 0 ].emit( 'click' );
	assert.equal( done.api.hasAnswer(), false );
} );

test( 'повторная попытка: пересборка на чистом контейнере не помнит прошлый ответ', () => {
	const first = mount();
	put( first.container, 'h', 0 );
	const again = mount();
	assert.equal( again.api.hasAnswer(), false );
	assert.deepEqual( JSON.parse( again.api.collectAnswer() ), { 0: '', 1: '', 2: '' } );
} );

test( 'без сегментов и плиток виджет не падает', () => {
	const { api } = mount( { type: 'drag_fill' } );
	assert.equal( api.hasAnswer(), false );
} );

test( 'формула с индексом и степенью: текст между слотами рисуется тегами', () => {
	const { container } = mount( {
		type:     'drag_fill',
		segments: [ { type: 'text', content: 'S = ' }, { type: 'slot', index: 0 }, { type: 'text', content: '<sup>2</sup>' } ],
		tiles:    [ 'x<sub>1</sub>' ],
	} );

	assert.equal( container.byClass( 'fs-drag-fill-text' )[ 1 ].innerHTML, '<sup>2</sup>' );
	assert.equal( container.byClass( 'fs-tile' )[ 0 ].innerHTML, 'x<sub>1</sub>' );
} );

test( 'в «Пропусках в тексте» текст по-прежнему обычный: теги в нём не оживают', () => {
	const container = new FakeElement();
	container.dataset.widget = JSON.stringify( { type: 'fill', answer_mode: 'tiles', segments: [ { type: 'text', content: 'a <sub>b' }, { type: 'gap', index: 0 } ], tiles: [ 'k' ] } );
	initTaskWidget( { querySelector: () => container } );

	assert.equal( container.byClass( 'fs-widget-fill-text' )[ 0 ].innerHTML, 'a <sub>b' );
} );
