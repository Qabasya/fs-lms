import { BaseValidator } from './BaseValidator.js';

export class SubjectNameValidator extends BaseValidator {
    checkCustom(value, input) {
        const regex = /^[A-Za-zА-Яа-яЁё0-9\s-]+$/u;

        if (!regex.test(value)) {
            return 'Разрешены буквы (кириллица, латиница), цифры, пробелы, и дефис.';
        }

        return null;
    }
}
