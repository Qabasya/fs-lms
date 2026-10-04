import assert from 'node:assert/strict';
import test from 'node:test';

import { renderTask, VERDICT_LABEL } from '../../src/js/profile/task-render.js';

/** Ручная задача экзамена со всеми полями оценивания — худший случай для режима read_only. */
function manualTask(extra = {}) {
    return {
        n: 14,
        task_id: 42,
        anchor: 'u-abc123',
        condition: '<p>Условие</p>',
        answer: '5',
        code: null,
        files: [],
        correct: '7',
        verdict: 'incorrect',
        score: 0,
        max_score: 2,
        manual: true,
        criteria: [{ label: 'К1', max_points: 2, awarded: 1 }],
        oge_rubric: { max_points: 3, html: '<p>Уровни</p>' },
        task_submission_id: 77,
        gradable: true,
        ...extra,
    };
}

const MANAGE_EXAM = { mode: 'manage', kind: 'exam', canGradeAttempt: true, canGradeBatch: true };

test('read_only не содержит ни форм оценивания, ни зачёта, независимо от полей задачи', () => {
    for (const kind of ['exam', 'work']) {
        const html = renderTask(manualTask(), { mode: 'read_only', kind, canGradeAttempt: true, canGradeBatch: true });

        assert.doesNotMatch(html, /stg-save/);
        assert.doesNotMatch(html, /stg-credit/);
        assert.doesNotMatch(html, /sum-task-grade/);
        assert.doesNotMatch(html, /sum-task-credit/);
        assert.doesNotMatch(html, /data-task-id/);
    }
});

test('manage для ручной задачи экзамена содержит форму оценки и зачёт', () => {
    const html = renderTask(manualTask({ criteria: [], oge_rubric: null }), MANAGE_EXAM);

    assert.match(html, /sum-task-grade/);
    assert.match(html, /stg-save/);
    assert.match(html, /stg-credit/);
});

test('manage без транспорта оценки не рисует формы', () => {
    const html = renderTask(manualTask(), { mode: 'manage', kind: 'exam' });

    assert.doesNotMatch(html, /stg-save/);
    assert.doesNotMatch(html, /stg-credit/);
});

test('покритерийное оценивание остаётся только в manage', () => {
    assert.match(renderTask(manualTask(), MANAGE_EXAM), /sum-task-grade--criteria/);
    assert.doesNotMatch(renderTask(manualTask(), { mode: 'read_only', kind: 'exam' }), /sum-task-grade--criteria/);
});

test('эталонный ответ не выводится при вердикте «верно» и выводится при остальных', () => {
    const correct = renderTask(manualTask({ verdict: 'correct' }), { mode: 'read_only', kind: 'exam' });
    const wrong = renderTask(manualTask({ verdict: 'incorrect' }), { mode: 'read_only', kind: 'exam' });

    assert.doesNotMatch(correct, /Правильный ответ/);
    assert.match(wrong, /Правильный ответ/);
});

test('код выводится блоком, файлы — списком; ответ-код без ответа не рисует пустую строку «Ответ»', () => {
    const html = renderTask(
        manualTask({ answer: '', code: 'print(1 < 2)', files: [{ url: 'https://x/f.png', name: 'f.png', mime: 'image/png' }, { url: 'https://x/a.zip', name: 'a.zip', mime: 'application/zip' }] }),
        { mode: 'read_only', kind: 'exam' },
    );

    assert.match(html, /sum-task-code-pre/);
    assert.match(html, /1 &lt; 2/);
    assert.match(html, /sum-task-files__img/);
    assert.match(html, /sum-task-files__link/);
    assert.doesNotMatch(html, /Ответ ученика:/);
});

test('подписи новых вердиктов и якорь задания', () => {
    assert.equal(VERDICT_LABEL.unanswered, 'Не решено');
    assert.equal(VERDICT_LABEL.partial, 'Частично');

    const unanswered = renderTask(manualTask({ verdict: 'unanswered' }), { mode: 'read_only', kind: 'exam' });
    const partial = renderTask(manualTask({ verdict: 'partial' }), { mode: 'read_only', kind: 'exam' });

    assert.match(unanswered, /sv-unanswered">Не решено</);
    assert.match(partial, /sv-partial">Частично</);
    assert.match(unanswered, /<div class="sum-task" id="u-abc123">/);
});

test('без якоря у корня задачи нет атрибута id', () => {
    const html = renderTask(manualTask({ anchor: undefined }), { mode: 'read_only', kind: 'exam' });

    assert.match(html, /<div class="sum-task">/);
});

test('условие и ответ ученика экранируются', () => {
    const html = renderTask(manualTask({ answer: '<script>x</script>' }), { mode: 'read_only', kind: 'exam' });

    assert.doesNotMatch(html, /<script>x<\/script>/);
    assert.match(html, /&lt;script&gt;/);
});
