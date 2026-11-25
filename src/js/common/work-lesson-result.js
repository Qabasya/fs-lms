import { fmtDateTime } from './utils.js';

/** Число задач за одну встречу и итог темы на тот момент. */
export function workLessonResultText(result, withDate = false) {
    const session = result.new_solved == null ? 'Нет разбивки по датам' : `За занятие ${result.new_solved}/${result.total}`;
    const total = result.solved == null ? '' : ` · всего ${result.solved}/${result.total}`;
    return (withDate ? `${fmtDateTime(result.at)} · ` : '') + session + total;
}
