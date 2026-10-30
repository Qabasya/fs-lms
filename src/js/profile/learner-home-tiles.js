/**
 * Плитки главной ученика/родителя как данные (без разметки).
 *
 *  - «Дедлайнов» — всегда;
 *  - «До экзамена · предмет» (до двух) — ученику вместо «Посещаемости», пока для предмета его групп задана дата экзамена;
 *    дальше недели в днях, в последние 7 суток — в часах и красным;
 *  - «Посещаемость» — когда у ученика нет экзамена с датой, а родителю — всегда;
 *  - «Кастомизация»: пользователь сам выбирает набор и порядок плиток (`layout.tiles`, до {@link MAX_TILES}); `null` — набор по умолчанию;
 *  - плитки пилота на тестовом аккаунте (в набор по умолчанию не входят, добавляются через «+»): «Серия без пропусков», «Осталось исправить», «Последний результат» (серия и «осталось исправить» — всегда, в том числе нули; «Последний результат» — когда он есть).
 */

import { pluralRu } from '../common/plural.js';

const MAX_EXAM_TILES = 2;

/** Не больше плиток в ряду главной. */
export const MAX_TILES = 6;
const NBSP = '\u00A0';

/** Склеивает последнее слово с предыдущим неразрывным пробелом: «Информатика ЕГЭ» при нехватке места переносится целиком. */
function glueLastWord( text ) {
    return String( text ).replace( /\s+(\S+)$/, `${ NBSP }$1` );
}

/** «32 дня» / «1 день» / «5 дней» дальше недели, «22 ч» в последние 7 суток. */
export function examCountdownValue( item ) {
    return item.urgent ? `${ item.hours } ч` : `${ item.days } ${ pluralRu( item.days, 'день', 'дня', 'дней' ) }`;
}

/**
 * Все плитки, которые у пользователя есть чем наполнить (в каноническом порядке), с id: `deadlines`, `exam:{предмет}`,
 * `attendance`, `streak`, `to_fix`, `last_result`. Родителю «До экзамена» недоступно.
 *
 * @param {{deadlines: Array, attendance: {percent: number|null}, exam_countdown?: Array, highlights?: object|null}} d Ответ кабинета
 * @param {{parent: boolean}} who
 * @returns {Array<{id: string, label: string, value: string, color: string, ico: string, href?: string, urgent?: boolean, note?: string}>}
 */
export function tileCatalog( d, { parent } ) {
    const last = ( d.deadlines || [] ).filter( ( x ) => x.player_url )
        .reduce( ( best, x ) => ( !best || String( x.due_at ) > String( best.due_at ) ? x : best ), null );
    const tiles = [ { id: 'deadlines', label: 'Дедлайнов', value: String( d.deadlines.length ), color: '#f08c00', ico: 'alert', href: last?.player_url } ];

    ( parent ? [] : ( d.exam_countdown || [] ) ).forEach( ( item ) => tiles.push( {
        id: `exam:${ item.subject_key }`,
        label: `До экзамена · ${ glueLastWord( item.subject ) }`,
        value: examCountdownValue( item ),
        color: item.urgent ? '#e03131' : '#1c7ed6',
        ico: 'cal',
        urgent: item.urgent,
    } ) );

    tiles.push( { id: 'attendance', label: 'Посещаемость', value: d.attendance.percent === null ? '—' : `${ d.attendance.percent }%`, color: '#2f9e44', ico: 'check' } );

    const h = d.highlights;
    if ( h ) {
        tiles.push( { id: 'streak', label: 'Серия без пропусков', value: String( h.streak ), color: '#2f9e44', ico: 'check', note: 'занятий подряд' } );
        tiles.push( { id: 'to_fix', label: 'Осталось исправить', value: String( h.to_fix ), color: '#f08c00', ico: 'alert', note: 'заданий в сданных работах' } );
    }
    if ( h && h.last_result ) {
        tiles.push( { id: 'last_result', label: 'Последний результат', value: h.last_result.value, color: '#7048e8', ico: 'check', note: h.last_result.note } );
    }

    return tiles;
}

/**
 * Набор плиток по умолчанию (id) — тот, что был у пользователей всегда: «Дедлайнов» и до двух «До экзамена»
 * (иначе «Посещаемость»; родителю всегда она). Новые плитки (серия, осталось исправить, последний результат) в набор по
 * умолчанию не входят: их добавляют только через «Кастомизацию» («+»).
 */
export function defaultTileIds( d, who ) {
    const catalog = tileCatalog( d, who );
    const exams   = catalog.filter( ( t ) => t.id.startsWith( 'exam:' ) ).slice( 0, MAX_EXAM_TILES );
    const middle  = exams.length ? exams : catalog.filter( ( t ) => 'attendance' === t.id );

    return [ catalog[ 0 ], ...middle ].map( ( t ) => t.id );
}

/**
 * Плитки главной в порядке показа. `layout.tiles` — выбор пользователя (недоступные сейчас плитки пропускаются);
 * `null`/нет раскладки — набор по умолчанию; пустой список — плиток нет.
 *
 * @param {object} d
 * @param {{parent: boolean}} who
 * @param {{tiles?: string[]|null}|null} [layout]
 */
export function homeTileModels( d, who, layout = null ) {
    const catalog = tileCatalog( d, who );
    const chosen  = Array.isArray( layout?.tiles ) ? layout.tiles : defaultTileIds( d, who );

    return chosen.map( ( id ) => catalog.find( ( t ) => t.id === id ) ).filter( Boolean ).slice( 0, MAX_TILES );
}

/** Плитки, которые можно добавить в «+»: доступные и ещё не стоящие в наборе. */
export function addableTiles( d, who, selectedIds ) {
    return tileCatalog( d, who ).filter( ( t ) => !selectedIds.includes( t.id ) );
}

/** Добавляет плитку в конец набора: без повторов и не больше {@link MAX_TILES}. */
export function addTile( ids, id ) {
    return ids.includes( id ) || ids.length >= MAX_TILES ? [ ...ids ] : [ ...ids, id ];
}

export function removeTile( ids, id ) {
    return ids.filter( ( x ) => x !== id );
}

/**
 * Перенос элемента списка на место другого (перетаскивание в режиме «Кастомизация»): остальные сдвигаются.
 * Работает и для секций, и для плиток. Неизвестные id список не меняют.
 */
export function moveSection( list, fromId, toId ) {
    const from = list.indexOf( fromId );
    const to   = list.indexOf( toId );
    if ( from < 0 || to < 0 || from === to ) { return [ ...list ]; }

    const next = [ ...list ];
    next.splice( to, 0, next.splice( from, 1 )[ 0 ] );

    return next;
}

/** Сдвиг элемента на одно место (стрелки «выше / ниже» на телефоне); на краях и для неизвестного id список не меняется. */
export function shiftItem( list, id, delta ) {
    const from = list.indexOf( id );
    const to   = from + delta;
    if ( from < 0 || to < 0 || to >= list.length ) { return [ ...list ]; }

    const next = [ ...list ];
    next.splice( to, 0, next.splice( from, 1 )[ 0 ] );

    return next;
}
