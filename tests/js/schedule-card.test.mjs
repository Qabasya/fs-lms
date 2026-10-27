import assert from 'node:assert/strict';
import test from 'node:test';

import { scheduleCardHtml } from '../../src/js/profile/schedule-view.js';

/* Карточка «Расписание» одна на кабинет преподавателя и ученика: заголовок, переключатель, тело. */
test('schedule card has the title, the three-mode toggle and the body container', () => {
    const html = scheduleCardHtml();

    assert.match(html, /<h3>Расписание<\/h3>/);
    assert.match(html, /id="profSchedToggle"/);
    for (const mode of ['today', 'week', 'month']) {
        assert.ok(html.includes(`data-mode="${mode}"`), mode);
    }
    assert.match(html, /id="profSchedBody"/);
});

test('schedule card toggle sits in the head so it wraps only when it does not fit', () => {
    const html = scheduleCardHtml();
    const head = html.slice(html.indexOf('prof-card-head'), html.indexOf('</div>', html.indexOf('profSchedToggle')) + 6);

    assert.ok(head.includes('profSchedToggle'));
    assert.ok(html.indexOf('profSchedBody') > html.indexOf('profSchedToggle'));
});
