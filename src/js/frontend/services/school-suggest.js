import { SCHOOLS } from '../data/schools.js';
import { createSuggest } from './dadata-suggest.js';

/**
 * Автодополнение поля «Школа» из справочника (форма заявки).
 *
 * «школа 24», «сош 24», «лицей 18» → «МАОУ СОШ № 24», «МАОУ лицей № 18»: подсказки
 * в том же выпадающем списке, что у DaData. Вписать школу вручную можно всегда —
 * справочник только предлагает. Единственное автозамещение — на уходе из поля, когда
 * введён голый номер («школа 24») и ему отвечает ровно одна школа справочника.
 */

const MAX_ITEMS = 8;

/** Слова-«обёртки» названия: сами по себе школу не определяют, поэтому в поиске не участвуют. */
const GENERIC = new Set( [ 'школа', 'сош', 'соо', 'оош', 'номер', 'n', 'no', 'маоу', 'мбоу', 'моу', 'мкоу' ] );

const normalize = ( text ) => text.toLowerCase().replaceAll( 'ё', 'е' );
const tokenize  = ( text ) => normalize( text ).split( /[^a-zа-я0-9]+/ ).filter( Boolean );

/** Номер школы — первое число в названии («МАОУ СОШ № 9 им. …» → «9»). */
const numberOf = ( tokens ) => tokens.find( ( t ) => /^\d+$/.test( t ) ) ?? '';

const INDEX = SCHOOLS.map( ( name ) => {
	const tokens = tokenize( name );
	return { name, tokens, number: numberOf( tokens ) };
} );

/** Разбор запроса: число (если есть) и значимые слова. */
function parseQuery( query ) {
	const tokens = tokenize( query );
	return {
		number: numberOf( tokens ),
		words:  tokens.filter( ( t ) => ! /^\d+$/.test( t ) && ! GENERIC.has( t ) ),
		bare:   tokens.length > 0 && tokens.every( ( t ) => /^\d+$/.test( t ) || GENERIC.has( t ) ),
	};
}

/**
 * Школы справочника, подходящие под запрос: каждое значимое слово — начало слова названия,
 * номер (если указан) — начало номера школы; точное совпадение номера идёт первым.
 *
 * @param {string} query
 * @return {string[]} Названия, не больше MAX_ITEMS.
 */
export function findSchools( query ) {
	const { number, words } = parseQuery( query );
	if ( ! number && ! words.length ) { return []; }

	return INDEX
		.filter( ( s ) => ( ! number || s.number.startsWith( number ) )
			&& words.every( ( w ) => s.tokens.some( ( t ) => t.startsWith( w ) ) ) )
		.sort( ( a, b ) => ( b.number === number ) - ( a.number === number ) )
		.slice( 0, MAX_ITEMS )
		.map( ( s ) => s.name );
}

/**
 * Полное название для запроса вида «школа 24» / «сош 24» / «24» — если такому номеру
 * отвечает ровно одна школа справочника.
 *
 * @param {string} query
 * @return {string} Название или '' (запрос не «голый номер» либо школ несколько/нет).
 */
export function resolveBareNumber( query ) {
	const { number, bare } = parseQuery( query );
	if ( ! bare || ! number ) { return ''; }

	const exact = INDEX.filter( ( s ) => s.number === number );
	return 1 === exact.length ? exact[ 0 ].name : '';
}

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
