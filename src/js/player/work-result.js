/** Отображение баллов работы: начисления и максимумы приходят только с сервера. */
import { pluralRu } from '../common/plural.js';
import { esc, ICO } from './icons.js';

export function workPointsLabel( value ) {
    const points = Number( value );
    return `${ points } ${ pluralRu( points, 'балл', 'балла', 'баллов' ) }`;
}

/** Снимок сдачи имеет приоритет над текущей ценой из редактора работы. */
export function workTaskMaximum( verdict, result, current = 1 ) {
    return Number( result?.max_score ?? verdict?.maxScore ?? current );
}

/** Ожидающие проверки задания дают только потенциальный максимум, не начисленный балл. */
export function pendingWorkPoints( verdicts, results, currentPoints ) {
    return Object.entries( verdicts ).reduce( ( sum, [ taskId, verdict ] ) => {
        return sum + ( verdict?.verdict === 'pending' && results[ taskId ]?.status !== 'graded'
            ? workTaskMaximum( verdict, results[ taskId ], currentPoints[ taskId ] ?? 1 ) : 0 );
    }, 0 );
}

/** Полный снимок итогов ответа сервера; количество задач не заменяет взвешенные баллы. */
export function workSubmissionFromResponse( response ) {
    return {
        status: response.status,
        status_label: response.status_label,
        score: response.score ?? response.correct,
        max_score: response.max_score ?? response.total,
        verdicts: response.per_task || {},
        lesson_results: response.lesson_results || [],
        submitted_at: response.submitted_at,
    };
}

/** Обновляет автопроверку после пересдачи, сохраняя уже выставленную ручную оценку. */
export function mergeWorkTaskResult( previous, verdict, answer ) {
    const result = { ...previous, answer };
    if ( ! verdict || ( verdict.verdict === 'pending' && previous?.status === 'graded' ) ) return result;
    return { ...result, score: verdict.score, max_score: verdict.maxScore,
        status: verdict.verdict === 'pending' ? 'pending_review' : 'submitted' };
}

function reviewedByTeacher( verdict, result, manual ) {
    return result?.status === 'graded' && ( manual || !verdict || verdict.verdict === 'pending' );
}

function hasFullScore( score, maximum ) {
    return maximum > 0 && Number( score ) >= maximum;
}

/** Сводка по всем заданиям, включая задания без ответа и ожидающие проверки. */
export function workResultSummary( tasks, verdicts, results ) {
    let correct = 0;
    let pending = 0;
    for ( const { id, manual, maximum } of tasks ) {
        const verdict = verdicts[ id ];
        const result = results[ id ];
        if ( reviewedByTeacher( verdict, result, manual ) ) {
            if ( hasFullScore( result.score, workTaskMaximum( verdict, result, maximum ) ) ) ++correct;
        } else if ( verdict?.verdict === 'correct' ) {
            ++correct;
        } else if ( verdict?.verdict === 'pending' || result?.status === 'pending_review' ) {
            ++pending;
        }
    }
    const total = tasks.length;
    const allCorrect = total > 0 && correct === total;
    const title = pending ? 'Работа на проверке' : allCorrect ? 'Работа завершена' : 'Работа проверена.';
    return { correct, total, pending, allCorrect, title };
}

export function workTaskVerdictHtml( verdict, result, solution = '', manual = false, currentMaximum = 1 ) {
    if ( reviewedByTeacher( verdict, result, manual ) ) {
        const maximum = workTaskMaximum( verdict, result, currentMaximum );
        const correct = hasFullScore( result.score, maximum );
        const label = correct ? 'Проверено' : 'Решено неверно или не решено';
        return vdBlock( correct ? 'ok' : 'no', `${ label } · ${ result.score ?? 0 } / ${ workPointsLabel( maximum ) }`, result.feedback || '' );
    }
    if ( ! verdict ) return vdBlock( 'wait', 'Нет данных проверки', 'Результат появится после проверки.' );
    if ( verdict.verdict === 'pending' ) {
        return vdBlock( 'wait', 'На проверке у преподавателя', 'Оценка появится после проверки — обычно в течение пары дней.' );
    }
    if ( verdict.verdict === 'correct' ) {
        const score = result?.score ?? verdict.score ?? 0;
        return vdBlock( verdict.corrected ? 'warn' : 'ok', `Верно · +${ workPointsLabel( score ) }`,
            verdict.corrected ? 'Сначала проверка показала ошибку, потом ответ исправлен.' : '', solution );
    }
    const isChoice = Array.isArray( verdict.correctOptionIds );
    return vdBlock( 'no', 'Неверно · 0 баллов', solution || isChoice ? '' : 'Правильный ответ станет доступен после разбора с преподавателем.', solution );
}

/** Решение уже отфильтровано и очищено сервером; ответы и HTML виджета сюда не передаются. */
function vdBlock( kind, title, body, solutionHtml = '' ) {
    const icon = 'ok' === kind || 'warn' === kind ? ICO.check( 13 ) : ( 'no' === kind ? ICO.cross( 11 ) : ICO.clock( 13 ) );
    return `<div class="vd vd-${ kind }"><span class="vi">${ icon }</span><div>` +
        `<b>${ esc( title ) }</b>` + ( body ? `<span>${ esc( body ) }</span>` : '' ) +
        ( solutionHtml ? `<div class="vd-solution wpc">${ solutionHtml }</div>` : '' ) +
        '</div></div>';
}
