import assert from 'node:assert/strict';
import test from 'node:test';

import { DRAG_THRESHOLD_PX, arrowStep, isDrag } from '../../src/js/profile/course-tabs.js';

test('сдвиг в пределах порога — это клик, а не перетаскивание', () => {
	assert.equal(isDrag(0), false);
	assert.equal(isDrag(DRAG_THRESHOLD_PX), false, 'ровно порог — ещё клик');
	assert.equal(isDrag(-DRAG_THRESHOLD_PX), false);
});

test('сдвиг больше порога в любую сторону — перетаскивание, клик после него глотается', () => {
	assert.equal(isDrag(DRAG_THRESHOLD_PX + 1), true);
	assert.equal(isDrag(-(DRAG_THRESHOLD_PX + 1)), true);
	assert.equal(isDrag(240), true);
});

test('стрелка листает на одну карточку с промежутком, если ширина карточки известна', () => {
	assert.equal(arrowStep(900, 280, 10), 290);
});

test('без карточки стрелка листает на 80% ширины ленты, как у вкладок курсов', () => {
	assert.equal(arrowStep(900, 0, 10), 720);
});
