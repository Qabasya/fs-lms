import { createSuggest } from './dadata-suggest.js';
import { findSchools, resolveBareNumber } from '../../common/school-suggest.js';

export { findSchools, resolveBareNumber };

/**
 * Автодополнение поля «Школа» из справочника (форма заявки). Поиск — в `common/school-suggest.js`.
 * Единственное автозамещение — на уходе из поля, когда введён голый номер («школа 24») и ему отвечает ровно одна школа.
 */

const DEFAULT_CITY = 'Калининград';

/**
 * @param {HTMLInputElement|null} input Поле «Школа».
 * @param {HTMLInputElement|null} [cityInput] Поле «Город»: подсказки только для Калининграда.
 */
export function initSchoolSuggest( input, cityInput = null ) {
	if ( ! input ) { return; }

	// Справочник — школы Калининграда: для другого города школу вписывают вручную.
	const isLocal = () => ! cityInput || cityInput.value.trim().toLowerCase() === DEFAULT_CITY.toLowerCase();

	createSuggest( input, { getItems: ( q ) => ( isLocal() ? findSchools( q ) : [] ), minChars: 1, debounceMs: 120 } );

	input.addEventListener( 'blur', () => {
		if ( ! isLocal() ) { return; }
		const full = resolveBareNumber( input.value );
		if ( full && full !== input.value ) {
			input.value = full;
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		}
	} );
}
