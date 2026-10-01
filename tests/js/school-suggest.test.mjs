/**
 * Подсказка школ в форме заявки (src/js/frontend/services/school-suggest.js).
 * Запуск: `npm run test:js`.
 */
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';

import { SCHOOLS } from '../../src/js/frontend/data/schools.js';
import { findSchools, resolveBareNumber } from '../../src/js/frontend/services/school-suggest.js';
import { SchoolNameValidator } from '../../src/js/common/validators/SchoolNameValidator.js';

describe( 'findSchools: поиск по номеру', () => {
	test( '«50» даёт МАОУ СОШ № 50', () => {
		assert.deepEqual( findSchools( '50' ), [ 'МАОУ СОШ № 50' ] );
	} );

	test( '«школа 24», «Школа №24», «сош 24», «№ 24» — одна и та же школа', () => {
		for ( const q of [ 'школа 24', 'Школа №24', 'сош 24', '№ 24', 'СОШ № 24', 'маоу сош 24' ] ) {
			assert.deepEqual( findSchools( q ), [ 'МАОУ СОШ № 24' ], q );
		}
	} );

	test( 'точное совпадение номера идёт первым, дальше — номера, начинающиеся с введённого', () => {
		const found = findSchools( '2' );
		assert.equal( found[ 0 ], 'МАОУ СОШ № 2' );
		assert.ok( found.includes( 'МАОУ СОШ № 21' ) );
		assert.ok( found.includes( 'МАОУ гимназия № 22' ) );
		assert.ok( ! found.includes( 'МАОУ СОШ № 12' ), '12 не начинается с 2' );
	} );

	test( 'одна цифра тоже ищет', () => {
		assert.equal( findSchools( '5' )[ 0 ], 'МАОУ СОШ № 5' );
	} );

	test( 'номер без «№» в названии (лицей 35) находится', () => {
		assert.deepEqual( findSchools( '35' ), [ 'МАОУ лицей 35 им. Буткова В.В.' ] );
	} );

	test( 'номер из названия «им. …» не путается с номером школы', () => {
		// «Ю.А. Гагарина» — цифр нет, а у № 40 номер 40.
		assert.deepEqual( findSchools( '40' ), [ 'МАОУ гимназия № 40 им. Ю.А. Гагарина' ] );
	} );

	test( 'несуществующий номер — пусто', () => {
		assert.deepEqual( findSchools( 'школа 99' ), [] );
		assert.deepEqual( findSchools( '999' ), [] );
	} );
} );

describe( 'findSchools: поиск по типу и названию', () => {
	test( 'тип + номер сужает выбор', () => {
		assert.deepEqual( findSchools( 'лицей 18' ), [ 'МАОУ лицей № 18' ] );
		assert.deepEqual( findSchools( 'гимназия 22' ), [ 'МАОУ гимназия № 22' ] );
	} );

	test( 'один номер у двух школ — показываются обе', () => {
		assert.deepEqual( findSchools( '17' ).sort(), [ 'МАОУ лицей № 17', 'МБОУ ВСОШ № 17' ] );
	} );

	test( 'слово ищется по началу слова, регистр и «ё» не важны', () => {
		assert.ok( findSchools( 'гимн' ).includes( 'Православная гимназия' ) );
		assert.ok( findSchools( 'ГИМН' ).includes( 'МАОУ Гимназия Вектор' ) );
		assert.ok( findSchools( 'альбер' ).includes( 'Альбертина' ) );
	} );

	test( 'частные школы находятся по названию', () => {
		assert.deepEqual( findSchools( 'фокс' ), [ 'Фоксфорд' ] );
		assert.deepEqual( findSchools( 'росток' ), [ 'Росток' ] );
	} );

	test( 'только «обёрточные» слова без номера — пусто (не вываливаем весь список)', () => {
		for ( const q of [ 'школа', 'сош', 'маоу', 'школа №' ] ) {
			assert.deepEqual( findSchools( q ), [], q );
		}
	} );

	test( 'пустой и бессмысленный ввод — пусто', () => {
		assert.deepEqual( findSchools( '' ), [] );
		assert.deepEqual( findSchools( '   ' ), [] );
		assert.deepEqual( findSchools( '№' ), [] );
	} );

	test( 'не больше 8 вариантов', () => {
		assert.equal( findSchools( '1' ).length, 8 );
	} );
} );

describe( 'resolveBareNumber: автозамена на выходе из поля', () => {
	test( 'голый номер с единственной школой заменяется на полное название', () => {
		assert.equal( resolveBareNumber( 'школа 24' ), 'МАОУ СОШ № 24' );
		assert.equal( resolveBareNumber( '№ 6' ), 'МАОУ СОШ № 6 с УИОП' );
		assert.equal( resolveBareNumber( 'сош 50' ), 'МАОУ СОШ № 50' );
	} );

	test( 'номер у двух школ — не заменяем', () => {
		assert.equal( resolveBareNumber( '17' ), '' );
		assert.equal( resolveBareNumber( 'школа 17' ), '' );
	} );

	test( 'есть значимое слово — это уже не «голый номер», вписанное вручную не трогаем', () => {
		assert.equal( resolveBareNumber( 'лицей 18' ), '' );
		assert.equal( resolveBareNumber( 'школа 24 Гвардейск' ), '' );
		assert.equal( resolveBareNumber( 'школа 24 г. Москва' ), '' );
	} );

	test( 'нет такой школы или нет номера — пусто', () => {
		assert.equal( resolveBareNumber( 'школа 99' ), '' );
		assert.equal( resolveBareNumber( 'школа' ), '' );
		assert.equal( resolveBareNumber( '' ), '' );
	} );

	test( 'уже полное название не меняется на другое', () => {
		assert.equal( resolveBareNumber( 'МАОУ СОШ № 24' ), 'МАОУ СОШ № 24' );
	} );
} );

describe( 'справочник SCHOOLS', () => {
	const validator = new SchoolNameValidator();

	test( 'названия непустые, без дублей и без служебных символов', () => {
		assert.ok( SCHOOLS.length > 0 );
		assert.equal( new Set( SCHOOLS ).size, SCHOOLS.length, 'дубли в списке' );
		for ( const name of SCHOOLS ) {
			assert.equal( name, name.trim(), `пробелы по краям: «${ name }»` );
			assert.ok( name.length >= 3, `короткое название: «${ name }»` );
			assert.ok( ! name.includes( '*' ), `звёздочка в «${ name }»` );
		}
	} );

	test( 'каждое название проходит валидатор поля «Школа» (иначе выбранная подсказка не отправится)', () => {
		for ( const name of SCHOOLS ) {
			assert.equal( validator.checkCustom( name ), null, name );
		}
	} );

	test( 'каждая школа находится по полному названию', () => {
		for ( const name of SCHOOLS ) {
			assert.ok( findSchools( name ).includes( name ), `не найдена по своему названию: «${ name }»` );
		}
	} );

	test( 'каждая нумерованная школа находится по своему номеру', () => {
		for ( const name of SCHOOLS ) {
			const number = name.match( /\d+/ )?.[ 0 ];
			if ( number ) {
				assert.ok( findSchools( number ).includes( name ), `«${ number }» не находит «${ name }»` );
			}
		}
	} );

	test( 'список совпадает с .docs/schools (источником перечня)', { skip: ! existsSync( '.docs/schools' ) }, () => {
		const source = readFileSync( '.docs/schools', 'utf8' )
			.split( '\n' )
			.map( ( l ) => l.trim().replace( /\*$/, '' ).trim() )
			.filter( Boolean );

		assert.deepEqual( SCHOOLS, source, 'src/js/frontend/data/schools.js разошёлся с .docs/schools' );
	} );
} );
