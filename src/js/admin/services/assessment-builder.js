import '../_types.js';
import { openPicker, esc, readSteps } from './step-editor.js';
import { showToast } from '../modules/toast.js';
import { ConfirmModal } from '../modals/confirm-modal.js';

/* global jQuery, fs_lms_vars */
const $ = jQuery;

/**
 * AssessmentBuilder — конструктор контрольной в стиле Course Builder.
 *
 * Слева — нумерованный список слотов-задач (.lesson/.les-num/.les-title).
 * Справа — полное тело задачи: условие, ответ, файлы, баллы (ЕГЭ).
 */
export const AssessmentBuilder = {
	init() {
		document.querySelectorAll( '.fs-lms-assessment-builder' ).forEach( mountBuilder );
	},
};

function mountBuilder( el ) {
	const assessmentId  = parseInt( el.dataset.assessmentId, 10 ) || 0;
	const subject       = String( el.dataset.subject || '' );
	const egeSlots      = parseInt( el.dataset.egeSlots, 10 ) || 0;
	const taskPointsMap = JSON.parse( el.dataset.taskPoints || '{}' );

	const initialSteps = readSteps( el );
	el.innerHTML = '';

	// ── State ─────────────────────────────────────────────────────────────────
	let slots = initialSteps.map( ( s, i ) => {
		const taskId = parseInt( s.payload?.ref, 10 ) || 0;
		return {
			key:    s.key || 'slot_' + i,
			taskId,
			title:  s._title || '',
			points: parseFloat( taskPointsMap[ taskId ] || 0 ),
		};
	} );
	let activeIndex = slots.length ? 0 : -1;

	// ── Layout (course-builder DOM) ───────────────────────────────────────────
	el.innerHTML = `
		<div class="builder">
			<div class="tree-pane">
				<div class="tree-head">
					<span class="th-title">Структура контрольной</span>
					<span class="th-count" data-slot-count></span>
				</div>
				<div class="tree-scroll" data-slot-list></div>
				<div class="tree-add">
					<button type="button" class="button" data-add-slot>+ Задача</button>
				</div>
			</div>
			<div class="editor-pane" data-editor></div>
		</div>
		<div class="fs-ab-save-status" data-status></div>
	`;

	const treeScroll = el.querySelector( '[data-slot-list]' );
	const editorPane = el.querySelector( '[data-editor]' );
	const countEl   = el.querySelector( '[data-slot-count]' );
	const statusEl  = el.querySelector( '[data-status]' );

	el.querySelector( '[data-add-slot]' ).addEventListener( 'click', addSlot );

	// ── Kind-change watcher ───────────────────────────────────────────────────
	const kindSelect = document.querySelector( '.fs-lms-assessment-kind-select' );
	let prevKind     = kindSelect ? kindSelect.value : '';

	function isEgeKind( kind ) {
		return kind === 'ege' || kind === 'ege_computer';
	}

	function toggleKindFields( kind ) {
		const scoreMapRow = document.querySelector( '#score_map' )?.closest( '.fs-lms-field-group' );
		if ( scoreMapRow ) {
			scoreMapRow.style.display = isEgeKind( kind ) ? '' : 'none';
		}
	}

	if ( kindSelect ) {
		toggleKindFields( prevKind );

		kindSelect.addEventListener( 'change', () => {
			const newKind = kindSelect.value;

			if ( slots.some( ( s ) => s.taskId > 0 ) ) {
				kindSelect.value = prevKind;
				showToast( 'Нельзя изменить тип: в контрольной уже есть задачи', 'error' );
				return;
			}

			prevKind = newKind;
			toggleKindFields( newKind );

			if ( isEgeKind( newKind ) && egeSlots > 0 ) {
				autoFillSlots( egeSlots );
			} else if ( newKind === 'control' ) {
				slots       = [];
				activeIndex = -1;
				render();
			}
		} );
	}

	// ── Slot operations ───────────────────────────────────────────────────────
	function autoFillSlots( count ) {
		slots = Array.from( { length: count }, ( _, i ) => ( {
			key: 'slot_' + i, taskId: 0, title: '', points: 0,
		} ) );
		activeIndex = 0;
		render();
		save();
	}

	function addSlot() {
		slots.push( { key: 'slot_' + slots.length, taskId: 0, title: '', points: 0 } );
		activeIndex = slots.length - 1;
		render();
		save();
	}

	async function removeSlot( index ) {
		try {
			await ConfirmModal.confirm( { title: 'Удалить этот слот?', isDanger: true, confirmText: 'Удалить' } );
		} catch {
			return;
		}
		slots.splice( index, 1 );
		if ( activeIndex >= slots.length ) {
			activeIndex = Math.max( 0, slots.length - 1 );
		}
		render();
		save();
	}

	function assignTask( index, taskId, title ) {
		slots[ index ].taskId = taskId;
		slots[ index ].title  = title;
		activeIndex = index;
		renderLeft();
		renderCenter();
		save();
	}

	function updatePoints( index, value ) {
		slots[ index ].points = parseFloat( value ) || 0;
		save();
	}

	// ── Render ────────────────────────────────────────────────────────────────
	function render() {
		renderLeft();
		renderCenter();
	}

	function renderLeft() {
		treeScroll.innerHTML = '';
		countEl.textContent  = slots.length ? slots.length + ' зад.' : '';

		slots.forEach( ( slot, i ) => {
			const item = document.createElement( 'div' );
			item.className = 'lesson'
				+ ( i === activeIndex ? ' active' : '' )
				+ ( ! slot.taskId ? ' empty' : '' );
			item.innerHTML = `<span class="les-num">${ i + 1 }</span>`
				+ `<span class="les-title">${ esc( slot.title || '(Пусто)' ) }</span>`;
			item.addEventListener( 'click', () => {
				activeIndex = i;
				treeScroll.querySelectorAll( '.lesson' )
					.forEach( ( n, j ) => n.classList.toggle( 'active', j === i ) );
				renderCenter();
			} );
			treeScroll.appendChild( item );
		} );
	}

	function renderCenter() {
		editorPane.innerHTML = '';

		if ( ! slots.length || activeIndex < 0 ) {
			editorPane.innerHTML = '<div class="editor-empty">Нет слотов — нажмите «+ Задача».</div>';
			return;
		}

		const slot = slots[ activeIndex ];
		const idx  = activeIndex;

		if ( slot.taskId > 0 ) {
			editorPane.innerHTML = '<div class="editor-empty"><p>Загрузка…</p></div>';
			fetchPreview( slot.taskId )
				.then( ( data ) => {
					editorPane.innerHTML = '';
					renderTaskContent( editorPane, data, slot, idx );
				} )
				.catch( () => {
					editorPane.innerHTML = '';
					renderEmptySlot( editorPane, slot, idx );
				} );
		} else {
			renderEmptySlot( editorPane, slot, idx );
		}
	}

	function renderEditorTop( container, titleText, slot, index, editUrl = '', isDraft = false ) {
		const top = document.createElement( 'div' );
		top.className = 'editor-top';

		const titleRow = document.createElement( 'div' );
		titleRow.className = 'lesson-title-row';

		const h3 = document.createElement( 'h3' );
		h3.className   = 'fs-ab-task-heading';
		h3.textContent = titleText;
		titleRow.appendChild( h3 );

		if ( editUrl ) {
			const link = document.createElement( 'a' );
			link.href        = editUrl;
			link.target      = '_blank';
			link.className   = 'lesson-flag';
			link.textContent = 'Редактировать ↗';
			titleRow.appendChild( link );
		}

		if ( isDraft ) {
			const badge = document.createElement( 'span' );
			badge.className   = 'lesson-flag';
			badge.textContent = 'Незавершённая';
			titleRow.appendChild( badge );
		}

		const removeBtn = document.createElement( 'button' );
		removeBtn.type        = 'button';
		removeBtn.className   = 'lesson-flag danger';
		removeBtn.textContent = 'Удалить слот';
		removeBtn.addEventListener( 'click', () => removeSlot( index ) );
		titleRow.appendChild( removeBtn );

		top.appendChild( titleRow );
		container.appendChild( top );
	}

	function renderTaskContent( container, data, slot, index ) {
		renderEditorTop( container, data.title, slot, index, data.edit_url, data.status === 'draft' );

		const body = document.createElement( 'div' );
		body.className = 'editor-body';

		if ( data.condition_html ) {
			const sec = document.createElement( 'div' );
			sec.className = 'fs-ab-task-section';
			sec.innerHTML = `<p class="fs-ab-section-label">Условие</p>${ data.condition_html }`;
			body.appendChild( sec );
		}

		if ( data.answer_html ) {
			const sec = document.createElement( 'div' );
			sec.className = 'fs-ab-task-section fs-ab-task-answer';
			sec.innerHTML = `<p class="fs-ab-section-label">Ответ</p>${ data.answer_html }`;
			body.appendChild( sec );
		}

		if ( data.audio_url ) {
			const audio = document.createElement( 'audio' );
			audio.controls = true;
			audio.src      = data.audio_url;
			audio.className = 'fs-ab-task-audio';
			body.appendChild( audio );
		}

		renderScore( body, slot, index );
		renderActions( body, slot, index );
		container.appendChild( body );
	}

	function renderEmptySlot( container, slot, index ) {
		renderEditorTop( container, 'Задача не выбрана', slot, index );

		const body = document.createElement( 'div' );
		body.className = 'editor-body';

		renderScore( body, slot, index );
		renderActions( body, slot, index );
		container.appendChild( body );
	}

	function renderScore( container, slot, index ) {
		if ( ! isEgeKind( prevKind ) ) { return; }

		const wrap = document.createElement( 'div' );
		wrap.className = 'fs-ab-task-score';

		const label = document.createElement( 'label' );
		label.textContent = 'Баллов за задание:';
		label.htmlFor     = 'fs-ab-points-' + index;

		const input = document.createElement( 'input' );
		input.type      = 'number';
		input.id        = 'fs-ab-points-' + index;
		input.className = 'small-text';
		input.min       = '0';
		input.step      = '0.5';
		input.value     = String( slot.points || 0 );
		input.addEventListener( 'change', () => updatePoints( index, input.value ) );

		wrap.appendChild( label );
		wrap.appendChild( input );
		container.appendChild( wrap );
	}

	function renderActions( container, slot, index ) {
		const actions = document.createElement( 'div' );
		actions.className = 'fs-ab-task-actions';

		const pickBtn = document.createElement( 'button' );
		pickBtn.type        = 'button';
		pickBtn.className   = 'button';
		pickBtn.textContent = slot.taskId ? 'Заменить задачу' : 'Выбрать из банка';
		pickBtn.addEventListener( 'click', () => {
			openPicker( pickBtn, {
				placeholder: 'Поиск задачи…',
				emptyText:   'Задачи не найдены',
				fetchFn:     ( q ) => searchTasks( q ),
				onPick:      ( id, title ) => assignTask( index, id, title ),
			} );
		} );
		actions.appendChild( pickBtn );

		if ( ! slot.taskId ) {
			const createBtn = document.createElement( 'button' );
			createBtn.type        = 'button';
			createBtn.className   = 'button';
			createBtn.textContent = 'Создать задачу';
			createBtn.addEventListener( 'click', () => openCreateForm( actions, index ) );
			actions.appendChild( createBtn );
		}

		if ( slot.taskId > 0 ) {
			const clearBtn = document.createElement( 'button' );
			clearBtn.type        = 'button';
			clearBtn.className   = 'button button-link-delete fs-ab-clear';
			clearBtn.textContent = 'Очистить';
			clearBtn.addEventListener( 'click', () => assignTask( index, 0, '' ) );
			actions.appendChild( clearBtn );
		}

		container.appendChild( actions );
	}

	// ── Inline create form ────────────────────────────────────────────────────
	function openCreateForm( actionsEl, index ) {
		actionsEl.innerHTML = '';

		const form = document.createElement( 'div' );
		form.className = 'fs-ab-create-form';

		const input = document.createElement( 'input' );
		input.type        = 'text';
		input.className   = 'regular-text fs-ab-create-input';
		input.placeholder = 'Название задачи…';
		form.appendChild( input );

		const btnRow = document.createElement( 'div' );
		btnRow.className = 'fs-ab-create-btn-row';

		const confirmBtn = document.createElement( 'button' );
		confirmBtn.type        = 'button';
		confirmBtn.className   = 'button button-primary';
		confirmBtn.textContent = 'Создать';

		const cancelBtn = document.createElement( 'button' );
		cancelBtn.type        = 'button';
		cancelBtn.className   = 'button';
		cancelBtn.textContent = 'Отмена';
		cancelBtn.addEventListener( 'click', () => renderCenter() );

		const doCreate = () => {
			const title = input.value.trim();
			if ( ! title ) { input.focus(); return; }
			confirmBtn.disabled    = true;
			confirmBtn.textContent = 'Создание…';
			post( fs_lms_vars.ajax_actions.createAssessmentTaskDraft, {
				subject_key: subject,
				title,
			} ).then( ( data ) => assignTask( index, data.id, data.title ) )
			   .catch( ( msg ) => {
				   showToast( String( msg ) || 'Ошибка создания задачи', 'error' );
				   confirmBtn.disabled    = false;
				   confirmBtn.textContent = 'Создать';
			   } );
		};

		confirmBtn.addEventListener( 'click', doCreate );
		input.addEventListener( 'keydown', ( e ) => { if ( e.key === 'Enter' ) { e.preventDefault(); doCreate(); } } );

		btnRow.appendChild( confirmBtn );
		btnRow.appendChild( cancelBtn );
		form.appendChild( btnRow );
		actionsEl.appendChild( form );
		input.focus();
	}

	// ── AJAX ──────────────────────────────────────────────────────────────────
	function setStatus( state ) {
		if ( state === 'saving' ) {
			statusEl.className   = 'fs-ab-save-status saving';
			statusEl.textContent = 'Сохранение…';
		} else {
			statusEl.className = 'fs-ab-save-status';
			statusEl.innerHTML = '<span class="saved-dot"></span> Все изменения сохранены';
		}
	}

	function post( action, data ) {
		return new Promise( ( resolve, reject ) => {
			$.post(
				fs_lms_vars.ajaxurl,
				Object.assign( { action, security: fs_lms_vars.nonces.authorAssessment }, data ),
			)
				.done( ( r ) => ( r && r.success ) ? resolve( r.data ) : reject( ( r && r.data ) || 'Ошибка' ) )
				.fail( () => reject( 'Ошибка сети' ) );
		} );
	}

	function buildTaskPoints() {
		const map = {};
		slots.forEach( ( s ) => {
			if ( s.taskId > 0 ) { map[ s.taskId ] = s.points; }
		} );
		return map;
	}

	function save() {
		setStatus( 'saving' );
		post( fs_lms_vars.ajax_actions.saveAssessmentItems, {
			assessment_id: assessmentId,
			item_ids:      slots.map( ( s ) => s.taskId ),
			task_points:   buildTaskPoints(),
		} )
			.then( () => setStatus( 'saved' ) )
			.catch( ( msg ) => {
				showToast( String( msg ) || 'Ошибка сохранения', 'error' );
				setStatus( 'saved' );
			} );
	}

	function fetchPreview( taskId ) {
		return post( fs_lms_vars.ajax_actions.getTaskPreview, {
			task_id:     taskId,
			subject_key: subject,
		} );
	}

	function searchTasks( q ) {
		return new Promise( ( resolve, reject ) => {
			$.post( fs_lms_vars.ajaxurl, {
				action:      fs_lms_vars.ajax_actions.getStepCandidates,
				security:    fs_lms_vars.nonces.authorLesson,
				subject_key: subject,
				kind:        'task',
				source:      'bank',
				search:      q,
			} )
				.done( ( r ) => ( r && r.success ) ? resolve( r.data ) : reject() )
				.fail( () => reject() );
		} );
	}

	// ── Init ──────────────────────────────────────────────────────────────────
	render();

	if ( egeSlots > 0 && slots.length === 0 && isEgeKind( prevKind ) ) {
		autoFillSlots( egeSlots );
	}
}
