import { BaseValidator } from './BaseValidator.js';

export class AddressValidator extends BaseValidator {
    checkCustom(value) {
        const regex = /^[А-Яа-яЁё0-9\s\-\.,/()№]+$/u;

        if (!regex.test(value)) {
            return 'Данное поле может содержать кириллицу, цифры, пробелы, точку, запятую, дефис, слэш и знак №.';
        }

        return null;
    }
}
