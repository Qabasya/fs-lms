import { BaseValidator } from './BaseValidator.js';

export class LatinOnlyValidator extends BaseValidator {
    checkCustom(value, input) {
        // Разрешена только латиница, цифры и нижнее подчеркивание
        const latinRegex = /^[A-Za-z0-9_]+$/;

        if (!latinRegex.test(value)) {
            return 'Разрешена только латиница, цифры и символ подчеркивания.';
        }

        return null;
    }
}