import assert from 'node:assert/strict';
import { test } from 'node:test';
import { restoreWorkAnswer } from '../../src/js/player/work-retry.js';

test( 'код черновика сохраняется после верной проверки и обновления страницы', () => {
	assert.deepEqual( restoreWorkAnswer( { check: { status: 'correct', answer: '257' }, draft: { text: '257', code: 'print(257)' } } ), { text: '257', code: 'print(257)' } );
	assert.deepEqual( restoreWorkAnswer( { check: { status: 'correct', answer: { text: '257', code: 'старый код' } }, draft: { text: '257', code: 'новый код' } } ), { text: '257', code: 'новый код' } );
} );

test( 'другой ответ в черновике не подменяет проверенный', () => {
	assert.equal( restoreWorkAnswer( { check: { status: 'correct', answer: '257' }, draft: { text: '258', code: 'print(258)' } } ), '257' );
} );

test( 'для незасчитанных заданий остаётся черновик, иначе используется последняя сдача', () => {
	assert.equal( restoreWorkAnswer( { check: { status: 'wrong', answer: '1' }, draft: '2', submitted: '3' } ), '2' );
	assert.equal( restoreWorkAnswer( { submitted: '3' } ), '3' );
} );
