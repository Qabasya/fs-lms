import assert from 'node:assert/strict';
import { test } from 'node:test';
import { tilesHtml, createLayoutSaver } from '../../src/js/profile/home-customization.js';

const catalog = [
    { id: 'to_review', label: 'На проверке', value: '3', color: '#7048e8', ico: 'check' },
    { id: 'ranking:python', label: 'Рейтинг · Python', value: '1 место', color: '#f08c00', ico: 'star' },
];

test( 'обычная главная сохраняет staff grid и скрывает управление; кастомизация показывает замену и добавление', () => {
    const view = tilesHtml(catalog, ['to_review'], { tiles: null }, false, false);
    assert.doesNotMatch(view, /prof-stat-tiles--learner|data-home-replace|data-home-remove|data-home-add/);
    const edit = tilesHtml(catalog, ['to_review'], { tiles: null }, true, false);
    assert.match(edit, /data-home-replace="to_review"/);
    assert.match(edit, /data-home-remove="to_review"/);
    assert.match(edit, /data-home-add/);
} );

test( 'временно недоступная плитка скрывается, сохранённый выбор не заменяется defaults', () => {
    const layout = { tiles: ['ranking:python'] };
    const summer = tilesHtml(catalog.slice(0, 1), ['to_review'], layout, false);
    assert.doesNotMatch(summer, /На проверке|Рейтинг/);
    assert.deepEqual(layout.tiles, ['ranking:python']);
    assert.match(tilesHtml(catalog, ['to_review'], layout, false), /1 место/);
} );


test( 'быстрые действия сохраняются последовательно даже если первый запрос задержан; снимки не меняются задним числом', async () => {
    const started = [];
    let releaseFirst;
    const firstGate = new Promise(resolve => { releaseFirst = resolve; });
    let persisted;
    const save = createLayoutSaver(async snapshot => {
        started.push(snapshot);
        if (started.length === 1) await firstGate;
        persisted = snapshot;
    }, assert.fail);
    const layout = { sections: ['tiles', 'schedule', 'attention'], tiles: ['lessons_today'] };
    const first = save(layout);
    layout.tiles.push('to_review');
    const second = save(layout);
    layout.sections.reverse();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(started.length, 1, 'второй запрос ждёт завершения первого');
    assert.deepEqual(started[0].tiles, ['lessons_today']);
    releaseFirst();
    await Promise.all([first, second]);
    assert.deepEqual(persisted, { sections: ['tiles', 'schedule', 'attention'], tiles: ['lessons_today', 'to_review'] });
} );

test( 'ошибка сохранения показывает уведомление и не блокирует следующую запись', async () => {
    let calls = 0;
    const errors = [];
    const sent = [];
    const save = createLayoutSaver(async snapshot => {
        calls++;
        if (calls === 1) throw new Error('offline');
        sent.push(snapshot);
    }, error => errors.push(error.message));
    const first = save({ sections: ['tiles'], tiles: null });
    const second = save({ sections: ['schedule'], tiles: [] });
    await Promise.all([first, second]);
    assert.deepEqual(errors, ['offline']);
    assert.deepEqual(sent, [{ sections: ['schedule'], tiles: [] }]);
} );
