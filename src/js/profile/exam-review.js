/* ══════════════════════════════════════════════════════════════════════
   Экран разбора экзамена — вердикты задач, баллы по блокам, итоговый результат.
   Расширяет work-review.js для режимов manage (учитель) и read_only (ученик/гость).
   ══════════════════════════════════════════════════════════════════════ */

import { esc, fmtNum } from './utils.js';
import { icoCheck, icoCross, icoAlert, icoClock, icoChevronDown } from '../common/icons.js';

/**
 * Вердикты задач (7.2 -> 7.4).
 */
const VERDICT_ICO = {
    correct: () => icoCheck( 16, '#2f9e44' ),      // Зелёная галочка
    incorrect: () => icoCross( 16, '#d92e2e' ),    // Красный крест
    partial: () => icoAlert( 16, '#f08c00' ),      // Оранжевое предупреждение
    pending: () => icoClock( 16, '#999999' ),      // Серые часы
    unanswered: () => icoAlert( 16, '#999999' ),   // Серое предупреждение
};

const VERDICT_LABEL = {
    correct: 'Верно',
    incorrect: 'Неверно',
    partial: 'Частично',
    pending: 'На проверке',
    unanswered: 'Не отвечено',
};

/**
 * Рендер задачи экзамена с вердиктом и баллами (7.4.2).
 *
 * @param {Object} task Объект задачи из detail.tasks
 * @returns {string} HTML строка задачи
 */
export function examTaskHtml( task ) {
    const verdict = task.verdict || 'pending';
    const ico = VERDICT_ICO[ verdict ] ? VERDICT_ICO[ verdict ]() : '';
    const label = VERDICT_LABEL[ verdict ] || '?';
    const score = task.score !== undefined ? fmtNum( task.score ) : '—';
    const maxScore = task.max_score !== undefined ? fmtNum( task.max_score ) : '—';

    return `<div class="wr-task" data-task-id="${task.task_id || ''}">
        <div class="wr-task-head">
            <span class="wr-task-num">Задание ${esc(task.number || '?')}</span>
            <span class="wr-task-verdict" title="${esc(label)}">
                ${ico}
                <span>${esc(label)}</span>
            </span>
        </div>
        <div class="wr-task-body">
            ${task.answer_text ? `<div class="wr-answer"><strong>Ответ:</strong> ${esc(task.answer_text)}</div>` : '<div class="wr-answer-empty">Нет ответа</div>'}
            <div class="wr-score">
                <span class="wr-label">Баллы:</span>
                <span class="wr-value">${score}/${maxScore}</span>
            </div>
            ${task.grader_note ? `<div class="wr-note"><strong>Замечание:</strong> ${esc(task.grader_note)}</div>` : ''}
        </div>
    </div>`;
}

/**
 * Рендер блока заданий (7.4.3).
 *
 * @param {Object} unit Объект блока из detail.units
 * @param {Array} tasks Задачи блока
 * @returns {string} HTML строка блока
 */
export function examUnitHtml( unit, tasks = [] ) {
    const verdict = unit.verdict || 'pending';
    const ico = VERDICT_ICO[ verdict ] ? VERDICT_ICO[ verdict ]() : '';

    return `<div class="wr-unit">
        <div class="wr-unit-head" data-unit-key="${esc(unit.unit_key || '')}">
            <div class="wr-unit-title">
                ${ico}
                <span>Блок ${esc(unit.number || '?')}</span>
            </div>
            <div class="wr-unit-score">
                ${unit.score || 0}/${unit.max || 0}
            </div>
        </div>
        <div class="wr-unit-tasks">
            ${tasks.map( t => examTaskHtml( t ) ).join( '' )}
        </div>
    </div>`;
}

/**
 * Шкала результатов (7.4.4).
 * Отображает прогресс первичного балла и вторичного (для ЕГЭ) / отметки (для ОГЭ).
 *
 * @param {Object} detail Детали попытки
 * @returns {string} HTML строка шкалы
 */
export function examScaleHtml( detail ) {
    if ( ! detail.score_summary ) {
        return '';
    }

    const s = detail.score_summary;
    const primary = s.primary || 0;
    const primaryMax = s.primary_max || 100;
    const pct = Math.min( 100, Math.round( ( primary / primaryMax ) * 100 ) );

    let secondary = '';
    if ( 'ege' === s.direction && s.secondary !== undefined && s.secondary !== null ) {
        const sec = s.secondary || 0;
        const secMax = s.secondary_max || 100;
        const secPct = Math.min( 100, Math.round( ( sec / secMax ) * 100 ) );
        secondary = `<div class="wr-scale-row">
            <div class="wr-scale-label">Вторичный балл</div>
            <div class="wr-scale-bar">
                <div class="wr-scale-progress" style="width: ${secPct}%"></div>
            </div>
            <div class="wr-scale-value">${sec}/${secMax}</div>
        </div>`;
    }

    if ( 'oge' === s.direction && s.grade !== undefined && s.grade !== null ) {
        secondary = `<div class="wr-scale-row">
            <div class="wr-scale-label">Оценка</div>
            <div class="wr-scale-value">
                <span class="wr-grade">${s.grade}/5</span>
            </div>
        </div>`;
    }

    return `<div class="wr-scale">
        <h3>Результат</h3>
        <div class="wr-scale-row">
            <div class="wr-scale-label">Первичный балл</div>
            <div class="wr-scale-bar">
                <div class="wr-scale-progress" style="width: ${pct}%"></div>
            </div>
            <div class="wr-scale-value">${primary}/${primaryMax}</div>
        </div>
        ${secondary}
        ${s.pending ? `<div class="wr-note-pending">⏳ Результаты ещё не утверждены</div>` : ''}
    </div>`;
}

/**
 * Экран разбора экзамена (7.4.1).
 * Рендерит детали с группировкой по блокам, вердиктами и итоговым результатом.
 *
 * @param {HTMLElement} root Элемент для рендера
 * @param {Object} detail Детали из ExamReviewProjection
 */
export function renderExamReview( root, detail ) {
    if ( ! detail || ! root ) {
        return;
    }

    // Если результаты не раскрыты (read_only режим)
    if ( ! detail.revealed ) {
        root.innerHTML = `<div class="wr-not-revealed">
            <p>📋 Результаты ещё не доступны</p>
            <p class="wr-note-hint">Статус: ${esc(detail.status || 'неизвестен')}</p>
        </div>`;
        return;
    }

    // Разбить задачи по блокам
    const tasksByUnit = {};
    if ( detail.tasks && Array.isArray( detail.tasks ) ) {
        detail.tasks.forEach( t => {
            const unit = t.unit_key || '__default__';
            if ( ! tasksByUnit[ unit ] ) {
                tasksByUnit[ unit ] = [];
            }
            tasksByUnit[ unit ].push( t );
        } );
    }

    // Построить HTML
    let html = examScaleHtml( detail );

    if ( detail.units && Array.isArray( detail.units ) ) {
        html += '<div class="wr-units">';
        detail.units.forEach( unit => {
            const tasks = tasksByUnit[ unit.unit_key ] || [];
            html += examUnitHtml( unit, tasks );
        } );
        html += '</div>';
    }

    root.innerHTML = html;
}
