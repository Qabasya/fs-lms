import { BaseValidator } from './BaseValidator.js';

export class CyrillicDigitsValidator extends BaseValidator {
    checkCustom(value, input) {
        const cyrillicRegex = /^[А-Яа-яЁё0-9\s-]+$/u;

        if (!cyrillicRegex.test(value)) {
            return 'Разрешены только буквы кириллицы, цифры, пробелы, и дефис.';
        }

        return null;
    }
}