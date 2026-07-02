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
		case 'file_answer': return buildFileAnswerWidget( container, widgetData, isDone );
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

// ── Развёрнутый ответ: файлы и/или текст, ручная проверка (Эпик 13, D16) ───

function buildFileAnswerWidget( container, data, isDone ) {
	// Материалы задания (файлы-исходники от преподавателя) — ссылки на скачивание.
	if ( Array.isArray( data.materials ) && data.materials.length ) {
		const box   = make( 'div', 'fs-widget-materials' );
		const title = make( 'div', 'fs-widget-materials__title' );
		title.textContent = 'Материалы задания:';
		box.appendChild( title );
		data.materials.forEach( ( m ) => {
			const link = make( 'a', 'fs-widget-materials__link' );
			link.href        = m.url;
			link.target      = '_blank';
			link.rel         = 'noopener noreferrer';
			link.textContent = m.name || m.url;
			box.appendChild( link );
		} );
		container.appendChild( box );
	}

	const textarea       = make( 'textarea', 'fs-widget-text' );
	textarea.rows        = 5;
	textarea.placeholder = 'Текст решения (необязательно, если прикладываете файл)…';
	if ( isDone ) { textarea.disabled = true; }
	container.appendChild( textarea );

	// Загрузка файлов ответа: двухшаговая (upload → attachment_id → id в JSON-ответ).
	const files = []; // { id, name }

	const fileBox  = make( 'div', 'fs-widget-files' );
	const chips    = make( 'div', 'fs-widget-files__chips' );
	const controls = make( 'div', 'fs-widget-files__controls' );

	const input    = make( 'input', 'fs-widget-files__input' );
	input.type     = 'file';
	input.multiple = true;
	input.accept   = '.jpg,.jpeg,.png,.gif,.webp,.heic,.pdf,.doc,.docx,.pptx,.txt,.py';
	input.hidden   = true;

	const addBtn       = make( 'button', 'fs-btn fs-btn--secondary fs-widget-files__add' );
	addBtn.type        = 'button';
	addBtn.textContent = '📎 Прикрепить файлы';

	const status = make( 'span', 'fs-widget-files__status' );
	status.setAttribute( 'aria-live', 'polite' );

	if ( isDone ) { addBtn.disabled = true; }

	const vars = window.fs_lms_player_vars || window.fs_lms_assessment_vars;
	const glid = container.closest( '.fs-player' )?.dataset.groupLessonId
		|| container.dataset.groupLessonId;

	function renderChips() {
		chips.textContent = '';
		files.forEach( ( f, i ) => {
			const chip = make( 'span', 'fs-widget-files__chip' );
			const name = make( 'span', 'fs-widget-files__chip-name' );
			name.textContent = f.name;
			chip.appendChild( name );
			if ( ! isDone ) {
				const rm = make( 'button', 'fs-widget-files__chip-remove' );
				rm.type        = 'button';
				rm.textContent = '✕';
				rm.setAttribute( 'aria-label', 'Убрать файл' );
				rm.addEventListener( 'click', () => { files.splice( i, 1 ); renderChips(); } );
				chip.appendChild( rm );
			}
			chips.appendChild( chip );
		} );
	}

	async function uploadOne( file ) {
		const fd = new FormData();
		fd.append( 'action',          vars.upload_action );
		fd.append( 'security',        vars.upload_nonce );
		fd.append( 'group_lesson_id', glid || '' );
		fd.append( 'answer_file',     file );
		const res  = await fetch( vars.ajax_url, { method: 'POST', body: fd } );
		const json = await res.json();
		if ( ! json?.success ) {
			throw new Error( json?.data?.message || json?.data || 'Не удалось загрузить файл' );
		}
		return json.data; // { attachment_id, url, name, mime }
	}

	addBtn.addEventListener( 'click', () => input.click() );
	input.addEventListener( 'change', async () => {
		if ( ! vars || ! vars.upload_action ) {
			status.textContent = 'Загрузка файлов недоступна.';
			return;
		}
		addBtn.disabled    = true;
		for ( const file of Array.from( input.files || [] ) ) {
			status.textContent = `Загрузка: ${ file.name }…`;
			try {
				const up = await uploadOne( file );
				files.push( { id: up.attachment_id, name: up.name || file.name } );
				renderChips();
				status.textContent = '';
			} catch ( e ) {
				status.textContent = `${ file.name }: ${ e.message }`;
			}
		}
		input.value     = '';
		addBtn.disabled = !! isDone;
	} );

	controls.append( addBtn, status );
	fileBox.append( chips, controls, input );
	container.appendChild( fileBox );

	const hint = make( 'p', 'fs-widget-files__hint' );
	hint.textContent = 'Проверяется преподавателем вручную. Фото/PDF/документ/презентация/.py, до 20 МБ.';
	container.appendChild( hint );

	return {
		collectAnswer: () => JSON.stringify( {
			text:  textarea.value.trim(),
			files: files.map( ( f ) => f.id ),
		} ),
	};
}

// ── Helpers ────────────────────────────────────────────────────────────────

function make( tag, className ) {
	const el     = document.createElement( tag );
	el.className = className;
	return el;
}
