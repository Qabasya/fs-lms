import assert from 'node:assert/strict';
import { test } from 'node:test';
import { workSlot, workTaskPoints } from '../../src/js/admin/services/work-task-points.js';
import { createSlotSaveQueue } from '../../src/js/admin/services/slot-persistence.js';

test('work slots reopen persisted scores and default legacy/invalid scores to one', () => {
    assert.equal(workSlot({payload:{ref:10}, points:2}, 0).points, 2);
    assert.equal(workSlot({payload:{ref:10}}, 0).points, 1);
    assert.equal(workSlot({payload:{ref:10}, points:1000}, 0).points, 1);
});

test('slot score follows replacement/reordering; empty and removed tasks never leak into payload', () => {
    const first = workSlot({payload:{ref:10}, points:2}, 0);
    const second = workSlot({payload:{ref:20}, points:3}, 1);
    first.taskId = 30;
    assert.deepEqual(workTaskPoints([second, first, {taskId:0, points:8}]), {20:3, 30:2});
    assert.deepEqual(workTaskPoints([first]), {30:2});
});

test('slot autosave snapshots score changes immediately and writes in order despite reverse response delays', async () => {
    const started = [];
    let persisted;
    let release;
    const firstGate = new Promise(resolve => { release = resolve; });
    const save = createSlotSaveQueue(async slots => {
        started.push(slots);
        if(started.length === 1) await firstGate;
        persisted = workTaskPoints(slots);
    });
    const slots = [{taskId:10, points:1}];
    const first = save(slots);
    slots[0].points = 2;
    const second = save(slots);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(started.length, 1);
    assert.equal(started[0][0].points, 1);
    release();
    await Promise.all([first,second]);
    assert.deepEqual(persisted,{10:2});
});

test('one failed slot save does not block later edits', async () => {
    let calls = 0;
    const save = createSlotSaveQueue(async slots => {
        if (++calls === 1) throw new Error('offline');
        return workTaskPoints(slots);
    });
    const first = save([{taskId:10, points:1}]);
    const second = save([{taskId:10, points:2}]);
    await assert.rejects(first, /offline/);
    assert.deepEqual(await second, {10:2});
});
