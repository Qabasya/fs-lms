import test from 'node:test';
import assert from 'node:assert/strict';
import { courseKey, initialCourseKey, canStartCourse, previewLessons, createCourseStarter } from '../../src/js/profile/learner-course-state.js';
const offer = { id: null, key: 'course:12', course_id: 12, group_id: null, enrolled: false, can_start: true };

test('course/group numeric collisions and two groups of one course have distinct keys', () => {
    const cards = [{ id: 12, group_id: 12, course_id: 12 }, { id: 15, group_id: 15, course_id: 12 }, offer];
    assert.deepEqual(cards.map(courseKey), ['group:12', 'group:15', 'course:12']);
    assert.equal(initialCourseKey(cards, null, 12), 'group:12');
    assert.equal(initialCourseKey(cards, 'course:12', 12), 'course:12');
    assert.equal(initialCourseKey(cards, 'gone', 15), 'group:15');
});

test('parent, exam lock, enrolled card, unavailable offer never start', () => {
    assert.equal(canStartCourse(offer), true);
    assert.equal(canStartCourse(offer, { parent: true }), false);
    assert.equal(canStartCourse(offer, { examLock: {} }), false);
    assert.equal(canStartCourse({ ...offer, enrolled: true }), false);
    assert.equal(canStartCourse({ ...offer, can_start: false }), false);
});

test('preview preserves all names but strips content, identifiers and playable links', () => {
    const rows = previewLessons([{ num: 1, title: 'Первый', player_url: '/private', status: 'available', payload: { answer: 'x' }, lesson_id: 12 }, { num: 2, title: 'Второй' }]);
    assert.deepEqual(rows.map(row => row.title), ['Первый', 'Второй']);
    assert.ok(rows.every(row => row.status === 'locked' && row.player_url === '' && !('payload' in row) && !('lesson_id' in row)));
});

test('start suppresses parallel click then reloads and selects real group', async () => {
    let finish, starts = 0, selected, rendered;
    const starter = createCourseStarter({
        start: () => { starts++; return new Promise(resolve => { finish = resolve; }); },
        reload: async () => ({ courses: [{ key: 'group:90' }] }),
        select: key => { selected = key; }, render: profile => { rendered = profile; },
    });
    const pending = starter.run(offer, {});
    assert.equal(await starter.run(offer, {}), false);
    finish({ group_id: 90 });
    assert.equal(await pending, true);
    assert.equal(starts, 1);
    assert.equal(selected, 'group:90');
    assert.equal(rendered.courses.length, 1);
    assert.equal(starter.isBusy(), false);
});

test('error leaves selection untouched and permits retry; guards perform no request', async () => {
    let selected = 'course:12', starts = 0;
    const starter = createCourseStarter({ start: async () => { starts++; throw new Error('Ошибка сети'); }, reload: async () => {}, select: key => { selected = key; }, render: () => {} });
    await assert.rejects(starter.run(offer, {}), /Ошибка сети/);
    assert.equal(selected, 'course:12');
    assert.equal(starter.isBusy(), false);
    assert.equal(await starter.run(offer, { parent: true }), false);
    assert.equal(await starter.run(offer, { examLock: {} }), false);
    assert.equal(starts, 1);
});

test('selection changed during request stays selected after successful reload', async () => {
    let selected = 'course:8';
    const starter = createCourseStarter({ start: async () => ({ group_id: 90 }), reload: async () => ({}), current: () => selected, select: key => { selected = key; }, render: () => { assert.equal(starter.isBusy(), false); } });
    await starter.run(offer, {});
    assert.equal(selected, 'course:8');
});

test('leaving screen while starting does not select or render stale profile', async () => {
    let calls = 0;
    const starter = createCourseStarter({ start: async () => ({ group_id: 90 }), reload: async () => ({}), select: () => { calls++; }, render: () => { calls++; } });
    await starter.run(offer, { isCurrent: () => false });
    assert.equal(calls, 0);
    assert.equal(starter.isBusy(), false);
});
