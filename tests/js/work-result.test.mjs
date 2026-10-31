import assert from 'node:assert/strict';
import { test } from 'node:test';
import { pendingWorkPoints, workPointsLabel, workTaskMaximum, workTaskVerdictHtml, workSubmissionFromResponse, mergeWorkTaskResult } from '../../src/js/player/work-result.js';

for ( const [ score, label ] of [ [ 1, '1 балл' ], [ 2, '2 балла' ], [ 5, '5 баллов' ], [ 11, '11 баллов' ], [ 21, '21 балл' ] ] ) {
    test( `correct result displays server award +${ label }`, () => {
        const html = workTaskVerdictHtml( { verdict: 'correct', score, maxScore: score }, null );
        assert.match( html, new RegExp( `Верно · \\+${ label }` ) );
        assert.equal( workPointsLabel( score ), label );
    } );
}

test( 'stored result points override a stale automatic verdict; corrected task retains solution', () => {
    const html = workTaskVerdictHtml( { verdict: 'correct', score: 1, corrected: true }, { score: 2 }, '<p>Открытое решение</p>' );
    assert.match( html, /vd-warn/ );
    assert.match( html, /Верно · \+2 балла/ );
    assert.match( html, /потом ответ исправлен/ );
    assert.match( html, /<p>Открытое решение<\/p>/ );
} );

test( 'pending manual result awards no points and reveals no solution before review', () => {
    const html = workTaskVerdictHtml( { verdict: 'pending', score: 0, maxScore: 5 }, { status: 'pending_review', score: 0, max_score: 5 }, '<p>Скрытый ответ</p>' );
    assert.match( html, /На проверке у преподавателя/ );
    assert.doesNotMatch( html, /\+5|\+0|Скрытый ответ|vd-solution/ );
} );

test( 'graded manual result displays actual awarded score and persisted maximum, including zero', () => {
    const verdict = { verdict: 'pending', score: 0, maxScore: 1 };
    const partial = workTaskVerdictHtml( verdict, { status: 'graded', score: 2.5, max_score: 5, feedback: 'Частичный ответ <script>' } );
    assert.match( partial, /Проверено · 2.5 \/ 5 баллов/ );
    assert.match( partial, /&lt;script&gt;/ );
    const zero = workTaskVerdictHtml( verdict, { status: 'graded', score: 0, max_score: 2 } );
    assert.match( zero, /Проверено · 0 \/ 2 балла/ );
} );

test( 'wrong result remains zero even if an answer solution is available', () => {
    const html = workTaskVerdictHtml( { verdict: 'incorrect', score: 0, maxScore: 5 }, null, '<p>Разрешённое решение</p>' );
    assert.match( html, /Неверно · 0 баллов/ );
    assert.match( html, /<p>Разрешённое решение<\/p>/ );
    assert.doesNotMatch( html, /\+5/ );
} );

test( 'manual pending summary sums snapshot maxima instead of counting tasks and excludes already graded tasks', () => {
    const verdicts = { 1: { verdict: 'pending', maxScore: 2 }, 2: { verdict: 'pending', maxScore: 5 }, 3: { verdict: 'pending', maxScore: 8 }, 4: { verdict: 'correct', maxScore: 10 } };
    const results = { 1: { status: 'pending_review', max_score: 3 }, 3: { status: 'graded', score: 4, max_score: 8 } };
    assert.equal( pendingWorkPoints( verdicts, results, { 1: 100, 2: 100 } ), 8 );
    assert.equal( workTaskMaximum( verdicts[ 1 ], results[ 1 ], 100 ), 3 );
    assert.equal( workTaskMaximum( null, null, 2 ), 2 );
} );

test( 'missing server award never invents a point for a correct task', () => {
    assert.match( workTaskVerdictHtml( { verdict: 'correct' }, null ), /Верно · \+0 баллов/ );
} );


test( 'submit summary prefers persisted fractional score and zero over task counts', () => {
    const result = workSubmissionFromResponse( { status: 'graded', score: 2.5, max_score: 7, correct: 1, total: 2, per_task: { 3: { verdict: 'correct', score: 2 } } } );
    assert.equal( result.score, 2.5 );
    assert.equal( result.max_score, 7 );
    assert.equal( workSubmissionFromResponse( { score: 0, max_score: 0, correct: 4, total: 5 } ).score, 0 );
} );

test( 'retry updates an earlier graded zero automatically using server points and retains its frozen maximum', () => {
    const previous = { status: 'graded', score: 0, max_score: 2, answer: 'wrong' };
    const result = mergeWorkTaskResult( previous, { verdict: 'correct', score: 2, maxScore: 2 }, 'correct' );
    assert.equal( result.score, 2 );
    assert.equal( result.max_score, 2 );
    assert.equal( result.answer, 'correct' );
    assert.match( workTaskVerdictHtml( { verdict: 'correct', score: 2 }, result ), /Верно · \+2 балла/ );
    assert.equal( previous.score, 0 );
} );

test( 'a locked graded manual result survives a retry pending verdict and shows no unearned maximum', () => {
    const previous = { status: 'graded', score: 1.5, max_score: 5, answer: 'essay' };
    const result = mergeWorkTaskResult( previous, { verdict: 'pending', score: 0, maxScore: 5 }, 'essay' );
    assert.equal( result.score, 1.5 );
    assert.equal( result.status, 'graded' );
    assert.match( workTaskVerdictHtml( { verdict: 'pending' }, result ), /Проверено · 1.5 \/ 5 баллов/ );
    assert.equal( pendingWorkPoints( { 3: { verdict: 'pending', maxScore: 5 } }, { 3: result }, {} ), 0 );
} );


test( 'manual partial review uses persisted score when aggregate snapshot says incorrect', () => {
    const html = workTaskVerdictHtml( { verdict: 'incorrect', score: 2, maxScore: 5 },
        { status: 'graded', score: 2, max_score: 5, feedback: 'Есть верная часть' }, '', true );
    assert.match( html, /Проверено · 2 \/ 5 баллов/ );
    assert.match( html, /Есть верная часть/ );
    assert.doesNotMatch( html, /Неверно · 0 баллов/ );
    const zero = workTaskVerdictHtml( { verdict: 'incorrect', score: 0, maxScore: 5 },
        { status: 'graded', score: 0, max_score: 5 }, '', true );
    assert.match( zero, /Проверено · 0 \/ 5 баллов/ );
} );
