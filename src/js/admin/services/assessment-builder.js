import '../_types.js';
import { openPicker, esc, readSteps } from './step-editor.js';
import { showToast } from '../modules/toast.js';
import { ConfirmModal } from '../modals/confirm-modal.js';

/* global jQuery, fs_lms_vars */
const $ = jQuery;

/**
 * AssessmentBuilder — двухпанельный конструктор контрольной.
 *
 * Слева — нумерованный список слотов-задач, справа — превью выбранной задачи
 * с кнопками «Выбрать из банка» и «Создать задачу».
 * При смене kind на ЕГЭ/ЕГЭ-компьютер автоматически создаются N пустых слотов.
 * Менять kind нельзя, если в контрольной есть хоть одна задача.
 */
export const AssessmentBuilder = {
	init() {
		document.querySelectorAll( '.fs-lms-assessment-builder' ).forEach( mountBuilder );
	},
};

function mountBuilder( el ) {
	const assessmentId = parseInt( el.dataset.assessmentId, 10 ) || 0;
	const subject      = String( el.dataset.subject || '' );
	const egeSlots     = parseInt( el.dataset.egeSlots, 10 ) || 0;
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

	// ── Layout ────────────────────────────────────────────────────────────────
	const wrap = document.createElement( 'div' );
	wrap.className = 'fs-ab';
	el.appendChild( wrap );

	const leftPanel   = document.createElement( 'div' );
	leftPanel.className = 'fs-ab-left';
	wrap.appendChild( leftPanel );

	const centerPanel = document.createElement( 'div' );
	centerPanel.className = 'fs-ab-center';
	wrap.appendChild( centerPanel );

	const footer = document.createElement( 'div' );
	footer.className = 'fs-ab-footer';
	el.appendChild( footer );

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
			key:    'slot_' + i,
			taskId: 0,
			title:  '',
			points: 0,
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
		setStatus( 'saved' );
	}

	function renderLeft() {
		leftPanel.innerHTML = '';

		if ( slots.length ) {
			const list = document.createElement( 'div' );
			list.className = 'fs-ab-slot-list';

			slots.forEach( ( slot, i ) => {
				const item = document.createElement( 'div' );
				item.className = 'fs-ab-slot-item'
					+ ( i === activeIndex ? ' active' : '' )
					+ ( ! slot.taskId ? ' empty' : '' );
				item.innerHTML = `<span class="fs-ab-slot-num">${ i + 1 }</span>`
					+ `<span class="fs-ab-slot-title">${ esc( slot.title || '(Пусто)' ) }</span>`;
				item.addEventListener( 'click', () => {
					activeIndex = i;
					leftPanel.querySelectorAll( '.fs-ab-slot-item' )
						.forEach( ( el, j ) => el.classList.toggle( 'active', j === i ) );
					renderCenter();
				} );
				list.appendChild( item );
			} );

			leftPanel.appendChild( list );
		}

		const addBtn = document.createElement( 'button' );
		addBtn.type        = 'button';
		addBtn.className   = 'button fs-ab-add-slot';
		addBtn.textContent = '+ Добавить слот';
		addBtn.addEventListener( 'click', addSlot );
		leftPanel.appendChild( addBtn );
	}

	function renderCenter() {
		centerPanel.innerHTML = '';

		if ( ! slots.length || activeIndex < 0 ) {
			centerPanel.innerHTML = '<div class="fs-ab-empty">Нет слотов — нажмите «Добавить слот».</div>';
			return;
		}

		const slot = slots[ activeIndex ];
		const idx  = activeIndex;

		const header = document.createElement( 'div' );
		header.className = 'fs-ab-task-header';
		header.innerHTML = `<span class="fs-ab-slot-badge">Задание ${ activeIndex + 1 }</span>`;

		const removeBtn = document.createElement( 'button' );
		removeBtn.type        = 'button';
		removeBtn.className   = 'button button-small fs-ab-remove-slot';
		removeBtn.textContent = 'Удалить слот';
		removeBtn.addEventListener( 'click', () => removeSlot( idx ) );
		header.appendChild( removeBtn );
		centerPanel.appendChild( header );

		const taskArea = document.createElement( 'div' );
		taskArea.className = 'fs-ab-task-area';
		centerPanel.appendChild( taskArea );

		if ( slot.taskId > 0 ) {
			taskArea.innerHTML = '<div class="fs-ab-loading">Загрузка…</div>';
			fetchPreview( slot.taskId )
				.then( ( data ) => {
					taskArea.innerHTML = '';
					renderTaskContent( taskArea, data, slot, idx );
				} )
				.catch( () => {
					taskArea.innerHTML = '';
					const fallback = document.createElement( 'p' );
					fallback.className   = 'fs-ab-task-title';
					fallback.textContent = slot.title || 'Задача #' + slot.taskId;
					taskArea.appendChild( fallback );
					renderActions( taskArea, slot, idx );
				} );
		} else {
			const empty = document.createElement( 'div' );
			empty.className   = 'fs-ab-empty-slot';
			empty.textContent = 'Задача не выбрана';
			taskArea.appendChild( empty );
			renderScore( taskArea, slot, idx );
			renderActions( taskArea, slot, idx );
		}
	}

	function renderTaskContent( container, data, slot, index ) {
		const preview = document.createElement( 'div' );
		preview.className = 'fs-ab-task-preview';

		const titleRow = document.createElement( 'div' );
		titleRow.className = 'fs-ab-task-title-row';
		titleRow.innerHTML = `<strong class="fs-ab-task-title">${ esc( data.title ) }</strong>`;

		if ( data.edit_url ) {
			const link = document.createElement( 'a' );
			link.href        = data.edit_url;
			link.target      = '_blank';
			link.className   = 'button button-small';
			link.textContent = 'Редактировать ↗';
			titleRow.appendChild( link );
		}
		preview.appendChild( titleRow );

		if ( data.status === 'draft' ) {
			const badge = document.createElement( 'span' );
			badge.className   = 'fs-lms-draft-badge';
			badge.textContent = 'Незавершённая';
			preview.appendChild( badge );
		}

		if ( data.task_text ) {
			const text = document.createElement( 'div' );
			text.className   = 'fs-ab-task-text';
			const raw        = data.task_text;
			text.textContent = raw.length > 600 ? raw.slice( 0, 600 ) + '…' : raw;
			preview.appendChild( text );
		}

		container.appendChild( preview );
		renderScore( container, slot, index );
		renderActions( container, slot, index );
	}

	function renderScore( container, slot, index ) {
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
		cancelBtn.addEventListener( 'click', () => {
			activeIndex = index;
			renderCenter();
		} );

		const doCreate = () => {
			const title = input.value.trim();
			if ( ! title ) { input.focus(); return; }
			confirmBtn.disabled   = true;
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
		footer.innerHTML = state === 'saving'
			? '<span class="fs-ab-status saving">Сохранение…</span>'
			: '<span class="fs-ab-status"><span class="saved-dot"></span> Все изменения сохранены</span>';
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

	// Авто-заполнение при первоначальной загрузке страницы с ЕГЭ-видом и без слотов
	if ( egeSlots > 0 && slots.length === 0 && ( prevKind === 'ege' || prevKind === 'ege_computer' ) ) {
		autoFillSlots( egeSlots );
	}
}
