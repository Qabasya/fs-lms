import assert from 'node:assert/strict';
import { test } from 'node:test';

import { hasUnsolved, retryAvailable } from '../../src/js/player/work-retry.js';

const v = ( verdict ) => ( { verdict } );

test( 'нерешённое — неверный ответ или ответа нет; верное и ждущее ручной проверки — нет', () => {
	assert.equal( hasUnsolved( { 1: v( 'correct' ), 2: v( 'correct' ) }, [ '1', '2' ] ), false );
	assert.equal( hasUnsolved( { 1: v( 'correct' ), 2: v( 'incorrect' ) }, [ '1', '2' ] ), true );
	assert.equal( hasUnsolved( { 1: v( 'correct' ), 2: v( 'pending' ) }, [ '1', '2' ] ), false );
	assert.equal( hasUnsolved( { 1: v( 'correct' ) }, [ '1', '2' ] ), true, 'задание без вердикта — ответа не дано' );
} );

test( 'кнопка «Исправить и сдать заново» есть, пока есть что исправлять, остались попытки и срок не вышел', () => {
	const base = { left: Infinity, allLocked: false, pastDue: false, verdicts: { 1: v( 'incorrect' ) }, taskIds: [ '1' ] };

	assert.equal( retryAvailable( base ), true );
	assert.equal( retryAvailable( { ...base, left: 2 } ), true );
} );

test( 'кнопки нет: всё решено верно, попытки закончились, срок вышел, всё уже засчитано', () => {
	const base = { left: 3, allLocked: false, pastDue: false, verdicts: { 1: v( 'incorrect' ) }, taskIds: [ '1' ] };

	assert.equal( retryAvailable( { ...base, verdicts: { 1: v( 'correct' ) } } ), false );
	assert.equal( retryAvailable( { ...base, left: 0 } ), false );
	assert.equal( retryAvailable( { ...base, pastDue: true } ), false );
	assert.equal( retryAvailable( { ...base, allLocked: true } ), false );
} );
