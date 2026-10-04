/* ══════════════════════════════════════════════════════════════════════
   Раздел «Результаты» (этап 8). Пока заглушка.
   ══════════════════════════════════════════════════════════════════════ */

import { emptyState } from '../utils.js';
import { icoCalendarBoard } from '../../common/icons.js';

export function renderExamResults(root) {
    root.innerHTML = emptyState('prof-ktp', icoCalendarBoard(34), 'Результаты', 'Раздел в разработке.');
}
