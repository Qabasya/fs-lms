import assert from 'node:assert/strict';
import test from 'node:test';

import { installCtxMenu } from './ctx-menu-stub.mjs';

/* Экран «Работы»: один список порциями, фильтры считает сервер, работа открывается на учеников.
   DOM — минимальная подделка: элементы собираются из разметки, которую экран записал в root. */

class FakeElement {
    constructor(dataset = {}) {
        this.dataset = dataset;
        this.listeners = {};
        this.value = '';
    }

    addEventListener(type, callback) {
        this.listeners[type] = callback;
    }

    fire(type) {
        this.listeners[type]?.({ currentTarget: this, target: this, stopPropagation() {} });
    }

    /** Якорь меню: позиционированию нужен только прямоугольник. */
    getBoundingClientRect() {
        return { left: 10, right: 210, top: 10, bottom: 40, width: 200 };
    }
}

class FakeRoot {
    constructor() {
        this.html = '';
        this.build();
    }

    set innerHTML(html) {
        this.html = html;
        this.build();
    }

    get innerHTML() {
        return this.html;
    }

    build() {
        const html = this.html;
        this.rows = [...html.matchAll(/data-key="([^"]+)"/g)].map((m) => new FakeElement({ key: m[1] }));
        this.statuses = [...html.matchAll(/data-status="([^"]*)"/g)].map((m) => new FakeElement({ status: m[1] }));
        this.more = /data-more/.test(html) ? new FakeElement() : null;
        this.refresh = /data-refresh/.test(html) ? new FakeElement() : null;
        this.back = /class="wk-back"/.test(html) ? new FakeElement() : null;
        this.typeBtn = /id="wkTypeBtn"/.test(html) ? new FakeElement() : null;
        this.groupBtn = /id="wkGroupBtn"/.test(html) ? new FakeElement() : null;
        const root = this;
        this.area = /id="wkListArea"/.test(html)
            ? { set innerHTML(areaHtml) { root.replaceArea(areaHtml); } }
            : null;
    }

    /** Подгрузка заменяет только область списка, остальная разметка остаётся. */
    replaceArea(areaHtml) {
        this.html = this.html.replace(/(<div id="wkListArea">)[\s\S]*(<\/div>\s*<\/div>\s*<\/div>\s*)$/, (_, open, close) => open + areaHtml + close);
        this.build();
    }

    querySelectorAll(selector) {
        if (selector === '[data-status]') { return this.statuses; }
        if (selector === '.wk-row[data-key]') { return this.rows; }
        return [];
    }

    querySelector(selector) {
        if (selector === '[data-refresh]') { return this.refresh; }
        if (selector === '[data-more]') { return this.more; }
        if (selector === '.wk-back') { return this.back; }
        if (selector === '#wkTypeBtn') { return this.typeBtn; }
        if (selector === '#wkGroupBtn') { return this.groupBtn; }
        if (selector === '#wkListArea') { return this.area; }
        return null;
    }
}

const tick = () => new Promise((resolve) => setImmediate(resolve));

const item = (lessonId, workId, extra = {}) => ({
    group_lesson_id: lessonId, work_id: workId, group_id: 1, group_name: 'Группа А', date: '2026-10-19', topic: 'Тема',
    title: `Работа ${workId}`, label: 'Практика', badge: 'ПР', total: 2, submitted: 1, missing: 1, pending: 1, ...extra,
});

/** Подготавливает окружение и запускает экран; `handler(action, params)` отвечает вместо сервера. */
async function setup(handler, { groups = [] } = {}) {
    const calls = [];
    globalThis.window = {
        fsProfile: {
            works: { nonce: 'nonce', actions: { getPassedWorks: 'passed', getPassedStudents: 'students', creditWork: 'credit', uncreditWork: 'uncredit' } },
            groups,
        },
    };
    const menu = installCtxMenu();
    const { renderWorks, applyWorksStatus } = await import('../../src/js/profile/works.js');
    const { FS_LMS_API, clearApiCache } = await import('../../src/js/profile/api.js');
    clearApiCache();
    window.FS_LMS_API = FS_LMS_API;
    window.FS_LMS_API.request = async (action, nonce, params) => {
        calls.push({ action, params });
        return handler(action, params);
    };
    const root = new FakeRoot();
    renderWorks(root);
    await tick();

    return { root, calls, applyWorksStatus, menu };
}

const students = (overrides = []) => [
    { student_person_id: 10, student_name: 'Иванов Иван', submission_id: 91, status: 'pending_review', score: null, max_score: null, credited: false },
    { student_person_id: 11, student_name: 'Петров Пётр', submission_id: 0, status: '', score: null, max_score: null, credited: false },
    ...overrides,
];

test('первая порция грузится с пустыми фильтрами; видно сдачи, ожидающие проверки, и число у фильтра', async () => {
    const { root, calls } = await setup(() => ({ items: [item(5, 3)], next: null, pending_total: 4 }));

    assert.deepEqual(calls[0], { action: 'passed', params: { group_id: 0, type: '', status: '', cursor: '' } });
    assert.match(root.html, /Работа 3/);
    assert.match(root.html, /сдали 1 из 2/);
    assert.match(root.html, /на проверке 1/);
    assert.match(root.html, /data-status="pending"[^>]*>Ждут проверки<span class="wk-badge">4<\/span>/);
    assert.equal(root.more, null, 'продолжения нет — кнопки подгрузки нет');
});

test('следующая порция подгружается по метке и дописывается, повторы не дублируются', async () => {
    const { root, calls } = await setup((action, params) => params.cursor
        ? { items: [item(5, 3), item(4, 7)], next: null, pending_total: null }
        : { items: [item(5, 3)], next: '2026-10-19 10:00:00|5', pending_total: 1 });

    assert.ok(root.more, 'есть продолжение — есть кнопка подгрузки');
    root.more.fire('click');
    await tick();

    assert.equal(calls[1].params.cursor, '2026-10-19 10:00:00|5');
    assert.equal((root.html.match(/data-key=/g) || []).length, 2, 'работа 3 пришла дважды, но показана один раз');
    assert.match(root.html, /Работа 7/);
    assert.equal(root.more, null);
    assert.match(root.html, /data-status="pending"[^>]*>Ждут проверки<span class="wk-badge">1<\/span>/, 'число у фильтра не сбрасывается подгрузкой');
});

test('тип работы выбирается тем же меню, что и группа: «Все типы работ» первым, текущий отмечен', async () => {
    const { root, menu } = await setup(() => ({ items: [item(5, 3)], next: null, pending_total: 0 }));

    assert.match(root.html, /id="wkTypeBtn"[^>]*>\s*<span class="kp-txt">Все типы работ<\/span>/);
    assert.doesNotMatch(root.html, /<select/, 'родного select на экране нет');

    root.typeBtn.fire('click');

    assert.deepEqual(menu.values(), ['', 'practice', 'independent', 'homework']);
    assert.equal(menu.labels()[0], 'Все типы работ');
    assert.deepEqual(menu.active(), ['']);
});

test('в меню групп «Все группы» стоит первым пунктом, выбор группы перезагружает список', async () => {
    const groups = [
        { id: 4, name: 'КОГЭ-1', subject: 'Информатика', subject_key: 'inf' },
        { id: 5, name: 'КОГЭ-2', subject: 'Информатика', subject_key: 'inf' },
    ];
    const { root, calls, menu } = await setup(() => ({ items: [item(5, 3)], next: null, pending_total: 0 }), { groups });

    root.groupBtn.fire('click');

    assert.deepEqual(menu.values(), ['0', '4', '5']);
    assert.equal(menu.labels()[0], 'Все группы');

    menu.pick('5');
    await tick();

    assert.equal(calls[1].params.group_id, 5);
});

test('смена фильтров начинает список с первой порции и сбрасывает показанное', async () => {
    const { root, calls, menu } = await setup((action, params) => 'homework' === params.type
        ? { items: [item(9, 8)], next: null, pending_total: 0 }
        : { items: [item(5, 3)], next: 'c1', pending_total: 2 });

    root.typeBtn.fire('click');
    menu.pick('homework');
    await tick();

    assert.deepEqual(calls[1].params, { group_id: 0, type: 'homework', status: '', cursor: '' });
    assert.match(root.html, /Работа 8/);
    assert.doesNotMatch(root.html, /Работа 3/);

    root.statuses.find((s) => s.dataset.status === 'missing').fire('click');
    await tick();

    assert.deepEqual(calls[2].params, { group_id: 0, type: 'homework', status: 'missing', cursor: '' });
});

test('ответ на прежние фильтры не затирает список под новыми', async () => {
    const held = [];
    const { root, menu } = await setup((action, params) => {
        if ('practice' === params.type) { return new Promise((resolve) => held.push(resolve)); }
        return { items: [item(1, 1)], next: null, pending_total: 0 };
    });

    root.typeBtn.fire('click');
    menu.pick('practice');
    await tick();
    // Вернули «Все типы»: страница без фильтра уже в кеше и показывается сразу, запрос «Практики» ещё висит.
    root.typeBtn.fire('click');
    menu.pick('');
    await tick();
    held[0]({ items: [item(2, 2, { title: 'устаревшая' })], next: null, pending_total: 9 });
    await tick();

    assert.match(root.html, /Работа 1/);
    assert.doesNotMatch(root.html, /устаревшая/);
});

test('устаревший ответ первой порции игнорируется', async () => {
    const held = [];
    const { root } = await setup((action, params) => {
        if ('' === params.type && '' === params.status && !held.length) {
            return new Promise((resolve) => held.push(resolve));
        }
        return { items: [item(2, 2)], next: null, pending_total: 0 };
    });

    root.statuses.find((s) => s.dataset.status === 'pending').fire('click');
    await tick();
    held[0]({ items: [item(1, 1, { title: 'устаревшая' })], next: null, pending_total: 99 });
    await tick();

    assert.match(root.html, /Работа 2/);
    assert.doesNotMatch(root.html, /устаревшая/);
});

test('открытая работа показывает учеников; «Обновить» перечитывает их и держит последнее при сбое', async () => {
    let failStudents = false;
    const { root, calls } = await setup((action) => {
        if ('students' === action) {
            if (failStudents) { throw new Error('network error'); }
            return students();
        }
        return { items: [item(5, 3)], next: null, pending_total: 1 };
    });

    root.rows[0].fire('click');
    await tick();
    assert.deepEqual(calls[1], { action: 'students', params: { group_lesson_id: 5, work_id: 3 } });
    assert.match(root.html, /Иванов Иван/);
    assert.match(root.html, /На проверке/);
    assert.match(root.html, /Засчитать всем несдавшим|data-credit="11"/);

    const before = calls.length;
    root.refresh.fire('click');
    assert.match(root.html, /Обновление…/);
    const afterFirst = calls.length;
    root.refresh.fire('click');
    assert.equal(calls.length, afterFirst, 'второй клик во время обновления не запускает повторный запрос');
    await tick();
    assert.equal(calls.length, before + 1, 'обновляются только ученики открытой работы');

    failStudents = true;
    root.refresh.fire('click');
    await tick();
    assert.match(root.html, /Иванов Иван/, 'после неудачного обновления показаны прежние ученики');
});

test('после обновления учеников счётчики работы в списке меняются на месте', async () => {
    let credited = false;
    const { root } = await setup((action) => {
        if ('students' === action) {
            return credited
                ? [
                    { student_person_id: 10, student_name: 'Иванов Иван', submission_id: 91, status: 'graded', score: 2, max_score: 2, credited: false },
                    { student_person_id: 11, student_name: 'Петров Пётр', submission_id: 92, status: 'graded', score: 2, max_score: 2, credited: true },
                ]
                : students();
        }
        return { items: [item(5, 3)], next: null, pending_total: 1 };
    });

    root.rows[0].fire('click');
    await tick();
    credited = true;
    root.refresh.fire('click');
    await tick();
    root.back.fire('click');

    assert.match(root.html, /сдали 2 из 2/);
    assert.doesNotMatch(root.html, /на проверке/);
});

test('быстрый статус из ссылки главной переключает список на «Ждут проверки»', async () => {
    const { root, calls, applyWorksStatus } = await setup(() => ({ items: [item(5, 3)], next: null, pending_total: 1 }));

    applyWorksStatus('pending');
    await tick();

    assert.equal(calls[1].params.status, 'pending');
    assert.match(root.html, /class="on" data-status="pending"/);

    const before = calls.length;
    applyWorksStatus('pending');
    assert.equal(calls.length, before, 'тот же статус список заново не грузит');
});

test('ошибка первой порции показывает сообщение с «Повторить»', async () => {
    let fail = true;
    const { root, calls } = await setup(() => {
        if (fail) { throw new Error('Сервер недоступен'); }
        return { items: [item(5, 3)], next: null, pending_total: 0 };
    });

    assert.match(root.html, /Сервер недоступен/);
    assert.ok(root.more, 'кнопка «Повторить» есть');

    fail = false;
    root.more.fire('click');
    await tick();

    assert.equal(calls.length, 2);
    assert.match(root.html, /Работа 3/);
});
