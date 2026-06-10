/**
 * Форматирует значение телефонного инпута в маску +7(XXX)-XXX-XX-XX.
 * @param {HTMLInputElement} input
 */
export function formatPhone( input ) {
    let value = input.value;

    if ( ! value ) {
        input.value = '+7(';
        return;
    }

    if ( ! value.startsWith( '+7(' ) ) {
        let digits = value.replace( /\D/g, '' );
        if ( digits.startsWith( '7' ) || digits.startsWith( '8' ) ) {
            digits = digits.substring( 1 );
        }
        value = '+7(' + digits;
    }

    const prefix  = '+7(';
    const digits  = value.substring( prefix.length ).replace( /\D/g, '' ).substring( 0, 10 );
    let formatted = prefix;

    if ( digits.length > 0 ) { formatted += digits.substring( 0, 3 ); }
    if ( digits.length >= 3 ) { formatted += ')-'; }
    if ( digits.length > 3 )  { formatted += digits.substring( 3, 6 ); }
    if ( digits.length >= 6 ) { formatted += '-'; }
    if ( digits.length > 6 )  { formatted += digits.substring( 6, 8 ); }
    if ( digits.length >= 8 ) { formatted += '-'; }
    if ( digits.length > 8 )  { formatted += digits.substring( 8, 10 ); }

    input.value = formatted;
}

/**
 * Привязывает маску телефона ко всем событиям инпута.
 * @param {HTMLInputElement|null} input
 */
export function bindPhoneMask( input ) {
    if ( ! input ) { return; }

    if ( input.value ) { formatPhone( input ); }

    input.addEventListener( 'focus', ( e ) => {
        if ( ! e.target.value ) { e.target.value = '+7('; }
    } );

    input.addEventListener( 'input', ( e ) => formatPhone( e.target ) );

    input.addEventListener( 'keydown', ( e ) => {
        if ( e.target.value === '+7(' && ( e.key === 'Backspace' || e.key === 'Delete' ) ) {
            e.preventDefault();
        }
    } );
}

/**
 * Форматирует значение инпута паспорта в маску «XXXX XXXXXX».
 * @param {HTMLInputElement} input
 */
export function formatPassportSN( input ) {
    let value = input.value.replace( /\D/g, '' ).substring( 0, 10 );
    if ( value.length > 4 ) {
        value = value.substring( 0, 4 ) + ' ' + value.substring( 4 );
    }
    input.value = value;
}

/**
 * Привязывает маску ИНН к инпуту (только цифры, макс. 12 символов).
 * @param {HTMLInputElement|null} input
 */
export function bindInnMask( input ) {
    if ( ! input ) { return; }
    input.addEventListener( 'input', () => {
        input.value = input.value
            .replace( /\D/g, '' )
            .substring( 0, 12 );
    } );
}
