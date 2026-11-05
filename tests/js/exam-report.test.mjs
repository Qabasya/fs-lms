import assert from 'node:assert/strict';
import test from 'node:test';

import { rowUrlFromClick } from '../../src/js/frontend/components/exam-report.js';

/** Минимальный стуб элемента: closest идёт по цепочке родителей. */
function el( tag, { parent = null, attr = {}, isRow = false, isLink = false } = {} ) {
    const node = {
        tag,
        parent,
        attr,
        getAttribute: ( name ) => attr[ name ] ?? null,
        closest( selector ) {
            if ( 'a' === selector && isLink ) { return node; }
            if ( 'tr[data-row-url]' === selector && isRow ) { return node; }
            return parent ? parent.closest( selector ) : null;
        },
    };
    return node;
}

const table = { contains: ( node ) => Boolean( node ) };

test('клик по ячейке открытой строки ведёт на адрес строки', () => {
    const row = el( 'tr', { attr: { 'data-row-url': 'https://x.test/report/?row=2#review' }, isRow: true } );
    const td  = el( 'td', { parent: row } );

    assert.equal( rowUrlFromClick( td, table ), 'https://x.test/report/?row=2#review' );
});

test('клик по ссылке внутри строки работает как ссылка', () => {
    const row = el( 'tr', { attr: { 'data-row-url': 'x' }, isRow: true } );
    const a   = el( 'a', { parent: row, isLink: true } );

    assert.equal( rowUrlFromClick( a, table ), null );
});

test('клик вне открываемой строки (заглушка) ничего не открывает', () => {
    const tr = el( 'tr' );

    assert.equal( rowUrlFromClick( tr, table ), null );
});

test('строка из другой таблицы не открывается', () => {
    const row = el( 'tr', { attr: { 'data-row-url': 'x' }, isRow: true } );
    const foreignTable = { contains: () => false };

    assert.equal( rowUrlFromClick( row, foreignTable ), null );
});
