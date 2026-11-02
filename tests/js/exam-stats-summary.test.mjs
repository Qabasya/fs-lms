import assert from 'node:assert/strict';
import test from 'node:test';
import { summaryRows } from '../../src/js/profile/exams/exam-stats.js';

test('строки сводки выравниваются по номерам заданий и не сдвигают итоги', () => {
    const html = summaryRows(['1', '3'], { works: 4, columns: [
        { number: '3', attempts: 2, correct: 1, attempts_share: 50, correct_share: 25 },
        { number: '1', attempts: 4, correct: 3, attempts_share: 100, correct_share: 75 },
    ] });
    assert.match(html, /Попытки<\/th><td class="exam-cell">4<\/td><td class="exam-cell">2<\/td>/);
    assert.match(html, /Верные решения/);
    assert.match(html, /Процент попыток, %/);
    assert.match(html, /Процент верных, %/);
    assert.equal((html.match(/exam-grid-sum/g) || []).length, 8);
});
