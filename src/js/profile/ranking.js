import { createApi } from './api.js';
import { esc, profileUrl, openGradePopModal, closeGradePop } from './utils.js';
import { subjectPickerBtnHtml, openSubjectPicker, groupPickerBtnHtml, openGroupPicker, optionPickerBtnHtml, openOptionPicker } from './picker.js';
import { readNav, saveNav } from './nav-state.js';
import { icoArrowUp, icoArrowDown } from '../common/icons.js';

const scoreLabels = { practice: 'Практика', independent: 'Самостоятельная работа', homework: 'Домашняя работа', survey: 'Опрос', assessment: 'Экзамены и контрольные', attendance_bonus: 'Серии посещений' };
const scoreNumber = value => Number(value).toLocaleString('ru-RU', { maximumFractionDigits: 2 });

export function scoreBreakdownHtml(row) {
    const equation = (count, points, value, maximum, unknown = false, scoreUnknown = false, prefix = '') => `<span class="ranking-score-equation">${prefix ? `<span class="ranking-score-prefix">${prefix}</span>` : ''}<span class="ranking-score-number">${scoreNumber(count)}</span><span>×</span><span class="ranking-score-number">${scoreNumber(points)}</span><span>=</span><strong class="ranking-score-number">${scoreNumber(value)}</strong>${unknown ? '<span class="ranking-score-note">из неизвестного максимума</span>' : maximum == null ? '<span></span><span></span>' : `<span>из</span><strong class="ranking-score-number">${scoreNumber(maximum)}</strong>`}${scoreUnknown ? '<span class="ranking-score-note">(неполный результат)</span>' : ''}</span>`;
    const result = (value, maximum, unknown = false, scoreUnknown = false) => `${scoreNumber(value)}${scoreUnknown ? ' (неполный результат)' : ''}${unknown ? ' из неизвестного максимума' : maximum == null ? '' : ` из ${scoreNumber(maximum)}`}`;
    const parts = (row.breakdown || []).filter(part => part.weight > 0 && (part.amount > 0 || part.max_amount > 0 || part.max_unknown)).map(part => {
        const label = scoreLabels[part.source] || part.source;
        const detail = part.source === 'attendance_bonus' ? ` (длина серии: ${part.streak_length})` : '';
        if (part.source === 'practice' && part.task_groups?.length) {
            const groups = part.task_groups.filter(group => (group.count > 0 || group.max_count > 0) && group.points > 0).map(group => equation(group.count, group.points, group.count * group.points, group.max_count == null ? null : group.max_count * group.points)).join('');
            const total = part.weight === 1 && !part.max_unknown
                ? ''
                : equation(part.amount, part.weight, part.amount * part.weight, part.max_amount == null ? null : part.max_amount * part.weight, part.max_unknown, part.score_unknown, 'С коэффициентом:');
            return `<li><span>${esc(label)}</span><span class="ranking-score-values">${groups}${total}</span></li>`;
        }
        return `<li><span>${esc(label + detail)}</span><span class="ranking-score-values">${equation(part.amount, part.weight, part.amount * part.weight, part.max_amount == null ? null : part.max_amount * part.weight, part.max_unknown, part.score_unknown)}</span></li>`;
    });
    return `<div class="gp-title">Баллы: ${esc(row.name || 'Ученик')}</div><ul class="ranking-score-list">${parts.join('')}</ul><p class="ranking-score-total">Итого: <strong>${result(row.score, row.max_score, row.max_unknown, row.score_unknown)}</strong></p>`;
}

function rankTrendHtml(trend) {
    if (!trend || !['up', 'down'].includes(trend.direction)) {
        return '<span class="ranking-trend-slot" aria-hidden="true"></span>';
    }
    const label = trend.direction === 'up' ? 'Место поднялось' : 'Место опустилось';
    const icon = trend.direction === 'up' ? icoArrowUp(16) : icoArrowDown(16);
    return `<span class="ranking-trend-slot"><span class="ranking-trend ranking-trend--${trend.direction}" tabindex="0" role="img" aria-label="${esc(label)}" title="${esc(label)}">${icon}</span></span>`;
}

/**
 * Панель выбора периода, направления и группы — тот же вид, что на экране «Сводка по ученику».
 * Направление не предлагается, когда доступно только одно (у ученика — своё): выбирать нечего.
 */
export function rankingPickersHtml(subject, groups, groupId, periods, periodFrom, showSubject = true, viewerGroupId = 0) {
    const selected = groups.find(group => Number(group.id) === groupId);
    const selectedLabel = selected && groupId === viewerGroupId ? { ...selected, name: 'Моя группа' } : selected;
    const period = periods.find(item => item.from === periodFrom);
    return `<div class="sum-pickers">
        <div class="prof-ktp-pick"><span class="kp-label">Период</span>${optionPickerBtnHtml(period?.label || 'Текущий', 'rankingPeriod')}</div>
        ${showSubject ? `<div class="prof-ktp-pick"><span class="kp-label">Направление</span>${subjectPickerBtnHtml(subject, 'rankingSubject')}</div>` : ''}
        <div class="prof-ktp-pick"><span class="kp-label">Группа</span>${groupPickerBtnHtml(selectedLabel || { id: 0, name: 'Все группы', subject: '' }, 'rankingGroup')}</div>
    </div>`;
}

/**
 * Таблица рейтинга — классы журнала (`jgrid`): липкая шапка, подсветка строки,
 * место и ученик — `cell-idx`/`cell-name` журнала, группа и баллы — по центру, как данные журнала.
 * Имя ученика кликабельно, как в журнале: ведёт в «Сводку по ученику» (openSummary).
 */
export function rankingTableHtml(data, canOpenSummary = false, learner = false) {
    const rows = data?.rows || [];
    const nameCell = (row) => {
        const inner = `<span class="cn-name">${esc(row.name || '—')}</span>`;
        const gid = row.groups?.[0]?.id;
        return canOpenSummary && gid
            ? `<button type="button" class="cn-wrap cn-link" data-summary-pid="${row.person_id}" data-summary-gid="${gid}" data-href="${esc(profileUrl({ screen: 'summary', gid, pid: row.person_id }))}" title="Открыть сводку по ученику">${inner}</button>`
            : `<div class="cn-wrap">${inner}</div>`;
    };
    return `<div class="prof-journal-wrap"><div class="j-scroll"><table class="jgrid ranking-table${learner ? ' ranking-table--learner' : ''}">
        <thead><tr>
            <th class="col-name"><div class="hd-name">Ученик</div></th>
            <th class="col-mid"><div class="hd-name">Группа</div></th>
            <th class="col-mid"><div class="hd-name">Баллы</div></th>
            <th class="col-rank"><div class="hd-name">Место в рейтинге</div></th>
        </tr></thead>
        <tbody>${rows.length ? rows.map(row => `<tr${learner && Number(data.viewer_person_id) > 0 && Number(row.person_id) === Number(data.viewer_person_id) ? ` class="ranking-self"${Array.isArray(row.breakdown) ? ` data-score-row="${Number(row.person_id)}" tabindex="0" role="button" aria-label="Расчёт моих баллов"` : ''}` : ''}>
            <td class="cell-name">${nameCell(row)}</td>
            <td class="cell-c">${esc((row.groups || []).map(group => group.name).join(', '))}</td>
            <td class="cell-c${!learner && Array.isArray(row.breakdown) ? ' ranking-score-cell' : ''}">${!learner && Array.isArray(row.breakdown) ? `<button type="button" class="ranking-score" data-score-pid="${Number(row.person_id)}" aria-label="Расчёт баллов: ${esc(row.name)}">${scoreNumber(row.score)}${row.score_unknown ? ' (неполный результат)' : ''}</button>` : `${scoreNumber(row.score)}${row.score_unknown ? ' (неполный результат)' : ''}`}</td>
            <td class="cell-rank"><span class="ranking-rank"><span class="ranking-rank-value">${row.rank == null ? '—' : esc(String(row.rank))}</span>${rankTrendHtml(row.trend)}</span></td>
        </tr>`).join('') : '<tr><td class="cell-empty" colspan="4">Нет активных учеников в выбранной группе.</td></tr>'}</tbody>
    </table></div></div>`;
}

/**
 * Рейтинг направления: у сотрудника — экран `ranking`, у ученика — `student-ranking`
 * (своё состояние в адресе). Места — общие по направлению; при выбранной группе сервер
 * пересчитывает их плотно внутри неё.
 */
export function renderRanking(root, handlers = {}, screen = 'ranking') {
    const config = window.fsProfile?.ranking;
    const subjects = config?.subjects || [];
    if (!subjects.length) {
        root.innerHTML = '<div class="prof-ranking"><h1>Рейтинг</h1><p>Нет доступных направлений. За вами пока не закреплены группы.</p></div>';
        return;
    }
    // Выбор направления имеет смысл, только когда их несколько; у ученика список и так
    // ограничен его группами — при одном направлении пикер не рисуем.
    const showSubject = subjects.length > 1;
    const api = createApi(config);
    const nav = readNav(screen);
    let subject = subjects.find(item => item.key === nav.s) || subjects[0];
    let groupId = Number(nav.g) || 0;
    // Периоды приходят с конфигом экрана; '' — текущий период. Чужая/устаревшая метка из адреса гасится.
    let periods = Array.isArray(config?.periods) ? config.periods : [];
    let periodFrom = periods.some(item => item.from === nav.p) ? nav.p : '';
    let groups = [];
    let viewerGroupId = 0;
    let data = null;
    let requestId = 0;
    const shell = document.createElement('section');
    shell.className = 'prof-ranking';
    root.replaceChildren(shell);

    function render(message = '') {
        const content = message ? `<p class="ranking-message" role="status">${esc(message)}</p>` : rankingTableHtml(data, 'function' === typeof handlers.openSummary, screen === 'student-ranking');
        shell.innerHTML = `<div class="sum-head">${rankingPickersHtml(subject, groups, groupId, periods, periodFrom, showSubject, viewerGroupId)}</div>${content}`;
        shell.querySelectorAll('[data-score-pid], [data-score-row]').forEach(button => {
            const open = () => {
                const row = data.rows.find(item => Number(item.person_id) === Number(button.dataset.scorePid || button.dataset.scoreRow));
                if (!row) return;
                const pop = document.getElementById('profGradePop');
                if (!pop) return;
                pop.innerHTML = `<div class="gp-form ranking-score-form">${scoreBreakdownHtml(row)}<div class="gp-row"><button type="button" class="prof-btn prof-btn-sm prof-btn-primary" data-score-close>Закрыть</button></div></div>`;
                pop.querySelector('[data-score-close]').addEventListener('click', () => { closeGradePop(); button.focus(); });
                openGradePopModal(pop);
            };
            button.addEventListener('click', open);
            if (button.hasAttribute('data-score-row')) button.addEventListener('keydown', event => {
                if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); open(); }
            });
        });
        shell.querySelectorAll('[data-summary-pid]').forEach(btn => btn.addEventListener('click', () => {
            handlers.openSummary(+btn.dataset.summaryGid, +btn.dataset.summaryPid);
        }));
        shell.querySelector('#rankingPeriod')?.addEventListener('click', event => {
            openOptionPicker(event.currentTarget, periods.map(item => ({ value: item.from, label: item.label })), periodFrom, value => {
                periodFrom = value;
                load();
            });
        });
        shell.querySelector('#rankingSubject')?.addEventListener('click', event => {
            openSubjectPicker(event.currentTarget, subjects, subject.key, key => {
                subject = subjects.find(item => item.key === key);
                groupId = 0;
                groups = [];
                viewerGroupId = 0;
                load();
            });
        });
        shell.querySelector('#rankingGroup')?.addEventListener('click', event => {
            const ownGroup = groups.find(group => Number(group.id) === viewerGroupId);
            const choices = ownGroup ? groups.filter(group => Number(group.id) !== viewerGroupId) : groups;
            const extra = [{ v: '0', label: 'Все группы', chip: '∀', first: true }];
            if (ownGroup) extra.push({ v: String(viewerGroupId), label: 'Моя группа', first: true });
            openGroupPicker(event.currentTarget, choices, groupId, id => {
                groupId = id;
                load();
            }, extra);
        });
    }

    async function load() {
        const request = ++requestId;
        saveNav(screen, { s: showSubject ? subject.key : '', g: groupId || '', p: periodFrom });
        render('Загрузка рейтинга…');
        try {
            const response = await api('getRanking', { subject_key: subject.key, group_id: groupId, period_from: periodFrom });
            if (request !== requestId || !root.contains(shell)) { return; }
            data = response;
            groups = response.groups || [];
            viewerGroupId = Number(response.viewer_group_id) || 0;
            periods = response.periods || periods;
            render();
        } catch (error) {
            if (request === requestId && root.contains(shell)) { render(error.message || 'Не удалось загрузить рейтинг.'); }
        }
    }
    load();
}
