/* ══════════════════════════════════════════════════════════════════════
   Раздел «Статистика» (этап 10). Пока заглушка.
   ══════════════════════════════════════════════════════════════════════ */

import { emptyState } from '../utils.js';
import { icoCalendarBoard } from '../../common/icons.js';

export function renderExamStats(root) {
    root.innerHTML = emptyState('prof-ktp', icoCalendarBoard(34), 'Статистика', 'Раздел в разработке.');
}
