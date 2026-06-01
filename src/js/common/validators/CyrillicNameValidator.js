import { BaseValidator } from './BaseValidator.js';

export class CyrillicNameValidator extends BaseValidator {
    checkCustom(value, input) {
        // Разрешены русские буквы, пробелы и дефисы (для двойных имён/фамилий)
        const cyrillicRegex = /^[А-Яа-яЁё\s-]+$/u;

        if (!cyrillicRegex.test(value)) {
            return 'Разрешены только буквы кириллицы, пробелы и дефис.';
        }

        // Дополнительная бизнес-проверка: имя + фамилия (минимум 2 слова)
        const words = value.split(/\s+/).filter(word => word.length > 0);
        if (words.length < 2) {
            return 'Пожалуйста, укажите полностью Фамилию и Имя.';
        }

        return null;
    }
}