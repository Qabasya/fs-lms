import { createSuggest } from './dadata-suggest.js';
import { findSchools, resolveBareNumber } from '../../common/school-suggest.js';

export { findSchools, resolveBareNumber };

/**
 * Автодополнение поля «Школа» из справочника (форма заявки). Поиск — в `common/school-suggest.js`.
 * Единственное автозамещение — на уходе из поля, когда введён голый номер («школа 24») и ему отвечает ровно одна школа.
 */

/**
 * @param {HTMLInputElement|null} input Поле «Школа».
 */
export function initSchoolSuggest( input ) {
	if ( ! input ) { return; }

	createSuggest( input, { getItems: findSchools, minChars: 1, debounceMs: 120 } );

	input.addEventListener( 'blur', () => {
		const full = resolveBareNumber( input.value );
		if ( full && full !== input.value ) {
			input.value = full;
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		}
	} );
}
