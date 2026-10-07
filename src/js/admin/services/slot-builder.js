import '../_types.js';
import { icoPlus, icoX, icoReplace, icoImport, icoCaret, icoTrash } from '../../common/icons.js';
import { escapeHtml as esc } from '../modules/utils.js';
import { openPicker, expandPicked } from '../modules/picker.js';
import { readSteps } from './step-editor.js';
import { showToast } from '../modules/toast.js';
import { ConfirmModal } from '../modals/confirm-modal.js';
import { BulkTaskModal } from '../modals/bulk-task-modal.js';

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

function buildSlotAnswerHtml( data ) {
	const lbl = ( t ) => `<p class="fs-sb-section-label">${ t }</p>`;

	if ( data.options && Array.isArray( data.options.options ) && data.options.options.length ) {
		const html = data.options.options.map( ( o ) =>
			'<div class="fs-sb-task-option' + ( o.correct ? ' is-correct' : '' ) + '">' +
			'<span class="fs-sb-opt-mark">' + ( o.correct ? '✓' : '·' ) + '</span>' +
			'<span>' + esc( String( o.text || '' ) ) + '</span>' +
			'</div>'
		).join( '' );
		return lbl( 'Варианты ответа' ) + '<div class="fs-sb-task-options">' + html + '</div>';
	}

	if ( data.pairs && Array.isArray( data.pairs.pairs ) && data.pairs.pairs.length ) {
		const html = data.pairs.pairs.map( ( p ) =>
			'<div class="fs-sb-task-pair">' +
			'<span class="fs-sb-pair-l">' + esc( String( p.left || '' ) ) + '</span>' +
			'<span class="fs-sb-pair-arrow">→</span>' +
			'<span class="fs-sb-pair-r">' + esc( String( p.right || '' ) ) + '</span>' +
			'</div>'
		).join( '' );
		return lbl( 'Сопоставление' ) + '<div class="fs-sb-task-pairs">' + html + '</div>';
	}

	if ( data.order_items && Array.isArray( data.order_items.items ) && data.order_items.items.length ) {
		const html = data.order_items.items.map( ( item ) => '<li>' + esc( String( item ) ) + '</li>' ).join( '' );
		return lbl( 'Порядок элементов' ) + '<ol class="fs-sb-task-order">' + html + '</ol>';
	}

	if ( data.gap_text ) {
		const processed = esc( data.gap_text ).replace( /\[\[([^\]]+)\]\]/g, '<span class="fs-sb-gap-fill">$1</span>' );
		return lbl( 'Текст с пропусками' ) + '<div class="fs-sb-task-gap">' + processed + '</div>';
	}

	if ( Array.isArray( data.three_in_one ) && data.three_in_one.length ) {
		const html = data.three_in_one.map( ( sub, i ) =>
			'<div class="fs-sb-subtask">' +
			'<div class="fs-sb-subtask-num">Подзадание ' + ( i + 1 ) + '</div>' +
			( sub.condition ? '<div class="fs-sb-subtask-cond">' + sub.condition + '</div>' : '' ) +
			( sub.answer ? '<div class="fs-sb-subtask-ans">' + esc( sub.answer ) + '</div>' : '' ) +
			'</div>'
		).join( '' );
		return lbl( 'Подзадания' ) + '<div class="fs-sb-subtasks">' + html + '</div>';
	}

	if ( data.answer_html ) {
		return lbl( 'Ответ' ) + '<div class="fs-sb-task-answer">' + data.answer_html + '</div>';
	}

	return '';
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
 * @param {Function}   [config.bulkSearch] (query, scope, page) => Promise<Object[]>  Страница кандидатов
 *                                            для окна «Массовое добавление заданий». Задан — кнопка
 *                                            «Добавить задачу» получает меню с этим пунктом (как
 *                                            «Добавить» в конструкторе курса); не задан — обычная кнопка.
 * @param {Function}    config.preview  (taskId) => Promise<Object>
 * @param {string}     [config.subjectKey]    Предмет — прокидывается в URL «Создать задачу»
 *                                            (post-new.php?fs_lms_subject=…), чтобы новый
 *                                            черновик в банке сразу был привязан к предмету.
 * @param {Function}   [config.suggestTitle] (filledCount) => string  Подсказка заголовка нового
 *                                            черновика (напр. «{название работы}-{N}»).
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

	const canBulkAdd = typeof config.bulkSearch === 'function';

	el.innerHTML = `
		<div class="fs-sb-builder">
			<div class="fs-sb-tree">
				<div class="fs-sb-tree-head">
					<span class="fs-sb-th-title">${ esc( config.treeTitle || 'Структура' ) }</span>
					<span class="fs-sb-th-count" data-slot-count></span>
				</div>
				<div class="fs-sb-tree-scroll" data-slot-list></div>
				<div class="fs-sb-tree-add">${ canBulkAdd ? `
					<div class="add-wrap" data-add-wrap>
						<div class="add-menu">
							<div class="add-menu-box">
								<button type="button" class="add-opt" data-add-slot>${ icoPlus( 13 ) }Задача</button>
								<button type="button" class="add-opt" data-add-bulk>${ icoImport( 13 ) }Массовое добавление заданий</button>
							</div>
						</div>
						<button type="button" class="button button-primary add-main" data-add-slot>
							${ icoPlus( 13 ) }
							Добавить задачу
							${ icoCaret( 10, 'add-caret' ) }
						</button>
					</div>` : `
					<button type="button" class="button button-primary" data-add-slot>${ icoPlus( 13 ) } Добавить задачу</button>` }
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

	// Меню «Добавить задачу» раскрывается по :hover/:focus-within; после выбора прячем
	// его до ухода курсора — иначе оно осталось бы висеть над открытым окном.
	const addWrap      = el.querySelector( '[data-add-wrap]' );
	const closeAddMenu = () => {
		if ( ! addWrap ) { return; }
		addWrap.classList.add( 'is-closed' );
		document.activeElement?.blur?.();
		addWrap.addEventListener( 'mouseleave', () => addWrap.classList.remove( 'is-closed' ), { once: true } );
	};

	el.querySelectorAll( '[data-add-slot]' ).forEach( ( btn ) => {
		btn.addEventListener( 'click', () => { closeAddMenu(); addSlot(); } );
	} );
	el.querySelector( '[data-add-bulk]' )?.addEventListener( 'click', () => { closeAddMenu(); openBulkAdd(); } );

	const api = {
		getSlots:     () => slots,
		replaceSlots: ( arr, active ) => {
			slots       = arr;
			activeIndex = ( active === undefined ) ? ( slots.length ? 0 : -1 ) : active;
			render();
		},
		assignManyAt,
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

	/**
	 * «Массовое добавление заданий»: окно со списком и чекбоксами, выбранное
	 * добавляется в работу одним сохранением.
	 */
	function openBulkAdd() {
		BulkTaskModal.open( {
			fetchFn:  config.bulkSearch,
			takenIds: slots.filter( ( s ) => s.taskId > 0 ).map( ( s ) => s.taskId ),
		} ).then( addMany, () => {} );
	}

	/**
	 * Добавляет набор заданий: сначала занимает пустые слоты, остальное дописывает
	 * в конец. Связка 19–21 раскладывается на подзадания, как при одиночном выборе
	 * ({@link assignPicked}); задания, уже стоящие в работе, пропускаются.
	 *
	 * @param {Object[]} items Элементы кандидатов из окна выбора.
	 */
	function addMany( items ) {
		const { fresh, skipped } = expandPicked(
			items,
			slots.filter( ( s ) => s.taskId > 0 ).map( ( s ) => s.taskId )
		);

		if ( ! fresh.length ) {
			showToast( 'Выбранные задания уже есть в работе', 'error' );
			return;
		}

		let firstIndex = -1;
		fresh.forEach( ( task ) => {
			let index = slots.findIndex( ( s ) => ! s.taskId );
			if ( index < 0 ) {
				slots.push( newSlot( slots.length ) );
				index = slots.length - 1;
			}
			slots[ index ].taskId = task.id;
			slots[ index ].title  = task.title;
			if ( firstIndex < 0 ) { firstIndex = index; }
		} );

		activeIndex = firstIndex;
		render();
		save();
		showToast(
			`Добавлено заданий: ${ fresh.length }` + ( skipped ? `, уже были в работе: ${ skipped }` : '' ),
			'success'
		);
	}

	async function removeSlot( index ) {
		if ( slots[ index ]?.taskId > 0 ) {
			try {
				await ConfirmModal.confirm( { title: 'Удалить этот слот?', message: 'Вы уверены в удалении выбранной задачи?', isDanger: true, confirmText: 'Удалить' } );
			} catch {
				return;
			}
		}
		slots.splice( index, 1 );
		if ( activeIndex >= slots.length ) {
			activeIndex = Math.max( 0, slots.length - 1 );
		}
		render();
		save();
	}

	/**
	 * @returns {boolean} Слот изменён (false — задача уже стоит в другом слоте)
	 */
	function assignTask( index, taskId, title ) {
		// Запрет дублей: одна и та же задача не может стоять в двух слотах (задача 6).
		// Пустой слот (taskId 0) — не задача: «Очистить» при другом пустом слоте
		// раньше упиралось в «дубль» и одновременно рапортовало об удалении.
		const dup = taskId > 0 && slots.some( ( slot, i ) => i !== index && slot.taskId === taskId );
		if ( dup ) {
			showToast( 'Эта задача уже добавлена', 'error' );
			return false;
		}
		slots[ index ].taskId = taskId;
		slots[ index ].title  = title;
		activeIndex = index;
		renderLeft();
		renderCenter();
		save();
		return true;
	}

	/**
	 * Прямое присвоение нескольких {index, taskId, title} без splice/дубль-тоста
	 * и без промежуточных save() на каждую пару — один save() на весь набор.
	 * Нужно EGE-конструктору (.docs/Tasks.md, задача C): позиционные слоты
	 * 19/20/21 сдвинулись бы при использовании assignPicked()/splice(), т.к. там
	 * индекс слота жёстко равен номеру позиции экзамена.
	 *
	 * @param {Array<{index:number, taskId:number, title:string}>} pairs
	 */
	function assignManyAt( pairs ) {
		const valid = pairs.filter( ( p ) => p.index >= 0 && p.index < slots.length );
		if ( ! valid.length ) { return; }

		valid.forEach( ( p ) => {
			slots[ p.index ].taskId = p.taskId;
			slots[ p.index ].title  = p.title;
		} );
		activeIndex = valid[ 0 ].index;
		render();
		save();
	}

	/**
	 * Связка 19-21: parent разворачивается в 3 слота (children) вместо одного —
	 * см. .docs/Tasks.md, §3.3/§3.5. Обычный (не-связка) пик идёт через assignTask.
	 *
	 * @param {number} index    Слот, в который пикали.
	 * @param {number} taskId   ID выбранного поста.
	 * @param {string} title    Заголовок выбранного поста.
	 * @param {Object} item     Исходный элемент кандидата (может нести bundle_children).
	 */
	function assignPicked( index, taskId, title, item ) {
		const children = item && Array.isArray( item.bundle_children ) ? item.bundle_children : null;
		if ( ! children || ! children.length ) {
			assignTask( index, taskId, title );
			return;
		}

		const newIds = children.map( ( c ) => c.id );
		const dup = slots.some( ( slot, i ) => i !== index && newIds.includes( slot.taskId ) );
		if ( dup ) {
			showToast( 'Одно из подзаданий связки уже добавлено', 'error' );
			return;
		}

		const replacement = children.map( ( c, i ) => {
			const s = newSlot( index + i );
			s.taskId = c.id;
			s.title  = c.title;
			return s;
		} );

		slots.splice( index, 1, ...replacement );
		activeIndex = index;
		render();
		save();
		showToast( 'Связка разложена на ' + replacement.length + ' слота', 'success' );
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
			link.className   = 'button';
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
		removeBtn.type      = 'button';
		removeBtn.className = 'button fs-sb-btn-danger';
		removeBtn.innerHTML = icoX( 13 ) + ' Удалить слот';
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

		const ansInner = buildSlotAnswerHtml( data );
		if ( ansInner ) {
			const sec = document.createElement( 'div' );
			sec.className = 'fs-sb-task-section';
			sec.innerHTML = ansInner;
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

		// config.onPick (опц.) — перехват пика до дефолтного assignPicked(); должен
		// вернуть true, если сам обработал присвоение (EGE-связка, задача C).
		const handlePick = ( id, title, source, item ) => {
			if ( typeof config.onPick === 'function' && config.onPick( index, id, title, item ) ) {
				return;
			}
			assignPicked( index, id, title, item );
		};

		const pickBtn = document.createElement( 'button' );
		pickBtn.type      = 'button';
		pickBtn.className = 'button';
		pickBtn.innerHTML = slot.taskId
			? icoReplace( 13 ) + ' Заменить задачу'
			: icoImport( 13 ) + ' Выбрать из банка ' + icoCaret( 10 );
		pickBtn.addEventListener( 'click', () => {
			openPicker( pickBtn, {
				placeholder: 'Поиск задачи…',
				emptyText:   'Задачи не найдены',
				fetchFn:     ( q, scope ) => config.search( q, index, scope ),
				// Дропдаун по умолчанию сужен до предмета (упрощение поиска, не запрет) —
				// «Все задания» переключает на полный список (предмет + банк).
				browseAllLabel: 'Все задания',
				onPick:      handlePick,
			} );
		} );
		actions.appendChild( pickBtn );

		// Публичные задачи предмета (опубликованные в тренажёре) — отдельный пикер
		// с тем же поиском: в общем списке они идут вперемешку с банком и, если их
		// сотня-другая, теряются за лимитом выдачи.
		const pickPublicBtn = document.createElement( 'button' );
		pickPublicBtn.type      = 'button';
		pickPublicBtn.className = 'button';
		pickPublicBtn.innerHTML = icoImport( 13 ) + ' Выбрать из публичных задач ' + icoCaret( 10 );
		pickPublicBtn.addEventListener( 'click', () => {
			openPicker( pickPublicBtn, {
				placeholder: 'Поиск по публичным задачам…',
				emptyText:   'Публичные задачи не найдены',
				scope:       'public',
				fetchFn:     ( q, scope ) => config.search( q, index, scope ),
				onPick:      handlePick,
			} );
		} );
		actions.appendChild( pickPublicBtn );

		if ( ! slot.taskId ) {
			const createBtn = document.createElement( 'button' );
			createBtn.type        = 'button';
			createBtn.className   = 'button button-primary';
			createBtn.textContent = 'Создать задачу';
			createBtn.addEventListener( 'click', () => {
				const adminBase = fs_lms_vars.ajaxurl.replace( 'admin-ajax.php', '' );

				// Предмет и (для работы) предполагаемый заголовок «{название работы}-{N}» —
				// новый черновик в банке сразу цепляет предмет и получает подсказку заголовка,
				// автор принимает её или правит перед публикацией (ProblemsController).
				const params = new URLSearchParams( { post_type: 'fs_lms_problems' } );
				if ( config.subjectKey ) {
					params.set( 'fs_lms_subject', config.subjectKey );
				}
				if ( typeof config.suggestTitle === 'function' ) {
					const filledCount = slots.filter( ( s ) => s.taskId > 0 ).length;
					const suggested   = config.suggestTitle( filledCount );
					if ( suggested ) {
						params.set( 'fs_lms_suggested_title', suggested );
					}
				}

				const newWin    = window.open( adminBase + 'post-new.php?' + params.toString(), '_blank' );
				let lastHref    = '';
				const poll = setInterval( () => {
					if ( newWin && ! newWin.closed ) {
						try { lastHref = newWin.location.href; } catch ( _e ) { /* навигация */ }
					}
					const search = lastHref.includes( '?' ) ? lastHref.split( '?' )[ 1 ] : '';
					const params = new URLSearchParams( search );
					const postId = params.get( 'post' );
					if ( postId && params.get( 'action' ) === 'edit' ) {
						clearInterval( poll );
						const acts   = fs_lms_vars.ajax_actions;
						const nonce  = fs_lms_vars.nonces.authorAssessment;
						post( acts.getTaskPreview, nonce, { task_id: postId } )
							.then( ( data ) => assignTask( index, parseInt( postId, 10 ), data.title || ( 'Задача #' + postId ) ) )
							.catch( () => assignTask( index, parseInt( postId, 10 ), 'Задача #' + postId ) );
						return;
					}
					if ( newWin && newWin.closed ) { clearInterval( poll ); }
				}, 800 );
			} );
			actions.appendChild( createBtn );
		}

		if ( slot.taskId > 0 ) {
			const clearBtn = document.createElement( 'button' );
			clearBtn.type      = 'button';
			clearBtn.className = 'button fs-sb-btn-danger';
			clearBtn.innerHTML = icoTrash( 13 ) + ' Очистить';
			clearBtn.addEventListener( 'click', () => {
				if ( assignTask( index, 0, '' ) ) { showToast( 'Задача удалена', 'success' ); }
			} );
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
			.then( ( data ) => {
				setStatus( 'saved' );
				// Хук получения ответа сохранения (например, вердикт полноты ЕГЭ).
				if ( typeof config.onPersisted === 'function' ) { config.onPersisted( data ); }
			} )
			.catch( ( msg ) => {
				showToast( String( msg ) || 'Ошибка сохранения', 'error' );
				setStatus( 'saved' );
			} );
	}
}
