import { BaseValidator } from './BaseValidator.js';

export class PassportSeriesNumberValidator extends BaseValidator {
    checkCustom( value ) {
        if ( ! /^\d{4} \d{6}$/.test( value ) ) {
            return 'Формат: 4 цифры, пробел, 6 цифр (например: 4507 123456).';
        }
        return null;
    }
}
