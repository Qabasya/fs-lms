/* ══════════════════════════════════════════════════════════════════════
   Экран «Проведение экзамена» (этап 8). Пока заглушка: функция открытия по сеансу
   только запоминает ID сеанса, на который ведёт клик по экзамену на «Главной» (4.7).
   ══════════════════════════════════════════════════════════════════════ */

import { emptyState } from '../utils.js';
import { icoCalendarBoard } from '../../common/icons.js';

let requestedSessionId = 0;

export function renderExamConduct(root) {
    root.innerHTML = emptyState('prof-ktp', icoCalendarBoard(34), 'Проведение экзамена', 'Раздел в разработке.');
}

/** Сеанс, который попросили открыть (с «Главной»); этап 8 прочитает его при отрисовке экрана. */
export function openExamConductFor(sessionId) {
    requestedSessionId = Number(sessionId) || 0;
}

export function requestedConductSession() {
    return requestedSessionId;
}
