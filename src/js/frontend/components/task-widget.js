/**
 * Interactive task widgets for the lesson player (Этап 6, Phase E).
 * Pure-JS, function pattern. No jQuery, no external deps.
 *
 * initTaskWidget(panel) reads .fs-task-widget data attributes, renders the
 * correct input widget, and returns { collectAnswer() → JSON string }.
 */

/**
 * @param {HTMLElement} panel  — the .fs-player__panel element
 * @returns {{ collectAnswer: () => string }|null}
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

// ── Text answer (Standard / Common) ───────────────────────────────────────

function buildTextAnswerWidget( container, isDone ) {
	const textarea = make( 'textarea', 'fs-widget-text' );
	textarea.rows        = 4;
	textarea.placeholder = 'Введите ответ…';
	if ( isDone ) { textarea.disabled = true; }
	container.appendChild( textarea );

	return { collectAnswer: () => JSON.stringify( textarea.value.trim() ) };
}

// ── Audio + text answer ────────────────────────────────────────────────────

function buildAudioWidget( container, data, isDone ) {
	if ( data.audio_url ) {
		const audio    = make( 'audio', 'fs-widget-audio' );
		audio.controls = true;
		audio.src      = data.audio_url;
		container.appendChild( audio );
	}

	const textarea       = make( 'textarea', 'fs-widget-text' );
	textarea.rows        = 3;
	textarea.placeholder = 'Введите ответ…';
	if ( isDone ) { textarea.disabled = true; }
	container.appendChild( textarea );

	return { collectAnswer: () => JSON.stringify( textarea.value.trim() ) };
}

// ── Triple (три отдельных инпута: задания 19, 20, 21) ──────────────────────

function buildTripleWidget( container, isDone ) {
	const keys   = [ '19', '20', '21' ];
	const inputs = {};

	keys.forEach( key => {
		const group       = make( 'div', 'fs-widget-triple-group' );
		const input       = make( 'input', 'fs-widget-triple-input' );
		input.type        = 'text';
		input.placeholder = `Ответ на задание ${ key }…`;
		if ( isDone ) { input.disabled = true; }
		inputs[ key ] = input;
		group.appendChild( input );
		container.appendChild( group );
	} );

	return {
		collectAnswer: () => JSON.stringify( {
			'19': inputs[ '19' ].value.trim(),
			'20': inputs[ '20' ].value.trim(),
			'21': inputs[ '21' ].value.trim(),
		} ),
	};
}

// ── Choice (radio / checkbox) ──────────────────────────────────────────────

function buildChoiceWidget( container, data, isDone ) {
	const multiple = !! data.multiple;
	const options  = Array.isArray( data.options ) ? data.options : [];
	const type     = multiple ? 'checkbox' : 'radio';
	const name     = `fs-choice-${ Date.now() }`;

	const list = make( 'ul', 'fs-widget-choice-list' );

	options.forEach( opt => {
		const li      = make( 'li', 'fs-widget-choice-item' );
		const id      = `fs-opt-${ name }-${ opt.id }`;
		const input   = make( 'input', 'fs-widget-choice-input' );
		const label   = make( 'label', 'fs-widget-choice-label' );

		input.type    = type;
		input.name    = name;
		input.value   = opt.id;
		input.id      = id;
		if ( isDone ) { input.disabled = true; }

		label.htmlFor     = id;
		label.textContent = opt.text;

		li.appendChild( input );
		li.appendChild( label );
		list.appendChild( li );
	} );

	container.appendChild( list );

	return {
		collectAnswer: () => {
			const checked = Array.from( list.querySelectorAll( 'input:checked' ) )
				.map( el => el.value );
			return JSON.stringify( checked );
		},
	};
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

	return {
		collectAnswer: () => JSON.stringify(
			selects.map( ( { leftText, select } ) => ( { left: leftText, right: select.value } ) )
		),
	};
}

// ── Ordering (drag-and-drop list) ──────────────────────────────────────────

function buildOrderingWidget( container, data, isDone ) {
	const items = Array.isArray( data.items ) ? data.items : [];
	const list  = make( 'ul', 'fs-widget-ordering' );

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
		attachOrderingDragDrop( list );
	}

	container.appendChild( list );

	return {
		collectAnswer: () => JSON.stringify(
			Array.from( list.querySelectorAll( '.fs-widget-ordering-item' ) )
				.map( el => el.dataset.text )
		),
	};
}

function attachOrderingDragDrop( list ) {
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

	return {
		collectAnswer: () => {
			const answers = {};
			gapInputs.forEach( inp => {
				answers[ inp.dataset.gapIndex ] = inp.value.trim();
			} );
			return JSON.stringify( answers );
		},
	};
}

// ── Helpers ────────────────────────────────────────────────────────────────

function make( tag, className ) {
	const el     = document.createElement( tag );
	el.className = className;
	return el;
}
