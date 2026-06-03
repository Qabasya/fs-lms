import { BaseValidator } from './BaseValidator.js';

export class CyrillicValidator extends BaseValidator {
    checkCustom( value ) {
        if ( ! /^[А-Яа-яЁё\s\-]+$/u.test( value ) ) {
            return 'Разрешены только кириллица, пробелы и дефис.';
        }
        return null;
    }
}
