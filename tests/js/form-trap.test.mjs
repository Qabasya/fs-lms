import assert from 'node:assert/strict';
import test from 'node:test';

import { isAutofilled, trapField, resetTrap } from '../../src/js/frontend/services/form-trap.js';

// Ловушка с управляемыми селекторами: `known` — какие псевдоклассы браузер понимает, `autofilled` — вписал ли значение браузер.
function trap({ value = '', autofilled = false, known = [ ':autofill', ':-webkit-autofill', ':-moz-autofill' ] } = {}) {
    return {
        name: 'fs_ref_id', value,
        matches(selector) {
            if (!known.includes(selector)) { throw new SyntaxError(`'${ selector }' is not a valid selector`); }
            return autofilled;
        },
    };
}

const root = input => ({ querySelector: selector => selector === '[data-fs-trap]' ? input : null });

test('значение от автозаполнения снимается', () => {
    const input = trap({ value: 'ООО Ромашка', autofilled: true });
    assert.deepEqual(trapField(root(input)), { fs_ref_id: '' });
    assert.equal(input.value, '');
});

test('значение от скрипта сохраняется — это бот', () => {
    const input = trap({ value: 'spam' });
    assert.deepEqual(trapField(root(input)), { fs_ref_id: 'spam' });
});

test('пустая ловушка уходит пустой', () => {
    assert.deepEqual(trapField(root(trap())), { fs_ref_id: '' });
});

test('нет ловушки на странице — в запрос ничего не добавляется', () => {
    assert.deepEqual(trapField(root(null)), {});
});

test('старый браузер знает только -webkit-autofill: незнакомые селекторы не ломают проверку', () => {
    const input = trap({ value: 'x', autofilled: true, known: [ ':-webkit-autofill' ] });
    assert.equal(isAutofilled(input), true);
    assert.equal(isAutofilled(trap({ known: [] })), false);
});

test('resetTrap очищает поле и не падает без него', () => {
    const input = trap({ value: 'spam' });
    resetTrap(root(input));
    assert.equal(input.value, '');
    resetTrap(root(null));
});
