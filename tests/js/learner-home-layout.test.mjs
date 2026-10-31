import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    addTile, addableTiles, defaultTileIds, homeTileModels, moveSection, removeTile, replaceTile, shiftItem, tileCatalog, MAX_TILES,
} from '../../src/js/profile/learner-home-tiles.js';

const deadlines = [ { player_url: '/lesson/1', due_at: '2027-05-02 10:00:00' } ];
const exam = ( key, over = {} ) => ( { subject_key: key, subject: `Предмет ${ key }`, days: 30, hours: 720, urgent: false, ...over } );
const base = { home_subjects: [{ key: 'inf_ege', exam: true }, { key: 'rus_ege', exam: true }], deadlines, attendance: { percent: 90 }, exam_countdown: [ exam( 'inf_ege' ), exam( 'rus_ege' ) ], highlights: null };
const pilot = { ...base, highlights: { streak: 4, to_fix: 2, last_result: { value: '72', note: 'Пробник' } } };
const ids = ( tiles ) => tiles.map( ( t ) => t.id );

test( 'у плиток есть стабильные id: дедлайны, экзамен по предмету, посещаемость, пилотные', () => {
	assert.deepEqual( ids( tileCatalog( pilot, { parent: false } ) ), [ 'deadlines', 'exam:inf_ege', 'exam:rus_ege', 'attendance', 'streak', 'to_fix', 'last_result' ] );
	assert.deepEqual( ids( homeTileModels( base, { parent: true } ) ), [ 'deadlines', 'attendance' ] );
	assert.deepEqual( ids( homeTileModels( { ...base, exam_countdown: [] }, { parent: false } ) ), [ 'deadlines' ] );
} );

test( 'по умолчанию — дедлайны и доступные счётчики предметов; без даты нет фиктивной плитки', () => {
	assert.deepEqual( defaultTileIds( pilot, { parent: false } ), [ 'deadlines', 'exam:inf_ege', 'exam:rus_ege' ] );
	assert.deepEqual( defaultTileIds( { ...pilot, exam_countdown: [] }, { parent: false } ), [ 'deadlines' ] );
	assert.deepEqual( defaultTileIds( { ...base, home_subjects: ['a', 'b', 'c'].map(key => ({key, exam: true})), exam_countdown: [ exam( 'a' ), exam( 'b' ), exam( 'c' ) ] }, { parent: false } ), [ 'deadlines', 'exam:a', 'exam:b', 'exam:c' ] );
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

test( 'стрелки на телефоне: сдвиг на одно место вверх/вниз; на краях и для неизвестного id ничего не меняется', () => {
	assert.deepEqual( shiftItem( [ 'a', 'b', 'c' ], 'b', -1 ), [ 'b', 'a', 'c' ] );
	assert.deepEqual( shiftItem( [ 'a', 'b', 'c' ], 'b', 1 ), [ 'a', 'c', 'b' ] );
	assert.deepEqual( shiftItem( [ 'a', 'b', 'c' ], 'a', -1 ), [ 'a', 'b', 'c' ] );
	assert.deepEqual( shiftItem( [ 'a', 'b', 'c' ], 'c', 1 ), [ 'a', 'b', 'c' ] );
	assert.deepEqual( shiftItem( [ 'a', 'b', 'c' ], 'zzz', 1 ), [ 'a', 'b', 'c' ] );
} );


test( 'замена сохраняет позицию и количество плиток, не допускает дублей', () => {
    assert.deepEqual( replaceTile( ['a', 'b', 'c'], 'b', 'd' ), ['a', 'd', 'c'] );
    assert.deepEqual( replaceTile( ['a', 'b'], 'a', 'b' ), ['a', 'b'] );
    assert.deepEqual( replaceTile( ['a'], 'missing', 'd' ), ['a'] );
} );

test( 'рейтинг выбранного ребёнка доступен родителю и ученику через каталог, но не входит в defaults; летом скрывается', () => {
    const data = { ...base, rankings: [{ subject_key: 'python', subject: 'Python', rank: 2, score: 89, period: 'Сентябрь — ноябрь 2026' }] };
    for ( const parent of [true, false] ) {
        const ranking = tileCatalog( data, { parent } ).find(t => t.id === 'ranking:python');
        assert.equal( ranking.value, '2 место' );
        assert.equal( ranking.ico, 'star' );
        assert.match( ranking.note, /^89 баллов/ );
        assert.equal( defaultTileIds( data, { parent } ).includes('ranking:python'), false );
        assert.deepEqual( homeTileModels( { ...data, rankings: [] }, { parent }, { tiles: ['ranking:python'] } ), [] );
    }
} );


const rank = key => ({subject_key: key, subject: key, rank: 1, score: 12, period: 'Осень'});
const subject = (key, exam = false) => ({key, exam});

test( 'обычный ученик Python получает дедлайны, исправления и рейтинг; родителю оставлены прежние defaults', () => {
    const data = { ...base, home_subjects: [subject('python')], rankings: [rank('python')], to_fix: 8 };
    assert.deepEqual(defaultTileIds(data, {parent: false}), ['deadlines', 'to_fix', 'ranking:python']);
    assert.equal(tileCatalog(data, {parent: false}).find(t => t.id === 'to_fix').value, '8');
    assert.deepEqual(defaultTileIds(data, {parent: true}), ['deadlines', 'attendance']);
} );

test( 'смешанные предметы дают счётчик, исправления и рейтинги в стабильном порядке всех предметов без дублей', () => {
    const data = { ...base, home_subjects: [subject('inf_ege', true), subject('python'), subject('python')], rankings: [rank('inf_ege'), rank('python')] };
    assert.deepEqual(defaultTileIds(data, {parent: false}), ['deadlines', 'exam:inf_ege', 'to_fix', 'ranking:inf_ege', 'ranking:python']);
    assert.deepEqual(ids(homeTileModels(data, {parent: false}, {tiles: ['attendance', 'ranking:python']})), ['attendance', 'ranking:python']);
} );

test( 'лимит шести сохраняет все основные виды, не использует неподдерживаемый счётчик и скрывает летние рейтинги', () => {
    const keys = ['a', 'b', 'c', 'd', 'e', 'f'];
    const data = { ...base, home_subjects: [...keys.map(key => subject(key, true)), subject('python')], exam_countdown: [...keys.map(key => exam(key)), exam('python')], rankings: [...keys, 'python'].map(rank) };
    const defaults = defaultTileIds(data, {parent: false});
    assert.equal(defaults.length, 6);
    assert.deepEqual(defaults.slice(0, 4), ['deadlines', 'exam:a', 'to_fix', 'ranking:a']);
    assert.equal(defaults.includes('exam:python'), false);
    assert.deepEqual(defaultTileIds({...data, rankings: [], exam_countdown: []}, {parent: false}), ['deadlines', 'to_fix']);
} );
