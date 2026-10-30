import assert from 'node:assert/strict';
import { test } from 'node:test';

import { examCountdownValue, homeTileModels } from '../../src/js/profile/learner-home-tiles.js';

const attendance = { percent: 91 };
const deadlines = [ { player_url: '/lesson/1', due_at: '2027-05-02 10:00:00' } ];
const exam = ( over = {} ) => ( { subject_key: 'inf_ege', subject: 'Информатика ЕГЭ', days: 32, hours: 770, urgent: false, ...over } );

test( 'до недели — дни, в последние 7 суток — часы', () => {
	assert.equal( examCountdownValue( exam() ), '32 дня' );
	assert.equal( examCountdownValue( exam( { urgent: true, hours: 22 } ) ), '22 ч' );
	assert.equal( examCountdownValue( exam( { days: 0, urgent: true, hours: 3 } ) ), '3 ч' );
} );

test( 'дни склоняются целиком: 1 день, 2–4 дня, 5–9 и 0 дней, 11–14 дней', () => {
	const word = ( days ) => examCountdownValue( exam( { days } ) );

	assert.deepEqual( [ 1, 2, 4, 5, 9, 10, 11, 14, 21, 22, 25, 101 ].map( word ), [
		'1 день', '2 дня', '4 дня', '5 дней', '9 дней', '10 дней', '11 дней', '14 дней', '21 день', '22 дня', '25 дней', '101 день',
	] );
	assert.equal( word( 0 ), '0 дней' );
} );

test( 'подпись предмета: последнее слово склеено с предыдущим неразрывным пробелом, чтобы переносилась целиком', () => {
	const label = ( subject ) => homeTileModels( { deadlines, attendance, exam_countdown: [ exam( { subject } ) ], highlights: null }, { parent: false } )[ 1 ].label;

	assert.equal( label( 'Информатика ЕГЭ' ), 'До экзамена · Информатика\u00A0ЕГЭ' );
	assert.equal( label( 'Программирование на Python' ), 'До экзамена · Программирование на\u00A0Python' );
	assert.equal( label( 'Физика' ), 'До экзамена · Физика' );
} );

test( 'ученику «До экзамена» заменяет «Посещаемость»; срочное помечено', () => {
	const tiles = homeTileModels( { deadlines, attendance, exam_countdown: [ exam( { urgent: true, hours: 22 } ) ], highlights: null }, { parent: false } );

	assert.deepEqual( tiles.map( ( t ) => t.label ), [ 'Дедлайнов', 'До экзамена · Информатика\u00A0ЕГЭ' ] );
	assert.equal( tiles[ 1 ].value, '22 ч' );
	assert.equal( tiles[ 1 ].urgent, true );
	assert.equal( tiles[ 0 ].href, '/lesson/1' );
} );

test( 'без даты экзамена остаётся «Посещаемость»; родителю она остаётся всегда', () => {
	assert.equal( homeTileModels( { deadlines, attendance, exam_countdown: [], highlights: null }, { parent: false } )[ 1 ].label, 'Посещаемость' );
	assert.equal( homeTileModels( { deadlines, attendance, exam_countdown: [ exam() ], highlights: null }, { parent: true } )[ 1 ].label, 'Посещаемость' );
	assert.equal( homeTileModels( { deadlines, attendance: { percent: null }, exam_countdown: [], highlights: null }, { parent: true } )[ 1 ].value, '—' );
} );

test( 'несколько экзаменов — по плитке на каждый, но не больше двух', () => {
	const tiles = homeTileModels( { deadlines, attendance, exam_countdown: [ exam(), exam( { subject: 'Русский ЕГЭ' } ), exam( { subject: 'Физика ЕГЭ' } ) ], highlights: null }, { parent: false } );

	assert.equal( tiles.filter( ( t ) => t.label.startsWith( 'До экзамена' ) ).length, 2 );
} );

test( 'плитки пилота не входят в набор по умолчанию — они в меню «+» и появляются, когда их добавили', () => {
	const highlights = { streak: 5, to_fix: 3, last_result: { value: '72', note: 'Пробник · баллов' } };
	const d = { deadlines, attendance, exam_countdown: [], highlights };

	assert.deepEqual( homeTileModels( d, { parent: false } ).map( ( t ) => t.label ), [ 'Дедлайнов', 'Посещаемость' ] );

	const chosen = homeTileModels( d, { parent: false }, { tiles: [ 'deadlines', 'attendance', 'streak', 'to_fix', 'last_result' ] } );
	assert.deepEqual( chosen.slice( 2 ).map( ( t ) => [ t.label, t.value ] ), [
		[ 'Серия без пропусков', '5' ],
		[ 'Осталось исправить', '3' ],
		[ 'Последний результат', '72' ],
	] );
	assert.equal( chosen[ 4 ].note, 'Пробник · баллов' );
} );

test( 'у пилота серия и «осталось исправить» доступны и с нулём, «Последний результат» — только когда он есть', () => {
	const highlights = { streak: 0, to_fix: 0, last_result: null };
	const tiles = homeTileModels( { deadlines, attendance, exam_countdown: [], highlights }, { parent: false }, { tiles: [ 'streak', 'to_fix', 'last_result' ] } );

	assert.deepEqual( tiles.map( ( t ) => [ t.label, t.value ] ), [ [ 'Серия без пропусков', '0' ], [ 'Осталось исправить', '0' ] ] );
} );
