import { FieldValidators } from './validators/index.js';

function renderFieldError( input, errorMessage ) {
    const formGroup = input.closest( '.fs-form-group' );
    if ( ! formGroup ) { return; }

    let errorElement = formGroup.querySelector( '.fs-field-error' );

    if ( errorMessage ) {
        formGroup.classList.add( 'form-invalid' );

        if ( ! errorElement ) {
            errorElement = document.createElement( 'p' );
            errorElement.className = 'description error fs-field-error';

            const nativeDesc = formGroup.querySelector( '.description:not(.error)' );
            if ( nativeDesc ) {
                formGroup.insertBefore( errorElement, nativeDesc );
            } else {
                formGroup.appendChild( errorElement );
            }
        }

        errorElement.textContent = errorMessage;
    } else {
        formGroup.classList.remove( 'form-invalid' );
        if ( errorElement ) { errorElement.remove(); }
    }
}

/**
 * Validates a single input and renders the error message near it.
 * Validator is resolved from data-validate attribute; falls back to field type or default.
 * @param {HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement} input
 * @returns {boolean} True if the field is valid
 */
export function validateField( input ) {
    const validatorKey = input.dataset.validate || input.type;
    const validator    = FieldValidators[ validatorKey ] || FieldValidators.default;
    const errorMessage = validator.validate( input );

    renderFieldError( input, errorMessage );

    return null === errorMessage;
}

/**
 * Clears the error state from a single input's form group.
 * @param {HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement} input
 */
export function clearFieldError( input ) {
    const formGroup = input.closest( '.fs-form-group' );
    if ( formGroup && formGroup.classList.contains( 'form-invalid' ) ) {
        formGroup.classList.remove( 'form-invalid' );
        const error = formGroup.querySelector( '.fs-field-error' );
        if ( error ) { error.remove(); }
    }
}

/**
 * Binds blur and input events on all fields in a form.
 * Returns a validateAll() function to be called on submit.
 *
 * Usage:
 *   const validateAll = initFormValidation(form);
 *   form.addEventListener('submit', e => { if (!validateAll()) e.preventDefault(); });
 *
 * Adding a new validator:
 *   1. Create src/js/common/validators/MyValidator.js extending BaseValidator
 *   2. Register it in validators/index.js: { myKey: new MyValidator() }
 *   3. Add data-validate="myKey" to the input — no other wiring needed
 *
 * @param {HTMLFormElement} form
 * @returns {function(): boolean} validateAll — validates every input and returns true if all pass
 */
export function initFormValidation( form ) {
    form.setAttribute( 'novalidate', 'true' );

    const inputs = form.querySelectorAll( 'input, select, textarea' );

    inputs.forEach( input => {
        input.addEventListener( 'blur',  () => validateField( input ) );
        input.addEventListener( 'input', () => clearFieldError( input ) );
    } );

    return function validateAll() {
        let firstInvalid = null;

        inputs.forEach( input => {
            if ( ! validateField( input ) && ! firstInvalid ) {
                firstInvalid = input;
            }
        } );

        if ( firstInvalid ) { firstInvalid.focus(); }

        return ! firstInvalid;
    };
}