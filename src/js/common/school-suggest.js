import { SCHOOLS } from '../frontend/data/schools.js';

/**
 * Поиск по справочнику школ без DOM: общий для формы заявки (сайт) и формы источника (кабинет).
 * Справочник один — `frontend/data/schools.js`; кабинет импортирует его, а не копирует.
 *
 * «школа 24», «сош 24», «лицей 18» → «МАОУ СОШ № 24», «МАОУ лицей № 18». Вписать школу вручную можно всегда —
 * справочник только предлагает.
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
 * Школа справочника по точному названию (без учёта регистра) или null — свободный ввод.
 *
 * @param {string} name
 * @return {string|null} Каноническое название справочника.
 */
export function exactSchool( name ) {
	const wanted = normalize( String( name ).trim() );
	return INDEX.find( ( s ) => normalize( s.name ) === wanted )?.name ?? null;
}

/**
 * Устойчивый ASCII-ключ школы справочника: `school-` + хеш нормализованного названия.
 * Справочник ключей не хранит, а серверный `sanitizeKey` кириллицу вырезает, поэтому ключ — хеш названия.
 *
 * @param {string} name Каноническое название справочника.
 * @return {string}
 */
export function schoolKeyOf( name ) {
	let hash = 0x811c9dc5;
	for ( const ch of normalize( name ) ) {
		hash ^= ch.codePointAt( 0 );
		hash = Math.imul( hash, 0x01000193 ) >>> 0;
	}
	return 'school-' + hash.toString( 16 ).padStart( 8, '0' );
}
