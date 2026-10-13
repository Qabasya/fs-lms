import test from 'node:test';
import assert from 'node:assert/strict';

import { expandPicked, candidateSource } from '../../src/js/admin/modules/picker.js';

test( 'выбранные задания добавляются в порядке выбора', () => {
	const { fresh, skipped } = expandPicked(
		[ { id: '7', title: 'Седьмая' }, { id: 3, title: 'Третья' } ],
		[]
	);

	assert.deepEqual( fresh, [ { id: 7, title: 'Седьмая' }, { id: 3, title: 'Третья' } ] );
	assert.equal( skipped, 0 );
} );

test( 'задания, уже стоящие в работе, и повторы в выборе пропускаются', () => {
	const { fresh, skipped } = expandPicked(
		[ { id: 1, title: 'A' }, { id: 2, title: 'B' }, { id: 2, title: 'B' }, { id: 0, title: 'пусто' } ],
		[ 1 ]
	);

	assert.deepEqual( fresh, [ { id: 2, title: 'B' } ] );
	assert.equal( skipped, 3 );
} );

test( 'связка 19–21 раскладывается на подзадания, сам родитель в работу не идёт', () => {
	const bundle = {
		id:              50,
		title:           'Связка',
		bundle_children: [ { id: 51, title: '19' }, { id: 52, title: '20' }, { id: 53, title: '21' } ],
	};

	const { fresh, skipped } = expandPicked( [ bundle ], [ 52 ] );

	assert.deepEqual( fresh, [ { id: 51, title: '19' }, { id: 53, title: '21' } ] );
	assert.equal( skipped, 1 );
} );

test( 'источник окна: публичные задачи держатся при поиске, банк по поиску расширяется', () => {
	assert.equal( candidateSource( '', 'subject' ), 'subject' );
	assert.equal( candidateSource( 'дроби', 'subject' ), 'all' );
	assert.equal( candidateSource( 'дроби', 'public' ), 'public' );
} );
