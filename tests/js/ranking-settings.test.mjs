import assert from 'node:assert/strict';
import { test } from 'node:test';

let request;
globalThis.window = {};
globalThis.jQuery = {
    post( url, payload ) {
        request = { url, payload, callbacks: {} };
        const chain = {};
        [ 'done', 'fail', 'always' ].forEach( name => {
            chain[ name ] = callback => { request.callbacks[ name ] = callback; return chain; };
        } );
        return chain;
    },
};
globalThis.fs_lms_vars = {
    ajaxurl: '/wp-admin/admin-ajax.php',
    ajax_actions: { saveRankingSettings: 'save_ranking_settings' },
    nonces: { config: 'settings-nonce' },
};
const { RankingSettings, matchingStudents, syncRankingFields } = await import( '../../src/js/admin/services/settings/ranking-settings.js' );

test( 'subject toggle hides and disables only its own fields', () => {
    const math = {};
    const physics = {};
    syncRankingFields( math, false );
    syncRankingFields( physics, true );
    assert.deepEqual( math, { hidden: true, disabled: true } );
    assert.deepEqual( physics, { hidden: false, disabled: false } );
    syncRankingFields( math, true );
    assert.deepEqual( math, { hidden: false, disabled: false } );
} );

function form( valid = true ) {
    const state = { text: '', classes: '', disabled: false, data: {}, attrs: {} };
    const status = {
        text( value ) { state.text = value; return this; },
        removeClass() { state.classes = ''; return this; },
        addClass( value ) { state.classes = value; return this; },
    };
    const fields = [
        { name: 'practice', value: '0' },
        { name: 'independent', value: '1' },
        { name: 'attendance_bonus', value: '2' },
        { name: 'attendance_streak_length', value: '3' },
    ];
    const result = {
        0: { reportValidity: () => valid },
        state,
        data( key, value ) { if ( arguments.length === 1 ) return state.data[ key ]; state.data[ key ] = value; return this; },
        attr( key, value ) { state.attrs[ key ] = value; return this; },
        removeAttr( key ) { delete state.attrs[ key ]; return this; },
        find( selector ) {
            if ( selector === '.js-ranking-status' ) return status;
            if ( selector === 'button' ) return { prop( key, value ) { state[ key ] = value; } };
            if ( selector === '[data-ranking-subject]' ) return { each( callback ) {
                [ { dataset: { rankingSubject: 'math' }, querySelector: () => ( { checked: false } ) },
                    { dataset: { rankingSubject: 'python' }, querySelector: () => ( { checked: true } ) } ].forEach( ( row, i ) => callback( i, row ) );
            } };
            if ( selector === '#fs-ranking-excluded' ) return { val: () => [ '4', '7' ] };
            assert.equal( selector, '[data-ranking-field]' );
            return { each( callback ) { fields.forEach( ( field, index ) => callback( index, field ) ); } };
        },
    };
    return result;
}

test( 'invalid numeric form does not send a request', () => {
    request = null;
    RankingSettings.save( form( false ) );
    assert.equal( request, null );
} );

test( 'save sends only ranking fields with config nonce and prevents overlapping submissions', () => {
    const $form = form();
    RankingSettings.save( $form );
    const firstRequest = request;
    assert.equal( request.url, '/wp-admin/admin-ajax.php' );
    assert.deepEqual( request.payload, {
        action: 'save_ranking_settings', security: 'settings-nonce',
        settings: { practice: '0', independent: '1', attendance_bonus: '2', attendance_streak_length: '3', hidden_subjects: 'math', excluded_students: '4,7' },
    } );
    assert.equal( $form.state.disabled, true );
    assert.equal( $form.state.attrs[ 'aria-busy' ], 'true' );
    RankingSettings.save( $form );
    assert.equal( request, firstRequest );
    request.callbacks.done( { success: true } );
    request.callbacks.always();
    assert.equal( $form.state.text, 'Сохранено.' );
    assert.equal( $form.state.classes, 'fs-config-status--ok' );
    assert.equal( $form.state.disabled, false );
    assert.equal( $form.state.data.saving, false );
    assert.equal( $form.state.attrs[ 'aria-busy' ], undefined );
} );

test( 'HTTP validation or permission failures show the server message and allow retry', () => {
    const $form = form();
    RankingSettings.save( $form );
    request.callbacks.fail( { responseJSON: { success: false, data: { message: 'Нет права сохранять настройки.' } } } );
    request.callbacks.always();
    assert.equal( $form.state.text, 'Нет права сохранять настройки.' );
    assert.equal( $form.state.classes, 'fs-config-status--err' );
    assert.equal( $form.state.disabled, false );
    assert.equal( $form.state.data.saving, false );
} );

test( 'network and JSON error responses have readable inline statuses', () => {
    const $form = form();
    RankingSettings.save( $form );
    request.callbacks.done( { success: false, data: 'Вес должен быть неотрицательным.' } );
    assert.equal( $form.state.text, 'Вес должен быть неотрицательным.' );
    request.callbacks.fail( {} );
    assert.equal( $form.state.text, 'Ошибка сети. Повторите сохранение.' );
    request.callbacks.always();
} );


test( 'student search finds unselected names without changing existing selections', () => {
    const options = [
        { value: '1', text: 'Иванов Иван (ID 1)', selected: true },
        { value: '2', text: 'Иванова Анна (ID 2)', selected: false },
        { value: '3', text: 'Петров Иван (ID 3)', selected: false },
    ];
    assert.deepEqual( matchingStudents( options, ' ИВАН ' ).map( item => item.value ), [ '2', '3' ] );
    assert.deepEqual( matchingStudents( options, 'и' ), [] );
    assert.equal( options[ 0 ].selected, true );
} );

test( 'subject save keeps edits made during a request and hidden unsent edits dirty', () => {
    const toggle = { checked: true };
    const field = { name: 'practice', value: '2', dataset: { original: '1' } };
    const select = { selectedOptions: [] };
    const card = {
        dataset: { rankingCard: 'math', enabledOriginal: '1', excludedOriginal: '' },
        querySelector( selector ) { return selector === 'input[type=checkbox]' ? toggle : select; },
        querySelectorAll() { return [ field ]; },
    };
    const state = { data: { excludedOriginal: '' } };
    const status = { text() { return this; }, removeClass() { return this; }, addClass() { return this; } };
    const $form = {
        0: { reportValidity: () => true },
        data( key, value ) { if ( arguments.length === 1 ) return state.data[ key ]; state.data[ key ] = value; return this; },
        attr() { return this; }, removeAttr() { return this; },
        find( selector ) {
            if ( selector === '[data-ranking-card]' ) return { each( callback ) { callback( 0, card ); } };
            if ( selector === '#fs-ranking-excluded' ) return { val: () => [] };
            if ( selector === '.js-ranking-status' ) return status;
            if ( selector === 'button' ) return { prop() {} };
            throw new Error( selector );
        },
    };
    RankingSettings.saveSubjects( $form );
    assert.deepEqual( request.payload.subjects, { math: { practice: '2' } } );
    field.value = '3';
    request.callbacks.done( { success: true } );
    request.callbacks.always();
    assert.equal( field.dataset.original, '2' );
    toggle.checked = false;
    RankingSettings.saveSubjects( $form );
    assert.deepEqual( request.payload.subjects, { math: { enabled: '0' } } );
    request.callbacks.done( { success: true } );
    request.callbacks.always();
    assert.equal( field.dataset.original, '2' );
    toggle.checked = true;
    RankingSettings.saveSubjects( $form );
    assert.deepEqual( request.payload.subjects, { math: { enabled: '1', practice: '3' } } );
} );
