import { test } from 'node:test';
import assert from 'node:assert/strict';
import { examStartPhase, msToNextPhase } from '../../src/js/common/exam-start-phase.js';

test('фазы: далеко, скоро, идёт, завершён', () => {
    assert.equal(examStartPhase(301, 4000), 'far');
    assert.equal(examStartPhase(300, 3900), 'soon');
    assert.equal(examStartPhase(0, 3600), 'running');
    assert.equal(examStartPhase(-5, 0), 'ended');
});

test('следующая смена фазы: порог «скоро», начало, конец окна', () => {
    assert.equal(msToNextPhase(900, 4500), 600000);
    assert.equal(msToNextPhase(120, 3720), 120000);
    assert.equal(msToNextPhase(0, 3600), 3600000);
    assert.equal(msToNextPhase(0, 0), null);
});
