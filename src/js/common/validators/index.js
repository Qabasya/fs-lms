import { BaseValidator } from './BaseValidator.js';
import { PhoneValidator } from './PhoneValidator.js';
import { CyrillicNameValidator } from './CyrillicNameValidator.js';
import {AddressValidator} from './AddressValidator.js';
import { LatinOnlyValidator } from './LatinOnlyValidator.js';
import { PassportSeriesNumberValidator } from './PassportSeriesNumberValidator.js';
import {SchoolNameValidator} from "./SchoolNameValidator";
import {InnValidator} from "./InnValidator";

// Экспортируем карту инстансов для быстрого доступа по ключу
export const FieldValidators = {
    inn: new InnValidator(),
    phone:        new PhoneValidator(),
    cyrillicName: new CyrillicNameValidator(),
    address:     new AddressValidator(),
    schoolName:   new SchoolNameValidator(),
    latinOnly:    new LatinOnlyValidator(),
    passportSN:   new PassportSeriesNumberValidator(),
    default:      new BaseValidator(),
};