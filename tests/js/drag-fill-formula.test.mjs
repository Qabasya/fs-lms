import assert from 'node:assert/strict';
import { test } from 'node:test';

import { countSlots, setAllGroups } from '../../src/js/common/drag-fill-formula.js';

test( 'число слотов — наибольший номер {N}', () => {
	assert.equal( countSlots( 'V = {1} * {2} * {3}' ), 3 );
	assert.equal( countSlots( '{2} + {1}' ), 2 );
	assert.equal( countSlots( 'без слотов' ), 0 );
	assert.equal( countSlots( '' ), 0 );
} );

test( '«Порядок неважен»: всем слотам одна группа 1', () => {
	assert.deepEqual( setAllGroups( 3 ), [ 1, 1, 1 ] );
	assert.deepEqual( setAllGroups( 0 ), [] );
} );
