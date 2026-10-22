import assert from 'node:assert/strict';
import test from 'node:test';

import { installCtxMenu } from './ctx-menu-stub.mjs';

/* Пикеры кабинета (picker.js): единый вид выбора из списка и порядок пунктов. */

globalThis.window = { fsProfile: { groups: [], avatars: {} } };
const { openGroupPicker, openOptionPicker, optionPickerBtnHtml } = await import('../../src/js/profile/picker.js');

const anchor = { getBoundingClientRect: () => ({ left: 10, right: 210, top: 10, bottom: 40, width: 200 }) };
const groups = [
    { id: 1, name: 'КОГЭ-1', subject: 'Информатика', subject_key: 'inf' },
    { id: 2, name: 'КОГЭ-2', subject: 'Информатика', subject_key: 'inf' },
];

test('«Все группы» с first: true стоит самым первым пунктом, группы идут следом', () => {
    const menu = installCtxMenu();

    openGroupPicker(anchor, groups, 0, () => {}, [{ v: '0', label: 'Все группы', chip: '∀', first: true }]);

    assert.deepEqual(menu.values(), ['0', '1', '2']);
    assert.equal(menu.labels()[0], 'Все группы');
    assert.deepEqual(menu.active(), ['0'], 'выбранный пункт отмечен');
});

test('служебный пункт без first остаётся в конце списка (КТП: «Индивидуальные занятия»)', () => {
    const menu = installCtxMenu();

    openGroupPicker(anchor, groups, 1, () => {}, [{ v: '-1', label: 'Индивидуальные занятия', chip: 'Инд' }]);

    assert.deepEqual(menu.values(), ['1', '2', '-1']);
});

test('выбор группы зовёт onPick с числом, текущая группа — не зовёт', () => {
    const menu = installCtxMenu();
    const picked = [];

    openGroupPicker(anchor, groups, 1, (id) => picked.push(id), [{ v: '0', label: 'Все группы', chip: '∀', first: true }]);
    menu.pick('1');
    menu.pick('2');
    menu.pick('0');

    assert.deepEqual(picked, [2, 0]);
});

test('выбор из списка значений: пустое значение — полноценный пункт, onPick только при смене', () => {
    const menu = installCtxMenu();
    const picked = [];
    const options = [{ value: '', label: 'Все типы работ' }, { value: 'practice', label: 'Практика' }];

    openOptionPicker(anchor, options, '', (value) => picked.push(value));

    assert.deepEqual(menu.values(), ['', 'practice']);
    assert.deepEqual(menu.active(), [''], 'текущее значение отмечено');
    menu.pick('');
    menu.pick('practice');
    assert.deepEqual(picked, ['practice']);
});

test('кнопка выбора из списка — тот же kp-btn, что у группы, с подписью и названием для скринридера', () => {
    const html = optionPickerBtnHtml('Все <типы>', 'wkTypeBtn', 'Тип работы');

    assert.match(html, /<button type="button" class="kp-btn" id="wkTypeBtn" aria-label="Тип работы">/);
    assert.match(html, /<span class="kp-txt">Все &lt;типы&gt;<\/span>/);
    assert.match(html, /kp-caret/);
});

test('без якоря меню не открывается', () => {
    const menu = installCtxMenu();

    openOptionPicker(null, [{ value: 'a', label: 'A' }], '', () => {});

    assert.equal(menu.isOpen(), false);
});
