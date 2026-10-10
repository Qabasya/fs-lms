import { BaseValidator } from './BaseValidator.js';
import { PhoneValidator } from './PhoneValidator.js';
import { CyrillicNameValidator } from './CyrillicNameValidator.js';
import {AddressValidator} from './AddressValidator.js';
import { LatinOnlyValidator } from './LatinOnlyValidator.js';
import { PasswordValidator } from './PasswordValidator.js';
import { PassportSeriesNumberValidator } from './PassportSeriesNumberValidator.js';
import {SchoolNameValidator} from "./SchoolNameValidator.js";
import {InnValidator} from "./InnValidator.js";
import {CyrillicDigitsValidator} from "./CyrillicDigitsValidator.js";
import {SubjectNameValidator} from "./SubjectNameValidator.js";

// Экспортируем карту инстансов для быстрого доступа по ключу
export const FieldValidators = {
    inn: new InnValidator(),
    phone:        new PhoneValidator(),
    cyrillicName: new CyrillicNameValidator(),
    cyrillicDigits: new CyrillicDigitsValidator(),
    subjectName:  new SubjectNameValidator(),
    address:     new AddressValidator(),
    schoolName:   new SchoolNameValidator(),
    latinOnly:    new LatinOnlyValidator(),
    password:     new PasswordValidator(),
    passportSN:   new PassportSeriesNumberValidator(),
    default:      new BaseValidator(),
};