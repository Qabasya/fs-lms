import '../_types.js';
import { showToast } from '../modules/toast.js';
import { DraftCreatorModal } from '../modals/draft-creator-modal.js';

/* global jQuery, fs_lms_vars */
const $ = jQuery;

/** Метаданные типов шагов (иконка + подпись + описание для модалки). */
const TYPES = {
	text:       { icon: '📝', label: 'Текст', desc: 'Теория, объяснение' },
	video:      { icon: '🎬', label: 'Видео', desc: 'Запись или внешний ролик' },
	material:   { icon: '📎', label: 'Материал', desc: 'Статья / вложение' },
	task:       { icon: '✏️', label: 'Задача', desc: 'Самопроверка, без сдачи' },
	work:       { icon: '🏠', label: 'Работа', desc: 'Домашка: сдача + грейдбук' },
	assessment: { icon: '🎓', label: 'Контрольная', desc: 'Экзамен: таймер, попытки' },
};

/** Допустимые типы шага по уровню сборки. */
const ALLOWED = {
	lesson:     [ 'text', 'video', 'material', 'task', 'work', 'assessment' ],
	work:       [ 'task' ],
	assessment: [ 'task' ],
};

/** Тип шага → kind кандидатов (для GetStepCandidates). Material = статьи. */
const CANDIDATE_KIND = { task: 'task', work: 'work', assessment: 'assessment', material: 'article' };

/** Инлайновые типы (добавляются сразу, без выбора кандидата). */
const INLINE = [ 'text', 'video' ];

let _uid = 0;
const tmpKey = () => `tmp_${ Date.now() }_${ ++_uid }`;

/**
 * StepBuilder — мастер-деталь конструктор шагов (вариант B прототипа).
 * Монтируется на `.fs-lms-step-builder` (data-lesson-id, data-subject, data-level).
 * Добавление шага — через модалку (T1.5.5): сетка типов → выбор кандидата (library)
 * или «Создать» (DraftCreatorModal для work/bank-задачи, иначе new-tab).
 */
export const StepBuilder = {

	init() {
		$( '.fs-lms-step-builder' ).each( ( _, el ) => createBuilder( $( el ) ) );
	},
};

function createBuilder( $mount ) {
	const lessonId = parseInt( $mount.data( 'lesson-id' ), 10 ) || 0;
	const subject  = String( $mount.data( 'subject' ) || '' );
	const level    = String( $mount.data( 'level' ) || 'lesson' );
	const allowed  = ALLOWED[ level ] || ALLOWED.lesson;

	let steps    = readInitialSteps( $mount );
	let selected = steps.length ? steps[ 0 ].key : null;

	const builder = { addInline, applyRef, subject };

	render();
	bind();

	function render() {
		$mount.html( `
			<div class="fs-sb">
				<div class="fs-sb-rail">
					<div class="fs-sb-list"></div>
					<button type="button" class="button fs-sb-addbtn">+ Добавить шаг</button>
				</div>
				<div class="fs-sb-editor"></div>
			</div>
			<div class="fs-sb-footer">
				<button type="button" class="button button-primary fs-sb-save">Сохранить шаги</button>
				<span class="fs-sb-status" aria-live="polite"></span>
			</div>
		` );
		renderList();
		renderEditor();
	}

	function renderList() {
		const $list = $mount.find( '.fs-sb-list' ).empty();
		if ( ! steps.length ) {
			$list.append( '<p class="fs-sb-empty">Шагов пока нет. «+ Добавить шаг».</p>' );
			return;
		}
		steps.forEach( ( step, i ) => {
			const m = TYPES[ step.type ];
			const $row = $( `
				<div class="fs-sb-item${ step.key === selected ? ' is-active' : '' }" draggable="true">
					<span class="fs-sb-grip">⠿</span><span class="fs-sb-num">${ i + 1 }</span>
					<span class="fs-sb-ico">${ m.icon }</span><span class="fs-sb-title"></span>
					<button type="button" class="fs-sb-del" title="Удалить">✕</button>
				</div>
			` );
			$row.attr( 'data-key', step.key );
			$row.find( '.fs-sb-title' ).text( stepLabel( step ) );
			$list.append( $row );
		} );
	}

	function renderEditor() {
		const $ed  = $mount.find( '.fs-sb-editor' ).empty();
		const step = current();
		if ( ! step ) {
			$ed.html( '<p class="fs-sb-hint">Выберите шаг слева или добавьте новый.</p>' );
			return;
		}
		const m = TYPES[ step.type ];
		$ed.append( `<h4 class="fs-sb-ed-head">${ m.icon } ${ m.label }</h4>` );

		if ( step.type === 'text' ) {
			const $ta = $( '<textarea class="fs-sb-field fs-sb-text" rows="8"></textarea>' ).val( step.payload.content || '' );
			$ta.on( 'input', () => { step.payload.content = $ta.val(); } );
			$ed.append( $ta );
		} else if ( step.type === 'video' ) {
			const $inp = $( '<input type="text" class="fs-sb-field fs-sb-url" placeholder="https://… (или запись из S3 позже)">' ).val( step.payload.url || '' );
			$inp.on( 'input', () => { step.payload.url = $inp.val(); renderList(); } );
			$ed.append( $inp );

			const $desc = $( '<textarea class="fs-sb-field" rows="2" placeholder="Описание к видео (необязательно)"></textarea>' ).val( step.payload.description || '' );
			$desc.on( 'input', () => { step.payload.description = $desc.val(); } );
			$ed.append( $desc );

			const $prov = $( '<input type="text" class="fs-sb-field" placeholder="Провайдер: youtube, vimeo, s3, …">' ).val( step.payload.provider || '' );
			$prov.on( 'input', () => { step.payload.provider = $prov.val(); } );
			$ed.append( $prov );

			const $slotWrap = $( '<label class="fs-sb-check"></label>' );
			const $slot = $( '<input type="checkbox">' ).prop( 'checked', !! step.payload.recording_slot );
			$slot.on( 'change', () => { step.payload.recording_slot = $slot.prop( 'checked' ); renderList(); } );
			$slotWrap.append( $slot ).append( ' Слот записи занятия (S3)' );
			$ed.append( $slotWrap );
		} else {
			const refId = parseInt( step.payload.ref || step.payload.article_id || 0, 10 );
			const $cur  = $( '<div class="fs-sb-ref-current"></div>' );
			$cur.append( $( '<span class="fs-sb-ref-title"></span>' ).text( step._title || ( refId ? `#${ refId }` : 'не выбрано' ) ) );
			if ( refId && step.type !== 'material' ) {
				$cur.append( ` <a class="fs-sb-ref-edit" href="post.php?post=${ refId }&action=edit" target="_blank" rel="noopener">редактировать ↗</a>` );
			}
			const $repl = $( '<button type="button" class="button fs-sb-replace">Выбрать / заменить</button>' );
			$repl.on( 'click', () => openAddModal( builder, allowed, subject, step.type, step.key ) );
			$ed.append( $cur ).append( $repl );
		}

		const $move = $( '<button type="button" class="button-link fs-sb-move">Переместить в другой урок →</button>' );
		$move.on( 'click', () => moveCurrentStep( step ) );
		$ed.append( $( '<div class="fs-sb-ed-actions"></div>' ).append( $move ) );
	}

	// ---------- добавление ----------
	function addInline( type ) {
		const step = { key: tmpKey(), type, payload: {} };
		steps.push( step );
		selected = step.key;
		render();
	}

	function applyRef( type, id, title, replaceKey ) {
		const step = replaceKey
			? steps.find( ( s ) => s.key === replaceKey )
			: ( steps.push( { key: tmpKey(), type, payload: {} } ), steps[ steps.length - 1 ] );
		if ( ! step ) {
			return;
		}
		if ( type === 'material' ) {
			step.payload.article_id = id;
		} else {
			step.payload.ref = id;
		}
		step._title = title;
		if ( type === 'task' ) {
			step.payload.source = step.payload.source || 'subject';
		}
		selected = step.key;
		render();
	}

	function removeStep( key ) {
		steps = steps.filter( ( s ) => s.key !== key );
		if ( selected === key ) {
			selected = steps.length ? steps[ 0 ].key : null;
		}
		render();
	}

	function current() {
		return steps.find( ( s ) => s.key === selected ) || null;
	}

	// ---------- сохранение ----------
	function persist() {
		return $.post( fs_lms_vars.ajaxurl, {
			action:      fs_lms_vars.ajax_actions.saveLessonSteps,
			security:    fs_lms_vars.nonces.authorLesson,
			lesson_id:   lessonId,
			subject_key: subject,
			steps:       steps.map( ( s ) => ( { key: s.key, type: s.type, payload: s.payload } ) ),
		} );
	}

	function save() {
		const $btn = $mount.find( '.fs-sb-save' ).prop( 'disabled', true );
		$mount.find( '.fs-sb-status' ).text( 'Сохранение…' );

		persist().done( ( resp ) => {
			if ( resp && resp.success ) {
				$mount.find( '.fs-sb-status' ).text( `Сохранено: ${ resp.data.count } шаг(ов)` );
				showToast( 'Шаги сохранены', 'success' );
			} else {
				showToast( ( resp && resp.data ) || 'Не удалось сохранить', 'error' );
			}
		} ).fail( () => showToast( 'Ошибка сети', 'error' ) )
			.always( () => $btn.prop( 'disabled', false ) );
	}

	// ---------- перенос шага в другой урок (T1.5.5) ----------
	function moveCurrentStep( step ) {
		if ( ! lessonId ) {
			showToast( 'Сначала сохраните урок', 'error' );
			return;
		}
		openMovePicker( subject, lessonId, ( targetId, targetTitle ) => {
			// Сохраняем текущее состояние, чтобы step.key существовал на сервере, затем переносим.
			$mount.find( '.fs-sb-status' ).text( 'Перенос…' );
			persist().done( ( resp ) => {
				if ( ! resp || ! resp.success ) {
					showToast( 'Не удалось сохранить перед переносом', 'error' );
					return;
				}
				$.post( fs_lms_vars.ajaxurl, {
					action:           fs_lms_vars.ajax_actions.moveLessonStep,
					security:         fs_lms_vars.nonces.authorLesson,
					source_lesson_id: lessonId,
					target_lesson_id: targetId,
					step_key:         step.key,
				} ).done( ( r ) => {
					if ( r && r.success ) {
						removeStep( step.key );
						showToast( `Шаг перемещён в «${ targetTitle }»`, 'success' );
					} else {
						showToast( ( r && r.data ) || 'Не удалось переместить', 'error' );
					}
				} ).fail( () => showToast( 'Ошибка сети', 'error' ) );
			} ).fail( () => showToast( 'Ошибка сети', 'error' ) );
		} );
	}

	// ---------- события ----------
	function bind() {
		$mount.on( 'click', '.fs-sb-addbtn', () => openAddModal( builder, allowed, subject ) );
		$mount.on( 'click', '.fs-sb-item', ( e ) => {
			if ( $( e.target ).is( '.fs-sb-del' ) ) {
				return;
			}
			selected = String( $( e.currentTarget ).data( 'key' ) );
			render();
		} );
		$mount.on( 'click', '.fs-sb-del', ( e ) => {
			e.stopPropagation();
			removeStep( String( $( e.currentTarget ).closest( '.fs-sb-item' ).data( 'key' ) ) );
		} );
		$mount.on( 'click', '.fs-sb-save', save );
		bindReorder();
	}

	function bindReorder() {
		$mount.on( 'dragstart', '.fs-sb-item', ( e ) => {
			e.originalEvent.dataTransfer.effectAllowed = 'move';
			$( e.currentTarget ).addClass( 'is-dragging' );
		} );
		$mount.on( 'dragend', '.fs-sb-item', ( e ) => {
			$( e.currentTarget ).removeClass( 'is-dragging' );
			const order = $mount.find( '.fs-sb-item' ).map( ( _, el ) => String( $( el ).data( 'key' ) ) ).get();
			steps.sort( ( a, b ) => order.indexOf( a.key ) - order.indexOf( b.key ) );
			renderList();
		} );
		$mount.on( 'dragover', '.fs-sb-list', ( e ) => {
			e.preventDefault();
			const $list = $( e.currentTarget );
			const $drag = $list.find( '.is-dragging' );
			if ( ! $drag.length ) {
				return;
			}
			const after = itemAfter( $list[ 0 ], e.originalEvent.clientY );
			if ( after === null ) {
				$list.append( $drag );
			} else {
				$drag.insertBefore( after );
			}
		} );
	}

	function itemAfter( container, y ) {
		const items = [ ...container.querySelectorAll( '.fs-sb-item:not(.is-dragging)' ) ];
		return items.reduce( ( closest, child ) => {
			const box    = child.getBoundingClientRect();
			const offset = y - box.top - box.height / 2;
			return ( offset < 0 && offset > closest.offset ) ? { offset, element: child } : closest;
		}, { offset: Number.NEGATIVE_INFINITY, element: null } ).element;
	}

	function stepLabel( step ) {
		if ( step.type === 'text' ) {
			const c = ( step.payload.content || '' ).replace( /<[^>]*>/g, '' ).trim();
			return c ? c.slice( 0, 48 ) : 'Текст';
		}
		if ( step.type === 'video' ) {
			if ( step.payload.recording_slot ) { return '⏺ Слот записи'; }
			return step.payload.url ? String( step.payload.url ) : 'Видео';
		}
		const ref = step.payload.ref || step.payload.article_id;
		return step._title || ( ref ? `${ TYPES[ step.type ].label } #${ ref }` : `${ TYPES[ step.type ].label } (не выбран)` );
	}
}

// ======================= Модалка «Добавить шаг» =======================

let _searchTimer = null;

/**
 * @param {Object} builder    {addInline, applyRef, subject}
 * @param {string[]} allowed   допустимые типы
 * @param {string} subject
 * @param {string} [presetType] открыть сразу пикер этого типа (для «заменить»)
 * @param {string} [replaceKey] ключ заменяемого шага
 */
function openAddModal( builder, allowed, subject, presetType, replaceKey ) {
	closeAddModal();
	const $ov = $( '<div class="fs-sb-ov"><div class="fs-sb-modal"></div></div>' ).appendTo( 'body' );
	const $modal = $ov.find( '.fs-sb-modal' );
	$ov.on( 'click', ( e ) => { if ( e.target === $ov[ 0 ] ) { closeAddModal(); } } );

	if ( presetType && CANDIDATE_KIND[ presetType ] ) {
		stepPicker( presetType );
	} else {
		stepTypes();
	}

	function stepTypes() {
		const grid = allowed.map( ( t ) => `
			<button type="button" class="fs-sb-typebtn" data-type="${ t }">
				<span class="fs-sb-ico">${ TYPES[ t ].icon }</span>
				<span><b>${ TYPES[ t ].label }</b><br><small>${ TYPES[ t ].desc }</small></span>
			</button>` ).join( '' );
		$modal.html( `<div class="fs-sb-mhead"><h3>Добавить шаг</h3><button type="button" class="fs-sb-x">×</button></div>
			<div class="fs-sb-mbody"><div class="fs-sb-typegrid">${ grid }</div></div>` );
		$modal.find( '.fs-sb-x' ).on( 'click', closeAddModal );
		$modal.find( '.fs-sb-typebtn' ).on( 'click', ( e ) => {
			const t = String( $( e.currentTarget ).data( 'type' ) );
			if ( INLINE.includes( t ) ) {
				builder.addInline( t );
				closeAddModal();
			} else {
				stepPicker( t );
			}
		} );
	}

	function stepPicker( type ) {
		const isTask = type === 'task';
		$modal.html( `
			<div class="fs-sb-mhead"><h3>${ TYPES[ type ].icon } ${ TYPES[ type ].label }</h3><button type="button" class="fs-sb-x">×</button></div>
			<div class="fs-sb-mbody">
				${ isTask ? `<div class="fs-sb-source"><label><input type="radio" name="fs-sb-src" value="subject" checked> Мои</label><label><input type="radio" name="fs-sb-src" value="bank"> Банк сайта</label></div>` : '' }
				<input type="text" class="fs-sb-field fs-sb-msearch" placeholder="Поиск в библиотеке…">
				<div class="fs-sb-mresults"></div>
			</div>
			<div class="fs-sb-mfoot">
				${ presetType ? '' : '<button type="button" class="fs-sb-back">← назад</button>' }
				<button type="button" class="button fs-sb-create">Создать новую</button>
			</div>
		` );
		$modal.find( '.fs-sb-x' ).on( 'click', closeAddModal );
		$modal.find( '.fs-sb-back' ).on( 'click', stepTypes );

		const fetchNow = () => fetchCandidates( type, subject, source(), String( $modal.find( '.fs-sb-msearch' ).val() ).trim(), $modal.find( '.fs-sb-mresults' ), ( id, title ) => {
			builder.applyRef( type, id, title, replaceKey );
			closeAddModal();
		} );

		$modal.find( '.fs-sb-msearch' ).on( 'input focus', () => {
			clearTimeout( _searchTimer );
			_searchTimer = setTimeout( fetchNow, 300 );
		} );
		$modal.find( '.fs-sb-source input' ).on( 'change', fetchNow );
		$modal.find( '.fs-sb-create' ).on( 'click', () => createNew( type, subject, source(), ( id, title ) => {
			builder.applyRef( type, id, title, replaceKey );
			closeAddModal();
		} ) );

		fetchNow();
	}

	function source() {
		return $modal.find( '.fs-sb-source input:checked' ).val() || 'subject';
	}
}

function closeAddModal() {
	$( '.fs-sb-ov' ).remove();
}

function fetchCandidates( type, subject, src, search, $results, onPick ) {
	const kind = CANDIDATE_KIND[ type ];
	if ( ! kind || ! subject ) {
		return;
	}
	$.post( fs_lms_vars.ajaxurl, {
		action:      fs_lms_vars.ajax_actions.getStepCandidates,
		security:    fs_lms_vars.nonces.authorLesson,
		subject_key: subject,
		kind,
		source:      type === 'task' ? src : 'subject',
		search,
	} ).done( ( resp ) => {
		$results.empty();
		const items = ( resp && resp.success && resp.data ) || [];
		if ( ! items.length ) {
			$results.append( '<div class="fs-sb-cand-empty">Ничего не найдено</div>' );
			return;
		}
		items.forEach( ( item ) => {
			$( '<div class="fs-sb-cand-opt"></div>' ).text( item.title )
				.on( 'click', () => onPick( parseInt( item.id, 10 ), item.title ) )
				.appendTo( $results );
		} );
	} );
}

// ======================= Пикер «Переместить в урок» (T1.5.5) =======================

/**
 * Пикер целевого урока для переноса шага. Использует тот же эндпоинт кандидатов
 * (kind=lesson, nonce authorLesson); текущий урок исключается из списка.
 *
 * @param {string}   subject
 * @param {number}   excludeId текущий урок (исключить)
 * @param {Function} onPick    (id, title) => void
 */
function openMovePicker( subject, excludeId, onPick ) {
	closeAddModal();
	const $ov    = $( '<div class="fs-sb-ov"><div class="fs-sb-modal"></div></div>' ).appendTo( 'body' );
	const $modal = $ov.find( '.fs-sb-modal' );
	$ov.on( 'click', ( e ) => { if ( e.target === $ov[ 0 ] ) { closeAddModal(); } } );

	$modal.html( `
		<div class="fs-sb-mhead"><h3>Переместить шаг в урок</h3><button type="button" class="fs-sb-x">×</button></div>
		<div class="fs-sb-mbody">
			<input type="text" class="fs-sb-field fs-sb-msearch" placeholder="Поиск урока…">
			<div class="fs-sb-mresults"></div>
		</div>
	` );
	$modal.find( '.fs-sb-x' ).on( 'click', closeAddModal );

	const run = () => fetchLessons( subject, String( $modal.find( '.fs-sb-msearch' ).val() ).trim(), excludeId, $modal.find( '.fs-sb-mresults' ), ( id, title ) => {
		onPick( id, title );
		closeAddModal();
	} );
	$modal.find( '.fs-sb-msearch' ).on( 'input focus', () => {
		clearTimeout( _searchTimer );
		_searchTimer = setTimeout( run, 300 );
	} );

	run();
}

function fetchLessons( subject, search, excludeId, $results, onPick ) {
	if ( ! subject ) {
		return;
	}
	$.post( fs_lms_vars.ajaxurl, {
		action:      fs_lms_vars.ajax_actions.getStepCandidates,
		security:    fs_lms_vars.nonces.authorLesson,
		subject_key: subject,
		kind:        'lesson',
		source:      'subject',
		search,
	} ).done( ( resp ) => {
		$results.empty();
		const items = ( ( resp && resp.success && resp.data ) || [] ).filter( ( it ) => parseInt( it.id, 10 ) !== excludeId );
		if ( ! items.length ) {
			$results.append( '<div class="fs-sb-cand-empty">Нет других уроков</div>' );
			return;
		}
		items.forEach( ( item ) => {
			$( '<div class="fs-sb-cand-opt"></div>' ).text( item.title )
				.on( 'click', () => onPick( parseInt( item.id, 10 ), item.title ) )
				.appendTo( $results );
		} );
	} );
}

/**
 * «Создать новую»: бесшовно через DraftCreatorModal (черновик title-only) для всех типов —
 * work / subject-task / bank-problem / assessment / material(article). Детали заполняются
 * при правке созданного черновика. New-tab `post-new.php` остаётся лишь фолбэком, если модалки нет.
 */
function createNew( type, subject, src, onCreated ) {
	let refType = null;
	if ( type === 'work' ) {
		refType = 'work';
	} else if ( type === 'assessment' ) {
		refType = 'assessment';
	} else if ( type === 'material' ) {
		refType = 'material';
	} else if ( type === 'task' ) {
		refType = 'bank' === src ? 'problem' : 'task';
	}

	if ( refType && DraftCreatorModal && $( '#fs-lms-draft-creator-modal' ).length ) {
		const $field = $( '<div></div>' ).attr( 'data-subject', subject );
		DraftCreatorModal.open( { refType, $field, onCreated: ( id, title ) => onCreated( id, title ) } );
		return;
	}

	const cptMap = {
		work:       `${ subject }_works`,
		assessment: `${ subject }_assessments`,
		material:   `${ subject }_articles`,
		task:       src === 'bank' ? 'fs_lms_problems' : `${ subject }_tasks`,
	};
	const cpt = cptMap[ type ];
	if ( cpt ) {
		window.open( `post-new.php?post_type=${ cpt }`, '_blank', 'noopener' );
		showToast( 'Создайте на открытой вкладке, затем выберите из библиотеки', 'info' );
	}
}

function readInitialSteps( $mount ) {
	const raw = $mount.find( '.fs-sb-data' ).first().text();
	if ( ! raw ) {
		return [];
	}
	try {
		const parsed = JSON.parse( raw );
		return Array.isArray( parsed ) ? parsed.map( ( s ) => ( {
			key:     String( s.key || '' ),
			type:    String( s.type || '' ),
			payload: ( s.payload && typeof s.payload === 'object' ) ? s.payload : {},
			_title:  s._title || '',
		} ) ).filter( ( s ) => TYPES[ s.type ] ) : [];
	} catch ( e ) {
		return [];
	}
}
