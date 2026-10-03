import assert from 'node:assert/strict';
import { test } from 'node:test';

import { syncCourseTabs } from '../../src/js/profile/course-tabs.js';

test('первая вкладка не уезжает и не показывает стрелку назад после раскрытия экрана', () => {
	const previousResizeObserver = globalThis.ResizeObserver;
	let onResize;
	globalThis.ResizeObserver = class {
		constructor(callback) { onResize = callback; }
		observe() {}
	};
	try {
		const classes = new Set();
		const buttons = {
			'-1': { hidden: true, addEventListener() {} },
			'1': { hidden: true, addEventListener() {} },
		};
		const wrap = {
			dataset: {},
			classList: { toggle(name, enabled) { if (enabled) { classes.add(name); } else { classes.delete(name); } } },
			querySelector(selector) { return buttons[selector.includes('"-1"') ? '-1' : '1']; },
		};
		const first = { offsetLeft: 2, offsetWidth: 240 };
		const listeners = {};
		const tabs = {
			clientWidth: 0,
			scrollWidth: 650,
			scrollLeft: 0,
			firstElementChild: first,
			closest() { return wrap; },
			querySelector() { return first; },
			addEventListener(name, callback) { listeners[name] = callback; },
			scrollTo({ left }) { this.scrollLeft = left; },
		};

		syncCourseTabs(tabs);
		assert.equal(wrap.dataset.alignPending, '1');

		tabs.clientWidth = 360;
		tabs.scrollLeft = 42;
		onResize();
		assert.equal(tabs.scrollLeft, 0);
		assert.equal(buttons['-1'].hidden, true);
		assert.equal(classes.has('is-at-start'), true);

		// Scroll-snap может сдвинуть ленту на её внутренний паддинг.
		tabs.scrollLeft = 2;
		listeners.scroll();
		assert.equal(buttons['-1'].hidden, true);
		assert.equal(classes.has('is-at-start'), true);
	} finally {
		globalThis.ResizeObserver = previousResizeObserver;
	}
});
