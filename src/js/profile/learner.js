/* Экраны учащегося (ученик/родитель) — заглушки скелета.
   Реальные данные подключаются в Эпике 7 (см. .docs/Tasks.md). */

import { esc } from './utils.js';

function stub(root, title, source) {
    const ro = window.fsProfile?.readOnly
        ? '<span class="prof-chip">Только просмотр</span>' : '';
    root.innerHTML = `
    <div class="prof-dash">
        <div class="prof-dash-hello">
            <h1>${esc(title)} ${ro}</h1>
            <p>Раздел в разработке — данные появятся после подключения к ${esc(source)}.</p>
        </div>
        <div class="prof-card">
            <div class="prof-card-head"><h3>${esc(title)}</h3></div>
            <div style="padding:18px;color:var(--muted)">Скоро здесь будут ваши данные.</div>
        </div>
    </div>`;
}

export function renderLearnerHome(root)       { stub(root, 'Главная', 'расписанию и дедлайнам'); }
export function renderLearnerLessons(root)    { stub(root, 'Мои курсы', 'плееру уроков'); }
export function renderLearnerGrades(root)     { stub(root, 'Мои оценки', 'дневнику оценок'); }
export function renderLearnerAttendance(root) { stub(root, 'Посещаемость', 'журналу посещаемости'); }
