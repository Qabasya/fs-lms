import assert from 'node:assert/strict';
import { test } from 'node:test';

import { nextChrome, TUCK_AFTER, DIRECTION_DELTA } from '../../src/js/profile/scroll-chrome-model.js';
import { shouldShowToTop, SHOW_AFTER_SCREENS } from '../../src/js/common/scroll-to-top.js';

const VIEW = 800;

test('«Наверх» появляется только глубже полутора экранов', () => {
	assert.equal(shouldShowToTop(VIEW * SHOW_AFTER_SCREENS - 1, VIEW), false);
	assert.equal(shouldShowToTop(VIEW * SHOW_AFTER_SCREENS, VIEW), true);
});

test('у начала экрана шапка на месте и не «приподнята»', () => {
	const next = nextChrome({ top: 0, tucked: false }, 0, VIEW);
	assert.deepEqual(next, { top: 0, tucked: false, scrolled: false, showTop: false });
});

test('прокрутка вниз прячет шапку, вверх — возвращает', () => {
	const down = nextChrome({ top: 200, tucked: false }, 200 + DIRECTION_DELTA + 1, VIEW);
	assert.equal(down.tucked, true);
	assert.equal(down.scrolled, true);

	const up = nextChrome(down, down.top - DIRECTION_DELTA - 1, VIEW);
	assert.equal(up.tucked, false);
});

test('дрожание в пределах порога направление не меняет', () => {
	const tucked = { top: 300, tucked: true };
	assert.equal(nextChrome(tucked, 300 - DIRECTION_DELTA, VIEW).tucked, true);
	const shown = { top: 300, tucked: false };
	assert.equal(nextChrome(shown, 300 + DIRECTION_DELTA, VIEW).tucked, false);
});

test('в пределах TUCK_AFTER от начала шапка не прячется даже при прокрутке вниз', () => {
	const next = nextChrome({ top: 0, tucked: false }, TUCK_AFTER, VIEW);
	assert.equal(next.tucked, false);
});

test('возврат к началу снимает «спрятано»', () => {
	assert.equal(nextChrome({ top: 500, tucked: true }, 0, VIEW).tucked, false);
});
