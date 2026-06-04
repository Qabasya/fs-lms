import { BaseValidator } from './BaseValidator.js';

export class InnValidator extends BaseValidator {
    checkCustom( value ) {
        const inn = value.replace( /\D/g, '' );

        if ( ! /^\d{12}$/.test( inn ) ) {
            return 'ИНН физического лица должен содержать 12 цифр.';
        }

        const coeff11 = [ 7, 2, 4, 10, 3, 5, 9, 4, 6, 8 ];
        const coeff12 = [ 3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8 ];

        const calcDigit = ( digits, coeffs ) => {
            const sum = coeffs.reduce(
                ( acc, coeff, i ) => acc + Number( digits[ i ] ) * coeff,
                0
            );

            return ( sum % 11 ) % 10;
        };

        const digit11 = calcDigit( inn, coeff11 );
        const digit12 = calcDigit( inn, coeff12 );

        if (
            digit11 !== Number( inn[ 10 ] ) ||
            digit12 !== Number( inn[ 11 ] )
        ) {
            return 'Указан некорректный ИНН.';
        }

        return null;
    }
}