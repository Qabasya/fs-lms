import { BaseValidator } from './BaseValidator.js';

export class SchoolNameValidator extends BaseValidator {
    checkCustom(value) {
        const regex = /^[А-Яа-яЁё0-9\s\-№.]+$/u;

        if (!regex.test(value)) {
            return 'Разрешены только кириллица, цифры, пробелы, дефис, точка и знак №.';
        }

        return null;
    }
}