/**
 * Interactive task widgets for the course player (Этап 6, Phase E; Эпик 14 T14.7).
 * Pure-JS, function pattern. No jQuery, no external deps.
 *
 * initTaskWidget(panel) reads .fs-task-widget data attributes, renders the
 * correct input widget and returns the widget API:
 *   collectAnswer() → JSON string — ответ для SubmitTaskAnswer (контракт чекеров, НЕ менять);
 *   setAnswer(value)              — восстановить ответ (черновики/повторная сдача работы);
 *   hasAnswer()     → bool        — есть ли что отправлять («Ответить» disabled без выбора);
 *   onChange(cb)                  — подписка на изменение ввода;
 *   applyVerdict(ok)              — пометить выбор ok/no после проверки (choice);
 *   resetAttempt()                — сброс выбора для «Попробовать ещё раз»;
 *   reveal(correct)               — подсветить эталон после исчерпания попыток (D20);
 *   lock()                        — задизейблить ввод.
 */

import { icoCheck, icoCross } from '../../common/icons.js';

const CHECK_SVG = icoCheck( 13 );
const CROSS_SVG = icoCross( 12 );

/**
 * @param {HTMLElement} panel  — панель шага с .fs-task-widget внутри
 * @returns {object|null} widget API (см. шапку файла)
 */
export function initTaskWidget( panel ) {
	const container = panel.querySelector( '.fs-task-widget' );
	if ( ! container ) { return null; }

	const widgetData = parseWidgetData( container.dataset.widget );
	const isDone     = !! container.dataset.done;

	switch ( widgetData.type ) {
		case 'text_answer': return buildTextAnswerWidget( container, isDone );
		case 'audio':       return buildAudioWidget( container, widgetData, isDone );
		case 'triple':      return buildTripleWidget( container, isDone );
		case 'choice':      return buildChoiceWidget( container, widgetData, isDone );
		case 'matching':    return buildMatchingWidget( container, widgetData, isDone );
		case 'ordering':    return buildOrderingWidget( container, widgetData, isDone );
		case 'fill':        return buildFillWidget( container, widgetData, isDone );
		default:            return null;
	}
}

function parseWidgetData( raw ) {
	try { return JSON.parse( raw || '{}' ); } catch { return {}; }
}

/** Базовый API виджета: билдеры переопределяют только то, что умеют. */
function widgetApi( overrides ) {
	return Object.assign(
		{
			setAnswer: () => {},
			hasAnswer: () => true,
			onChange: () => {},
			applyVerdict: () => {},
			resetAttempt: () => {},
			reveal: () => {},
			lock: () => {},
		},
		overrides
	);
}

/** Текстовые контролы: общий API поверх списка инпутов/textarea. */
function inputsApi( inputs, collectAnswer, hasAnswer ) {
	return widgetApi( {
		collectAnswer,
		hasAnswer,
		onChange: ( cb ) => inputs.forEach( ( el ) => el.addEventListener( 'input', cb ) ),
		lock: () => inputs.forEach( ( el ) => { el.disabled = true; el.classList.add( 'lock' ); } ),
	} );
}

// ── Text answer (Standard / Common) ───────────────────────────────────────

function buildTextAnswerWidget( container, isDone ) {
	const textarea = make( 'textarea', 'fs-widget-text ansbox txt' );
	textarea.rows        = 4;
	textarea.placeholder = 'Введите ответ…';
	if ( isDone ) { textarea.disabled = true; }
	container.appendChild( textarea );

	return Object.assign(
		inputsApi(
			[ textarea ],
			() => JSON.stringify( textarea.value.trim() ),
			() => '' !== textarea.value.trim()
		),
		{ setAnswer: ( v ) => { textarea.value = 'string' === typeof v ? v : ''; } }
	);
}

// ── Audio + text answer ────────────────────────────────────────────────────

function buildAudioWidget( container, data, isDone ) {
	if ( data.audio_url ) {
		const audio    = make( 'audio', 'fs-widget-audio' );
		audio.controls = true;
		audio.src      = data.audio_url;
		container.appendChild( audio );
	}

	const textarea       = make( 'textarea', 'fs-widget-text ansbox txt' );
	textarea.rows        = 3;
	textarea.placeholder = 'Введите ответ…';
	if ( isDone ) { textarea.disabled = true; }
	container.appendChild( textarea );

	return Object.assign(
		inputsApi(
			[ textarea ],
			() => JSON.stringify( textarea.value.trim() ),
			() => '' !== textarea.value.trim()
		),
		{ setAnswer: ( v ) => { textarea.value = 'string' === typeof v ? v : ''; } }
	);
}

// ── Triple (три отдельных инпута: задания 19, 20, 21) ──────────────────────

function buildTripleWidget( container, isDone ) {
	const keys   = [ '19', '20', '21' ];
	const inputs = {};

	keys.forEach( key => {
		const group       = make( 'div', 'fs-widget-triple-group' );
		const input       = make( 'input', 'fs-widget-triple-input ansbox txt' );
		input.type        = 'text';
		input.placeholder = `Ответ на задание ${ key }…`;
		if ( isDone ) { input.disabled = true; }
		inputs[ key ] = input;
		group.appendChild( input );
		container.appendChild( group );
	} );

	return Object.assign(
		inputsApi(
			Object.values( inputs ),
			() => JSON.stringify( {
				'19': inputs[ '19' ].value.trim(),
				'20': inputs[ '20' ].value.trim(),
				'21': inputs[ '21' ].value.trim(),
			} ),
			() => Object.values( inputs ).some( ( el ) => '' !== el.value.trim() )
		),
		{ setAnswer: ( v ) => keys.forEach( ( key ) => { inputs[ key ].value = String( v?.[ key ] ?? '' ); } ) }
	);
}

// ── Choice (radio / checkbox) — opt-строки по дизайну плеера ───────────────

function buildChoiceWidget( container, data, isDone ) {
	const multiple = !! data.multiple;
	const options  = Array.isArray( data.options ) ? data.options : [];
	const type     = multiple ? 'checkbox' : 'radio';
	const name     = `fs-choice-${ Date.now() }`;

	const list = make( 'div', 'fs-widget-choice-list opt-list' );

	options.forEach( opt => {
		const row     = make( 'label', 'opt' );
		const input   = make( 'input', 'opt-input' );
		const radio   = make( 'span', 'radio' );
		const body    = make( 'span', 'opt-body' );

		input.type    = type;
		input.name    = name;
		input.value   = opt.id;
		input.hidden  = true;
		if ( isDone ) { input.disabled = true; row.classList.add( 'dis' ); }

		body.textContent = opt.text;

		row.appendChild( input );
		row.appendChild( radio );
		row.appendChild( body );
		list.appendChild( row );
	} );

	const syncSel = () => {
		list.querySelectorAll( '.opt' ).forEach( ( row ) => {
			row.classList.toggle( 'sel', row.querySelector( 'input' ).checked );
		} );
	};
	list.addEventListener( 'change', syncSel );

	container.appendChild( list );

	const rows       = () => Array.from( list.querySelectorAll( '.opt' ) );
	const addTail    = ( row, ok, label ) => {
		row.querySelector( '.tail' )?.remove();
		const tail     = make( 'span', `tail ${ ok ? 't-ok' : 't-no' }` );
		tail.innerHTML = `${ ok ? CHECK_SVG : CROSS_SVG }<span>${ label }</span>`;
		row.appendChild( tail );
	};
	const lock = () => rows().forEach( ( row ) => {
		row.querySelector( 'input' ).disabled = true;
		row.classList.add( 'dis' );
	} );

	return widgetApi( {
		collectAnswer: () => JSON.stringify(
			Array.from( list.querySelectorAll( 'input:checked' ) ).map( el => el.value )
		),
		setAnswer: ( v ) => {
			const ids = ( Array.isArray( v ) ? v : [] ).map( String );
			rows().forEach( ( row ) => {
				const input   = row.querySelector( 'input' );
				input.checked = ids.includes( String( input.value ) );
			} );
			syncSel();
		},
		hasAnswer: () => !! list.querySelector( 'input:checked' ),
		onChange: ( cb ) => list.addEventListener( 'change', cb ),
		applyVerdict: ( ok ) => rows().forEach( ( row ) => {
			if ( ! row.querySelector( 'input' ).checked ) { return; }
			row.classList.remove( 'sel' );
			row.classList.add( ok ? 'ok' : 'no' );
			addTail( row, ok, 'Ваш ответ' );
		} ),
		resetAttempt: () => rows().forEach( ( row ) => {
			const input   = row.querySelector( 'input' );
			input.checked  = false;
			input.disabled = false;
			row.classList.remove( 'sel', 'ok', 'no', 'dis' );
			row.querySelector( '.tail' )?.remove();
		} ),
		reveal: ( correct ) => {
			const ids = ( Array.isArray( correct ) ? correct : [ correct ] ).map( String );
			rows().forEach( ( row ) => {
				const input = row.querySelector( 'input' );
				if ( ids.includes( String( input.value ) ) && ! input.checked ) {
					row.classList.add( 'ok' );
					addTail( row, true, 'Правильный ответ' );
				}
			} );
			lock();
		},
		lock,
	} );
}

// ── Matching (select dropdowns) ────────────────────────────────────────────

function buildMatchingWidget( container, data, isDone ) {
	const lefts  = Array.isArray( data.lefts )  ? data.lefts  : [];
	const rights = Array.isArray( data.rights ) ? data.rights : [];

	const wrapper  = make( 'div', 'fs-widget-matching' );
	const selects  = [];

	lefts.forEach( left => {
		const row     = make( 'div', 'fs-widget-matching-row' );
		const leftEl  = make( 'span', 'fs-widget-matching-left' );
		const select  = make( 'select', 'fs-widget-matching-select' );

		leftEl.textContent = left.text;
		if ( isDone ) { select.disabled = true; }

		const ph         = document.createElement( 'option' );
		ph.value         = '';
		ph.textContent   = '— выбрать —';
		ph.disabled      = true;
		ph.selected      = true;
		select.appendChild( ph );

		rights.forEach( rightText => {
			const opt       = document.createElement( 'option' );
			opt.value       = rightText;
			opt.textContent = rightText;
			select.appendChild( opt );
		} );

		selects.push( { leftText: left.text, select } );
		row.appendChild( leftEl );
		row.appendChild( select );
		wrapper.appendChild( row );
	} );

	container.appendChild( wrapper );

	return widgetApi( {
		collectAnswer: () => JSON.stringify(
			selects.map( ( { leftText, select } ) => ( { left: leftText, right: select.value } ) )
		),
		setAnswer: ( v ) => {
			const pairs = Array.isArray( v ) ? v : [];
			selects.forEach( ( { leftText, select } ) => {
				const pair   = pairs.find( ( p ) => p && p.left === leftText );
				select.value = pair ? String( pair.right ?? '' ) : '';
			} );
		},
		hasAnswer: () => selects.some( ( { select } ) => '' !== select.value ),
		onChange: ( cb ) => selects.forEach( ( { select } ) => select.addEventListener( 'change', cb ) ),
		lock: () => selects.forEach( ( { select } ) => { select.disabled = true; } ),
	} );
}

// ── Ordering (drag-and-drop list) ──────────────────────────────────────────

function buildOrderingWidget( container, data, isDone ) {
	const items = Array.isArray( data.items ) ? data.items : [];
	const list  = make( 'ul', 'fs-widget-ordering' );
	let notifyChange = () => {};

	items.forEach( item => {
		const li        = make( 'li', 'fs-widget-ordering-item' );
		li.dataset.text = item.text;

		if ( ! isDone ) {
			li.draggable      = true;
			const grip        = make( 'span', 'fs-widget-ordering-grip' );
			grip.setAttribute( 'aria-hidden', 'true' );
			grip.textContent  = '⠿';
			li.appendChild( grip );
		}

		const label       = make( 'span', 'fs-widget-ordering-text' );
		label.textContent = item.text;
		li.appendChild( label );
		list.appendChild( li );
	} );

	if ( ! isDone ) {
		attachOrderingDragDrop( list, () => notifyChange() );
	}

	container.appendChild( list );

	return widgetApi( {
		collectAnswer: () => JSON.stringify(
			Array.from( list.querySelectorAll( '.fs-widget-ordering-item' ) )
				.map( el => el.dataset.text )
		),
		setAnswer: ( v ) => {
			if ( ! Array.isArray( v ) ) { return; }
			v.forEach( ( text ) => {
				const li = Array.from( list.children ).find( ( el ) => el.dataset.text === text );
				if ( li ) { list.appendChild( li ); }
			} );
		},
		onChange: ( cb ) => { notifyChange = cb; },
		lock: () => list.querySelectorAll( '.fs-widget-ordering-item' ).forEach( ( li ) => {
			li.draggable = false;
			li.querySelector( '.fs-widget-ordering-grip' )?.remove();
		} ),
	} );
}

function attachOrderingDragDrop( list, onDrop ) {
	let dragEl = null;

	list.addEventListener( 'dragstart', ( e ) => {
		dragEl = e.target.closest( '.fs-widget-ordering-item' );
		if ( dragEl ) {
			dragEl.classList.add( 'fs-dragging' );
			e.dataTransfer.effectAllowed = 'move';
		}
	} );

	list.addEventListener( 'dragover', ( e ) => {
		e.preventDefault();
		const over = e.target.closest( '.fs-widget-ordering-item' );
		if ( ! over || over === dragEl ) { return; }
		const rect  = over.getBoundingClientRect();
		const after = e.clientY > rect.top + rect.height / 2;
		list.insertBefore( dragEl, after ? over.nextSibling : over );
	} );

	list.addEventListener( 'dragend', () => {
		dragEl?.classList.remove( 'fs-dragging' );
		dragEl = null;
		onDrop();
	} );
}

// ── Fill (inline gap inputs) ───────────────────────────────────────────────

function buildFillWidget( container, data, isDone ) {
	const segments  = Array.isArray( data.segments ) ? data.segments : [];
	const wrapper   = make( 'div', 'fs-widget-fill' );
	const gapInputs = [];

	segments.forEach( seg => {
		if ( seg.type === 'text' ) {
			const span       = make( 'span', 'fs-widget-fill-text' );
			span.textContent = seg.content || '';
			wrapper.appendChild( span );
		} else if ( seg.type === 'gap' ) {
			const input           = make( 'input', 'fs-widget-fill-gap' );
			input.type            = 'text';
			input.dataset.gapIndex = String( seg.index );
			input.size            = 12;
			if ( isDone ) { input.disabled = true; }
			gapInputs.push( input );
			wrapper.appendChild( input );
		}
	} );

	container.appendChild( wrapper );

	return Object.assign(
		inputsApi(
			gapInputs,
			() => {
				const answers = {};
				gapInputs.forEach( inp => {
					answers[ inp.dataset.gapIndex ] = inp.value.trim();
				} );
				return JSON.stringify( answers );
			},
			() => gapInputs.some( ( inp ) => '' !== inp.value.trim() )
		),
		{ setAnswer: ( v ) => gapInputs.forEach( ( inp ) => { inp.value = String( v?.[ inp.dataset.gapIndex ] ?? '' ); } ) }
	);
}

// ── Helpers ────────────────────────────────────────────────────────────────

function make( tag, className ) {
	const el     = document.createElement( tag );
	el.className = className;
	return el;
}
