import test from 'node:test';
import assert from 'node:assert/strict';
import { CourseAccess } from '../../src/js/admin/services/course-access.js';

function fixture( save ) {
	const handlers = {};
	const feedback = { textContent: '', classList: { add() {}, remove() {} } };
	const fields = { disabled: false };
	const toggle = { checked: true, focus() {} };
	const submit = { addEventListener( name, fn ) { handlers.save = fn; } };
	const panel = {
		hidden: true,
		querySelector( selector ) {
			return { '[data-access-fields]': fields, '[data-access-feedback]': feedback,
				'[data-access-enabled]': toggle, '[data-access-save]': submit }[ selector ];
		},
		querySelectorAll() { return []; },
		addEventListener() {},
	};
	const button = { addEventListener( name, fn ) { handlers.toggle = fn; }, setAttribute() {}, focus() {} };
	const mount = { querySelector: ( selector ) => selector === '[data-strip-access]' ? button : panel };
	const state = { course: { self_paced_enabled: false, audience_subject_keys: [ 'old' ], service_group_id: 12 } };
	CourseAccess.bind( mount, { state, save } );
	return { handlers, feedback, fields, panel, state };
}

test( 'empty audience is an explicit JSON patch, not a dropped form array', () => {
	const panel = { querySelector: () => ( { checked: false } ), querySelectorAll: () => [] };
	assert.deepEqual( CourseAccess.patch( panel ), { self_paced_enabled: '0', audience_subject_keys: '[]' } );
} );

test( 'failed save keeps persisted settings and restores controls for retry', async () => {
	const f = fixture( async () => { throw 'Контент требует ручной проверки'; } );
	await f.handlers.save();
	assert.equal( f.state.course.self_paced_enabled, false );
	assert.deepEqual( f.state.course.audience_subject_keys, [ 'old' ] );
	assert.equal( f.feedback.textContent, 'Контент требует ручной проверки' );
	assert.equal( f.fields.disabled, false );
} );

test( 'success commits patch only after response and preserves service group', async () => {
	let resolve;
	const f = fixture( () => new Promise( ( done ) => { resolve = done; } ) );
	const saving = f.handlers.save();
	assert.equal( f.fields.disabled, true );
	assert.equal( f.state.course.self_paced_enabled, false );
	resolve();
	await saving;
	assert.equal( f.state.course.self_paced_enabled, true );
	assert.deepEqual( f.state.course.audience_subject_keys, [] );
	assert.equal( f.state.course.service_group_id, 12 );
	assert.equal( f.fields.disabled, false );
} );

test( 'opening panel performs no save; markup escapes names and keys', () => {
	let saved = false;
	const f = fixture( () => { saved = true; } );
	f.handlers.toggle();
	assert.equal( f.panel.hidden, false );
	assert.equal( saved, false );
	const html = CourseAccess.markup( {}, [ { key: '\"><bad>', name: '<script>' } ] );
	assert.ok( ! html.includes( '<script>' ) );
	assert.ok( ! html.includes( '<bad>' ) );
} );
