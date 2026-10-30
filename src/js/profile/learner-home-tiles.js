/**
 * Плитки главной ученика/родителя как данные (без разметки).
 *
 *  - «Дедлайнов» — всегда;
 *  - «До экзамена · предмет» (до двух) — ученику вместо «Посещаемости», пока для предмета его групп задана дата экзамена;
 *    дальше недели в днях, в последние 7 суток — в часах и красным;
 *  - «Посещаемость» — когда у ученика нет экзамена с датой, а родителю — всегда;
 *  - пилот на тестовом аккаунте: «Серия без пропусков», «Осталось исправить», «Последний результат» (серия и «осталось исправить» — всегда, в том числе нули; «Последний результат» — когда он есть).
 */

import { pluralRu } from '../common/plural.js';

const MAX_EXAM_TILES = 2;
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
 * @param {{deadlines: Array, attendance: {percent: number|null}, exam_countdown?: Array, highlights?: object|null}} d Ответ кабинета
 * @param {{parent: boolean}} who
 * @returns {Array<{label: string, value: string, color: string, ico: string, href?: string, urgent?: boolean, note?: string}>}
 */
export function homeTileModels( d, { parent } ) {
    const last = ( d.deadlines || [] ).filter( ( x ) => x.player_url )
        .reduce( ( best, x ) => ( !best || String( x.due_at ) > String( best.due_at ) ? x : best ), null );
    const tiles = [ { label: 'Дедлайнов', value: String( d.deadlines.length ), color: '#f08c00', ico: 'alert', href: last?.player_url } ];

    const exams = parent ? [] : ( d.exam_countdown || [] ).slice( 0, MAX_EXAM_TILES );
    if ( exams.length ) {
        exams.forEach( ( item ) => tiles.push( {
            label: `До экзамена · ${ glueLastWord( item.subject ) }`,
            value: examCountdownValue( item ),
            color: item.urgent ? '#e03131' : '#1c7ed6',
            ico: 'cal',
            urgent: item.urgent,
        } ) );
    } else {
        tiles.push( { label: 'Посещаемость', value: d.attendance.percent === null ? '—' : `${ d.attendance.percent }%`, color: '#2f9e44', ico: 'check' } );
    }

    const h = d.highlights;
    if ( h ) { tiles.push( { label: 'Серия без пропусков', value: String( h.streak ), color: '#2f9e44', ico: 'check', note: 'занятий подряд' } ); }
    if ( h ) { tiles.push( { label: 'Осталось исправить', value: String( h.to_fix ), color: '#f08c00', ico: 'alert', note: 'заданий в сданных работах' } ); }
    if ( h && h.last_result ) { tiles.push( { label: 'Последний результат', value: h.last_result.value, color: '#7048e8', ico: 'check', note: h.last_result.note } ); }

    return tiles;
}
