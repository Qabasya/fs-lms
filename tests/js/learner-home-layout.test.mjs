import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    addTile, addableTiles, defaultTileIds, homeTileModels, moveSection, removeTile, MAX_TILES,
} from '../../src/js/profile/learner-home-tiles.js';

const deadlines = [ { player_url: '/lesson/1', due_at: '2027-05-02 10:00:00' } ];
const exam = ( key, over = {} ) => ( { subject_key: key, subject: `Предмет ${ key }`, days: 30, hours: 720, urgent: false, ...over } );
const base = { deadlines, attendance: { percent: 90 }, exam_countdown: [ exam( 'inf_ege' ), exam( 'rus_ege' ) ], highlights: null };
const pilot = { ...base, highlights: { streak: 4, to_fix: 2, last_result: { value: '72', note: 'Пробник' } } };
const ids = ( tiles ) => tiles.map( ( t ) => t.id );

test( 'у плиток есть стабильные id: дедлайны, экзамен по предмету, посещаемость, пилотные', () => {
	assert.deepEqual( ids( homeTileModels( pilot, { parent: false } ) ), [ 'deadlines', 'exam:inf_ege', 'exam:rus_ege', 'streak', 'to_fix', 'last_result' ] );
	assert.deepEqual( ids( homeTileModels( base, { parent: true } ) ), [ 'deadlines', 'attendance' ] );
	assert.deepEqual( ids( homeTileModels( { ...base, exam_countdown: [] }, { parent: false } ) ), [ 'deadlines', 'attendance' ] );
} );

test( 'по умолчанию — прежний набор: дедлайны, до двух экзаменов или посещаемость, плитки пилота', () => {
	assert.deepEqual( defaultTileIds( pilot, { parent: false } ), [ 'deadlines', 'exam:inf_ege', 'exam:rus_ege', 'streak', 'to_fix', 'last_result' ] );
	assert.deepEqual( defaultTileIds( { ...base, exam_countdown: [ exam( 'a' ), exam( 'b' ), exam( 'c' ) ] }, { parent: false } ), [ 'deadlines', 'exam:a', 'exam:b' ] );
} );

test( 'сохранённый набор определяет, что показано и в каком порядке; недоступные плитки (нет данных) пропускаются', () => {
	const tiles = homeTileModels( base, { parent: false }, { tiles: [ 'attendance', 'streak', 'exam:rus_ege', 'deadlines' ] } );

	assert.deepEqual( ids( tiles ), [ 'attendance', 'exam:rus_ege', 'deadlines' ] );
} );

test( 'пустой сохранённый набор — плиток нет (остаётся «+»); null — по умолчанию', () => {
	assert.deepEqual( homeTileModels( base, { parent: false }, { tiles: [] } ), [] );
	assert.deepEqual( ids( homeTileModels( base, { parent: false }, { tiles: null } ) ), [ 'deadlines', 'exam:inf_ege', 'exam:rus_ege' ] );
} );

test( 'родителю «До экзамена» недоступно, посещаемость — всегда', () => {
	assert.deepEqual( ids( addableTiles( base, { parent: true }, [ 'deadlines' ] ) ), [ 'attendance' ] );
} );

test( 'в меню «+» — доступные плитки, которых ещё нет в наборе', () => {
	assert.deepEqual( ids( addableTiles( pilot, { parent: false }, [ 'deadlines', 'streak' ] ) ), [ 'exam:inf_ege', 'exam:rus_ege', 'attendance', 'to_fix', 'last_result' ] );
} );

test( 'добавление и удаление плитки: не больше шести, без повторов', () => {
	assert.deepEqual( addTile( [ 'a' ], 'b' ), [ 'a', 'b' ] );
	assert.deepEqual( addTile( [ 'a' ], 'a' ), [ 'a' ] );
	assert.equal( MAX_TILES, 6 );
	assert.deepEqual( addTile( [ '1', '2', '3', '4', '5', '6' ], '7' ), [ '1', '2', '3', '4', '5', '6' ] );
	assert.deepEqual( removeTile( [ 'a', 'b', 'c' ], 'b' ), [ 'a', 'c' ] );
	assert.deepEqual( removeTile( [ 'a' ], 'zzz' ), [ 'a' ] );
} );

test( 'перестановка секций: перенос на место другой, остальные сдвигаются', () => {
	assert.deepEqual( moveSection( [ 'tiles', 'schedule', 'deadlines' ], 'deadlines', 'tiles' ), [ 'deadlines', 'tiles', 'schedule' ] );
	assert.deepEqual( moveSection( [ 'tiles', 'schedule', 'deadlines' ], 'tiles', 'deadlines' ), [ 'schedule', 'deadlines', 'tiles' ] );
	assert.deepEqual( moveSection( [ 'tiles', 'schedule', 'deadlines' ], 'schedule', 'schedule' ), [ 'tiles', 'schedule', 'deadlines' ] );
	assert.deepEqual( moveSection( [ 'tiles', 'schedule', 'deadlines' ], 'nope', 'tiles' ), [ 'tiles', 'schedule', 'deadlines' ] );
} );

test( 'перестановка плиток тем же способом', () => {
	assert.deepEqual( moveSection( [ 'a', 'b', 'c' ], 'c', 'a' ), [ 'c', 'a', 'b' ] );
} );
