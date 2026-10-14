/**
 * Фаза момента начала экзамена — зеркало `Inc\Enums\Exam\ExamStartPhase`.
 * Чистая функция: «сейчас» приходит параметрами (секунды до начала и до конца окна).
 */

/** Секунд до начала, с которых экзамен «скоро начнётся» (как `ExamStartPhase::SOON_SECONDS`). */
export const SOON_SECONDS = 300;

/**
 * @param {number} secondsToStart Секунд до начала сеанса.
 * @param {number} secondsToEnd   Секунд до конца окна сеанса.
 * @returns {'far'|'soon'|'running'|'ended'}
 */
export function examStartPhase(secondsToStart, secondsToEnd) {
    if (secondsToEnd <= 0) { return 'ended'; }
    if (secondsToStart <= 0) { return 'running'; }
    return secondsToStart <= SOON_SECONDS ? 'soon' : 'far';
}

/**
 * Через сколько миллисекунд фаза сменится в следующий раз; null — смен больше не будет.
 *
 * @param {number} secondsToStart
 * @param {number} secondsToEnd
 * @returns {number|null}
 */
export function msToNextPhase(secondsToStart, secondsToEnd) {
    if (secondsToEnd <= 0) { return null; }
    if (secondsToStart > SOON_SECONDS) { return (secondsToStart - SOON_SECONDS) * 1000; }
    if (secondsToStart > 0) { return secondsToStart * 1000; }
    return secondsToEnd * 1000;
}
