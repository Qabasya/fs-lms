import '../_types.js';
import { openPicker, esc, readSteps } from './step-editor.js';
import { showToast } from '../modules/toast.js';
import { ConfirmModal } from '../modals/confirm-modal.js';

/* global jQuery, fs_lms_vars */
const $ = jQuery;

/**
 * post(action, nonce, data) — единый AJAX-помощник слот-билдера.
 * Нонс передаётся явно, поэтому один билдер может ходить в эндпоинты
 * с разными нонсами (например, превью задачи под `authorAssessment`).
 *
 * @param {string} action
 * @param {string} nonce
 * @param {Object} data
 * @returns {Promise<*>}
 */
export function post( action, nonce, data ) {
	return new Promise( ( resolve, reject ) => {
		$.post(
			fs_lms_vars.ajaxurl,
			Object.assign( { action, security: nonce }, data ),
		)
			.done( ( r ) => ( r && r.success ) ? resolve( r.data ) : reject( ( r && r.data ) || 'Ошибка' ) )
			.fail( () => reject( 'Ошибка сети' ) );
	} );
}

const defaultMapSlot = ( s, i ) => ( {
	key:    s.key || 'slot_' + i,
	taskId: parseInt( s.payload?.ref, 10 ) || 0,
	title:  s._title || '',
} );
const defaultNewSlot = ( i ) => ( { key: 'slot_' + i, taskId: 0, title: '' } );

/**
 * createSlotBuilder — конструктор «нумерованный список слотов-задач».
 *
 * Левая панель — список слотов; правая — тело выбранной задачи
 * (условие / ответ / аудио) + действия (выбрать из банка / создать / очистить).
 * Общий каркас для работы и контрольной; различия задаются через `config`.
 *
 * @param {HTMLElement} el
 * @param {Object}      config
 * @param {string}      config.treeTitle                Заголовок левой панели.
 * @param {string}      config.emptyText                Текст пустого редактора.
 * @param {Function}    config.persist  (slots) => Promise              Сохранение item_ids.
 * @param {Function}    config.search   (query) => Promise<{id,title}[]>
 * @param {Function}    config.preview  (taskId) => Promise<Object>
 * @param {Function}   [config.createTask] (title) => Promise<{id,title}>  Inline-создание задачи.
 * @param {Function}   [config.mapSlot]    (step,i) => slot              Маппинг начальных слотов.
 * @param {Function}   [config.newSlot]    (i) => slot                   Пустой слот для «+ Задача».
 * @param {Function}   [config.renderExtraBody] (container, slot, index, api) => void
 * @param {Function}   [config.onReady]    (api) => void                 Хук после первого рендера.
 * @returns {{ getSlots: Function, replaceSlots: Function, render: Function, save: Function }}
 */
export function createSlotBuilder( el, config ) {
	const mapSlot = typeof config.mapSlot === 'function' ? config.mapSlot : defaultMapSlot;
	const newSlot = typeof config.newSlot === 'function' ? config.newSlot : defaultNewSlot;

	const initialSteps = readSteps( el );
	el.innerHTML = '';

	let slots       = initialSteps.map( mapSlot );
	let activeIndex = slots.length ? 0 : -1;

	el.innerHTML = `
		<div class="fs-sb-builder">
			<div class="fs-sb-tree">
				<div class="fs-sb-tree-head">
					<span class="fs-sb-th-title">${ esc( config.treeTitle || 'Структура' ) }</span>
					<span class="fs-sb-th-count" data-slot-count></span>
				</div>
				<div class="fs-sb-tree-scroll" data-slot-list></div>
				<div class="fs-sb-tree-add">
					<button type="button" class="button" data-add-slot>+ Задача</button>
				</div>
			</div>
			<div class="fs-sb-editor" data-editor></div>
		</div>
		<div class="fs-sb-status" data-status></div>
	`;

	const treeScroll = el.querySelector( '[data-slot-list]' );
	const editorPane = el.querySelector( '[data-editor]' );
	const countEl    = el.querySelector( '[data-slot-count]' );
	const statusEl   = el.querySelector( '[data-status]' );

	el.querySelector( '[data-add-slot]' ).addEventListener( 'click', addSlot );

	const api = {
		getSlots:     () => slots,
		replaceSlots: ( arr, active ) => {
			slots       = arr;
			activeIndex = ( active === undefined ) ? ( slots.length ? 0 : -1 ) : active;
			render();
		},
		render,
		save,
	};

	render();
	if ( typeof config.onReady === 'function' ) { config.onReady( api ); }

	return api;

	// ── Slot operations ───────────────────────────────────────────────────────
	function addSlot() {
		slots.push( newSlot( slots.length ) );
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

	// ── Render ────────────────────────────────────────────────────────────────
	function render() {
		renderLeft();
		renderCenter();
	}

	function renderLeft() {
		treeScroll.innerHTML = '';
		countEl.textContent  = slots.length ? slots.length + ' зад.' : '';

		slots.forEach( ( slot, i ) => {
			const item = document.createElement( 'div' );
			item.className = 'fs-sb-slot'
				+ ( i === activeIndex ? ' active' : '' )
				+ ( ! slot.taskId ? ' empty' : '' );
			item.innerHTML = `<span class="fs-sb-slot-num">${ i + 1 }</span>`
				+ `<span class="fs-sb-slot-title">${ esc( slot.title || '(Пусто)' ) }</span>`;
			item.addEventListener( 'click', () => {
				activeIndex = i;
				treeScroll.querySelectorAll( '.fs-sb-slot' )
					.forEach( ( n, j ) => n.classList.toggle( 'active', j === i ) );
				renderCenter();
			} );
			treeScroll.appendChild( item );
		} );
	}

	function renderCenter() {
		editorPane.innerHTML = '';

		if ( ! slots.length || activeIndex < 0 ) {
			editorPane.innerHTML = `<div class="fs-sb-empty">${ esc( config.emptyText || 'Нет слотов.' ) }</div>`;
			return;
		}

		const slot = slots[ activeIndex ];
		const idx  = activeIndex;

		if ( slot.taskId > 0 ) {
			editorPane.innerHTML = '<div class="fs-sb-empty"><p>Загрузка…</p></div>';
			config.preview( slot.taskId )
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
		top.className = 'fs-sb-editor-top';

		const titleRow = document.createElement( 'div' );
		titleRow.className = 'fs-sb-title-row';

		const h3 = document.createElement( 'h3' );
		h3.className   = 'fs-sb-task-heading';
		h3.textContent = titleText;
		titleRow.appendChild( h3 );

		if ( editUrl ) {
			const link = document.createElement( 'a' );
			link.href        = editUrl;
			link.target      = '_blank';
			link.className   = 'fs-sb-flag';
			link.textContent = 'Редактировать ↗';
			titleRow.appendChild( link );
		}

		if ( isDraft ) {
			const badge = document.createElement( 'span' );
			badge.className   = 'fs-sb-flag';
			badge.textContent = 'Незавершённая';
			titleRow.appendChild( badge );
		}

		const removeBtn = document.createElement( 'button' );
		removeBtn.type        = 'button';
		removeBtn.className   = 'fs-sb-flag danger';
		removeBtn.textContent = 'Удалить слот';
		removeBtn.addEventListener( 'click', () => removeSlot( index ) );
		titleRow.appendChild( removeBtn );

		top.appendChild( titleRow );
		container.appendChild( top );
	}

	function renderTaskContent( container, data, slot, index ) {
		renderEditorTop( container, data.title, slot, index, data.edit_url, data.status === 'draft' );

		const body = document.createElement( 'div' );
		body.className = 'fs-sb-body';

		if ( data.condition_html ) {
			const sec = document.createElement( 'div' );
			sec.className = 'fs-sb-task-section';
			sec.innerHTML = `<p class="fs-sb-section-label">Условие</p>${ data.condition_html }`;
			body.appendChild( sec );
		}

		if ( data.answer_html ) {
			const sec = document.createElement( 'div' );
			sec.className = 'fs-sb-task-section fs-sb-task-answer';
			sec.innerHTML = `<p class="fs-sb-section-label">Ответ</p>${ data.answer_html }`;
			body.appendChild( sec );
		}

		if ( data.audio_url ) {
			const audio = document.createElement( 'audio' );
			audio.controls  = true;
			audio.src       = data.audio_url;
			audio.className = 'fs-sb-task-audio';
			body.appendChild( audio );
		}

		if ( typeof config.renderExtraBody === 'function' ) {
			config.renderExtraBody( body, slot, index, api );
		}
		renderActions( body, slot, index );
		container.appendChild( body );
	}

	function renderEmptySlot( container, slot, index ) {
		renderEditorTop( container, 'Задача не выбрана', slot, index );

		const body = document.createElement( 'div' );
		body.className = 'fs-sb-body';
		if ( typeof config.renderExtraBody === 'function' ) {
			config.renderExtraBody( body, slot, index, api );
		}
		renderActions( body, slot, index );
		container.appendChild( body );
	}

	function renderActions( container, slot, index ) {
		const actions = document.createElement( 'div' );
		actions.className = 'fs-sb-task-actions';

		const pickBtn = document.createElement( 'button' );
		pickBtn.type        = 'button';
		pickBtn.className   = 'button';
		pickBtn.textContent = slot.taskId ? 'Заменить задачу' : 'Выбрать из банка';
		pickBtn.addEventListener( 'click', () => {
			openPicker( pickBtn, {
				placeholder: 'Поиск задачи…',
				emptyText:   'Задачи не найдены',
				fetchFn:     ( q ) => config.search( q ),
				onPick:      ( id, title ) => assignTask( index, id, title ),
			} );
		} );
		actions.appendChild( pickBtn );

		if ( ! slot.taskId && typeof config.createTask === 'function' ) {
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
			clearBtn.className   = 'button button-link-delete fs-sb-clear';
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
		form.className = 'fs-sb-create-form';

		const input = document.createElement( 'input' );
		input.type        = 'text';
		input.className   = 'regular-text fs-sb-create-input';
		input.placeholder = 'Название задачи…';
		form.appendChild( input );

		const btnRow = document.createElement( 'div' );
		btnRow.className = 'fs-sb-create-btn-row';

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
			config.createTask( title )
				.then( ( data ) => assignTask( index, data.id, data.title ) )
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

	// ── Status / persistence ───────────────────────────────────────────────────
	function setStatus( state ) {
		if ( state === 'saving' ) {
			statusEl.className   = 'fs-sb-status saving';
			statusEl.textContent = 'Сохранение…';
		} else {
			statusEl.className = 'fs-sb-status';
			statusEl.innerHTML = '<span class="fs-sb-dot"></span> Все изменения сохранены';
		}
	}

	function save() {
		setStatus( 'saving' );
		Promise.resolve( config.persist( slots ) )
			.then( () => setStatus( 'saved' ) )
			.catch( ( msg ) => {
				showToast( String( msg ) || 'Ошибка сохранения', 'error' );
				setStatus( 'saved' );
			} );
	}
}
