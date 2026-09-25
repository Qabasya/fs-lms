/* Поиск ученика по ФИО с подсказками (Tasks.md, п. 2) — «Сводка по ученику».
   Список совпадений раскрывается с первой буквы и обновляется на каждый ввод:
   совпадение — начало любого слова ФИО (или всего ФИО), без учёта регистра и
   разницы «е»/«ё». Сначала — по фамилии, затем по имени, внутри — по алфавиту.
   Фильтр клиентский: ученики уже загружены пикером. */

import { esc, initials, avaColor, plural } from './utils.js';
import { icoSearch } from '../common/icons.js';

const norm = (s) => String(s || '').toLowerCase().replace(/ё/g, 'е').trim();

/** Разметка поля поиска; список подсказок наполняет wireStudentSearch. */
export function studentSearchHtml(id) {
    return `<div class="stu-search">
        <span class="stu-search-ico">${icoSearch(15)}</span>
        <input type="search" class="stu-search-input" id="${id}" placeholder="Поиск по ФИО" autocomplete="off"
            role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="${id}List">
        <div class="stu-search-list" id="${id}List" role="listbox" hidden></div>
    </div>`;
}

/**
 * Ученики, подходящие под запрос, в порядке показа.
 *
 * @param {Array<{person_id:number, name:string}>} students
 * @param {string} query
 */
export function matchStudents(students, query) {
    const q = norm(query);
    if (!q) { return []; }

    const found = [];
    students.forEach(s => {
        const name = norm(s.name);
        const words = name.split(/\s+/);
        let rank = -1;
        if (name.startsWith(q) || words[0].startsWith(q)) {
            rank = 0;
        } else if (words.slice(1).some(w => w.startsWith(q))) {
            rank = 1;
        }
        if (rank >= 0) { found.push({ s, rank }); }
    });

    found.sort((a, b) => (a.rank - b.rank) || a.s.name.localeCompare(b.s.name, 'ru'));
    return found.map(f => f.s);
}

/**
 * Навешивает поведение на поле из studentSearchHtml().
 *
 * @param {HTMLInputElement} input
 * @param {Array<{person_id:number, name:string, groups:number[]}>} students
 * @param {(groupId:number) => string} groupName Подпись группы в строке подсказки
 * @param {(personId:number) => void} onPick
 */
export function wireStudentSearch(input, students, groupName, onPick) {
    const list = document.getElementById(`${input.id}List`);
    if (!list) { return; }

    let shown = [];
    let active = -1;

    const close = () => {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        active = -1;
    };

    const highlight = (i) => {
        active = i;
        list.querySelectorAll('.stu-opt').forEach((el, idx) => {
            const on = idx === active;
            el.classList.toggle('is-active', on);
            el.setAttribute('aria-selected', String(on));
            if (on) { el.scrollIntoView({ block: 'nearest' }); }
        });
    };

    const pick = (i) => {
        const s = shown[i];
        if (!s) { return; }
        input.value = '';
        close();
        onPick(s.person_id);
    };

    const meta = (s) => (1 === s.groups.length
        ? groupName(s.groups[0])
        : `${s.groups.length} ${plural(s.groups.length, 'группа', 'группы', 'групп')}`);

    const update = () => {
        if (!norm(input.value)) { close(); return; }

        shown = matchStudents(students, input.value);
        list.innerHTML = shown.length
            ? shown.map((s, i) => `
                <div class="stu-opt" role="option" aria-selected="false" data-i="${i}">
                    <span class="stu-opt-ava" style="background:${avaColor(students, s.person_id)}">${initials(s.name)}</span>
                    <span class="stu-opt-name">${esc(s.name)}</span>
                    <span class="stu-opt-meta">${esc(meta(s))}</span>
                </div>`).join('')
            : '<div class="stu-empty">Никого не нашлось</div>';
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        highlight(shown.length ? 0 : -1);
    };

    input.addEventListener('input', update);
    input.addEventListener('focus', update);
    input.addEventListener('blur', close);
    input.addEventListener('keydown', (e) => {
        if ('Escape' === e.key) { close(); return; }
        if (list.hidden || !shown.length) { return; }
        if ('ArrowDown' === e.key) {
            e.preventDefault();
            highlight((active + 1) % shown.length);
        } else if ('ArrowUp' === e.key) {
            e.preventDefault();
            highlight((active - 1 + shown.length) % shown.length);
        } else if ('Enter' === e.key) {
            e.preventDefault();
            pick(active);
        }
    });

    // mousedown, а не click: выбор должен случиться раньше blur поля.
    list.addEventListener('mousedown', (e) => {
        const opt = e.target.closest('.stu-opt');
        if (!opt) { return; }
        e.preventDefault();
        pick(+opt.dataset.i);
    });
}
