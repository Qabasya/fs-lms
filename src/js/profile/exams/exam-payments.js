/* ══════════════════════════════════════════════════════════════════════
   Раздел «Оплаты гостей» (этап 11b). Пока заглушка.
   ══════════════════════════════════════════════════════════════════════ */

import { emptyState } from '../utils.js';
import { icoCalendarBoard } from '../../common/icons.js';

export function renderExamPayments(root) {
    root.innerHTML = emptyState('prof-ktp', icoCalendarBoard(34), 'Оплаты гостей', 'Раздел в разработке.');
}
