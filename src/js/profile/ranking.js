import { createApi } from './api.js';
import { esc } from './utils.js';
import { subjectPickerBtnHtml, openSubjectPicker, optionPickerBtnHtml, openOptionPicker } from './picker.js';
import { readNav, saveNav } from './nav-state.js';

/** Staff subject ranking. Server assigns places before applying the group filter. */
export function renderRanking(root) {
    const config = window.fsProfile?.ranking;
    const subjects = config?.subjects || [];
    if (!subjects.length) {
        root.innerHTML = '<div class="prof-ranking"><h1>Рейтинг</h1><p>Нет доступных направлений. За вами пока не закреплены группы.</p></div>';
        return;
    }
    const api = createApi(config);
    const nav = readNav('ranking');
    let subject = subjects.find(item => item.key === nav.s) || subjects[0];
    let groupId = Number(nav.g) || 0;
    let groups = [];
    let data = null;
    let requestId = 0;
    const shell = document.createElement('section');
    shell.className = 'prof-ranking';
    root.replaceChildren(shell);

    function render(message = '') {
        const selectedGroup = groups.find(group => Number(group.id) === groupId);
        const rows = data?.rows || [];
        const content = message ? `<p class="ranking-message" role="status">${esc(message)}</p>`
            : data?.summer ? '<p class="ranking-message">Летом рейтинг не показывается. Новый триместр начнётся в сентябре.</p>'
                : `<div class="prof-journal-wrap"><div class="j-scroll"><table class="jgrid ranking-table">
                    <caption class="ranking-caption">${esc(data?.period || '')}</caption>
                    <thead><tr><th scope="col">Место</th><th scope="col">ФИО</th><th scope="col">Группа</th><th scope="col">Баллы</th></tr></thead>
                    <tbody>${rows.length ? rows.map(row => `<tr><td>${esc(row.rank)}</td><td>${esc(row.name || '—')}</td><td>${esc((row.groups || []).map(group => group.name).join(', '))}</td><td>${Number(row.score).toLocaleString('ru-RU', { maximumFractionDigits: 2 })}</td></tr>`).join('') : '<tr><td colspan="4">Нет активных учеников в выбранной группе.</td></tr>'}</tbody>
                </table></div></div>`;
        shell.innerHTML = `<header class="ranking-head"><h1>Рейтинг</h1><div class="prof-ktp-pickers">
            <div class="prof-ktp-pick"><span class="kp-label">Направление</span>${subjectPickerBtnHtml(subject, 'rankingSubject')}</div>
            <div class="prof-ktp-pick"><span class="kp-label">Группа</span>${optionPickerBtnHtml(selectedGroup?.name || 'Все группы', 'rankingGroup', 'Фильтр по группе')}</div>
            </div></header>${content}`;
        shell.querySelector('#rankingSubject')?.addEventListener('click', event => {
            openSubjectPicker(event.currentTarget, subjects, subject.key, key => {
                subject = subjects.find(item => item.key === key);
                groupId = 0;
                groups = [];
                load();
            });
        });
        shell.querySelector('#rankingGroup')?.addEventListener('click', event => {
            openOptionPicker(event.currentTarget, [{ value: '0', label: 'Все группы' }, ...groups.map(group => ({ value: String(group.id), label: group.name }))], String(groupId), value => {
                groupId = Number(value);
                load();
            });
        });
    }

    async function load() {
        const request = ++requestId;
        saveNav('ranking', { s: subject.key, g: groupId || '' });
        render('Загрузка рейтинга…');
        try {
            const response = await api('getRanking', { subject_key: subject.key, group_id: groupId });
            if (request !== requestId || !root.contains(shell)) { return; }
            data = response;
            groups = response.groups || [];
            render();
        } catch (error) {
            if (request === requestId && root.contains(shell)) { render(error.message || 'Не удалось загрузить рейтинг.'); }
        }
    }
    load();
}
