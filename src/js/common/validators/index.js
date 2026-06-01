import { BaseValidator } from './BaseValidator.js';
import { PhoneValidator } from './PhoneValidator.js';
import { CyrillicNameValidator } from './CyrillicNameValidator.js';
import { CyrillicValidator } from './CyrillicValidator.js';
import { LatinOnlyValidator } from './LatinOnlyValidator.js';

// Экспортируем карту инстансов для быстрого доступа по ключу
export const FieldValidators = {
    phone:        new PhoneValidator(),
    cyrillicName: new CyrillicNameValidator(),
    cyrillic:     new CyrillicValidator(),
    latinOnly:    new LatinOnlyValidator(),
    default:      new BaseValidator(),
};