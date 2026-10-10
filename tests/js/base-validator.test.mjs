import assert from 'node:assert/strict';
import test from 'node:test';
import { BaseValidator } from '../../src/js/common/validators/BaseValidator.js';

const validator = new BaseValidator();
const input = overrides => ({ type: 'text', required: false, value: '', maxLength: 100, validity: { valid: true }, ...overrides });

test('общая проверка текста: обязательность, пробелы и лимит при программном заполнении', () => {
    assert.ok(validator.checkNative(input({ required: true, value: '  ' })));
    assert.ok(validator.checkNative(input({ value: 'а'.repeat(101) })));
    assert.equal(validator.checkNative(input({ value: 'O’Connor', required: true })), null);
    assert.equal(validator.checkNative(input({ value: '' })), null);
    assert.equal(validator.checkNative(input({ required: true, disabled: true })), null);
});
