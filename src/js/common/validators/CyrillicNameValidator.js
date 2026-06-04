import { BaseValidator } from './BaseValidator.js';

export class CyrillicNameValidator extends BaseValidator {
    checkCustom(value, input) {
        // Разрешены русские буквы, пробелы и дефисы (для двойных имён/фамилий)
        const cyrillicRegex = /^[А-Яа-яЁё\s-]+$/u;

        if (!cyrillicRegex.test(value)) {
            return 'Разрешены только буквы кириллицы, пробелы и дефис.';
        }

        return null;
    }
}