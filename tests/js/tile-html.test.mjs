import assert from 'node:assert/strict';
import { test } from 'node:test';

import { tileHtml, tilePlain } from '../../src/js/common/tile-html.js';

test( 'индекс и степень выводятся тегами, остальное экранируется', () => {
	assert.equal( tileHtml( 'x<sub>1</sub> + 2<sup>n</sup>' ), 'x<sub>1</sub> + 2<sup>n</sup>' );
	assert.equal( tileHtml( 'a < b & "c"' ), 'a &lt; b &amp; &quot;c&quot;' );
} );

test( 'чужая разметка и атрибуты не проходят', () => {
	assert.equal( tileHtml( '<img src=x onerror=1>' ), '&lt;img src=x onerror=1&gt;' );
	assert.equal( tileHtml( '<sub onclick="e()">1</sub>' ), '&lt;sub onclick=&quot;e()&quot;&gt;1</sub>' );
} );

test( 'пустое и не строка не падают', () => {
	assert.equal( tileHtml( '' ), '' );
	assert.equal( tileHtml( undefined ), '' );
} );

test( 'plain — читаемый текст без sub/sup', () => {
	assert.equal( tilePlain( 'x<sub>1</sub> + 2<sup>n</sup>' ), 'x1 + 2n' );
} );
