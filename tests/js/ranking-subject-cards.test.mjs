import assert from 'node:assert/strict';
import { test } from 'node:test';

function element( id = '' ) {
    return { id, attrs: {}, props: {}, events: {}, children: [], value: '', options: [], selectedOptions: [] };
}
function wrap( el ) {
    return {
        0: el,
        length: el ? 1 : 0,
        find( selector ) { return wrap( el?.parts?.[ selector ] ); },
        each( callback ) { ( el.items || [] ).forEach( ( item, index ) => callback( index, item ) ); return this; },
        data( key, value ) { el.data ||= {}; if ( arguments.length === 1 ) return el.data[ key ]; el.data[ key ] = value; return this; },
        on( event, selector, callback ) { el.events[ event ] = callback || selector; return this; },
        prop( key, value ) { if ( arguments.length === 1 ) return el.props[ key ]; el.props[ key ] = value; return this; },
        attr( key, value ) { if ( arguments.length === 1 && 'string' === typeof key ) return el.attrs[ key ]; Object.assign( el.attrs, 'object' === typeof key ? key : { [ key ]: value } ); return this; },
        removeAttr( key ) { delete el.attrs[ key ]; return this; },
        val( value ) { if ( arguments.length === 0 ) return el.value; el.value = value; return this; },
        empty() { el.children = []; return this; },
        append( child ) { el.children.push( child[ 0 ] ); return this; },
        text( value ) { el.text = value; return this; },
        trigger() { return this; },
    };
}
let currentForm;
function jquery( value ) {
    if ( value === '#fs-ranking-subjects-form' ) return wrap( currentForm );
    if ( typeof value === 'string' && value.startsWith( '<' ) ) return wrap( element() );
    return wrap( value );
}
globalThis.jQuery = jquery;
globalThis.window = {};
const { RankingSettings } = await import( '../../src/js/admin/services/settings/ranking-settings.js' );

function card( key, enabled, options ) {
    const toggle = element();
    toggle.checked = enabled;
    toggle.setAttribute = ( name, value ) => { toggle.attrs[ name ] = value; };
    toggle.addEventListener = ( name, callback ) => { toggle.events[ name ] = callback; };
    const fields = element( `fs-ranking-${ key }-fields` );
    const search = element( `fs-ranking-${ key }-search` );
    const list = element( `fs-ranking-${ key }-list` );
    list.attrs.id = list.id;
    const select = element();
    select.options = options;
    select.selectedOptions = options.filter( option => option.selected );
    const selected = element();
    const result = element();
    result.dataset = { rankingCard: key };
    result.parts = { '[data-ranking-search]': search, '[data-ranking-list]': list, '[data-ranking-excluded]': select, '.fs-ranking-selected': selected };
    result.querySelector = selector => ( { 'input[type=checkbox]': toggle, '[data-ranking-fields]': fields, '[data-ranking-excluded]': select } )[ selector ];
    result.querySelectorAll = () => [];
    return { node: result, toggle, fields, search, list };
}

test( 'initialization keeps subject toggles independent and uses one search', () => {
    const math = card( 'math', false, [ { value: '1', text: 'Иванов', selected: false } ] );
    const physics = card( 'physics', true, [ { value: '2', text: 'Иванова', selected: false } ] );
    currentForm = element();
    currentForm.parts = { '[data-ranking-card]': { items: [ math.node, physics.node ] }, '#fs-ranking-student-search': math.search, '#fs-ranking-student-list': math.list, '#fs-ranking-excluded': { value: [], options: [ { value: '1', text: 'Иванов', selected: false } ] }, '.fs-ranking-selected': element() };
    RankingSettings.initSubjectCards();
    assert.equal( math.fields.hidden, true );
    assert.equal( math.fields.disabled, true );
    assert.equal( physics.fields.hidden, false );
    assert.equal( math.toggle.attrs[ 'aria-controls' ], math.fields.id );
    assert.notEqual( math.fields.id, physics.fields.id );
    assert.notEqual( math.list.id, physics.list.id );
    math.toggle.checked = true;
    math.toggle.events.change();
    assert.equal( math.fields.hidden, false );
    assert.equal( math.fields.disabled, false );
    assert.equal( physics.fields.hidden, false );
    math.search.value = 'иван';
    math.search.events.input();
    assert.equal( math.list.children.length, 1 );
    assert.equal( math.list.children[ 0 ].children[ 0 ].text, 'Иванов' );
    assert.equal( physics.list.children.length, 0 );
} );
