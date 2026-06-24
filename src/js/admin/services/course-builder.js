import '../_types.js';
import { showToast } from '../modules/toast.js';
import { createStepEditor, esc, ajax, tmpKey, openPicker } from './step-editor.js';
import { createPersistence } from './course-persistence.js';
import { ConfirmModal } from '../modals/confirm-modal.js';

/* global jQuery, fs_lms_vars */
const $ = jQuery;

/**
 * CourseBuilder — Stepik-style конструктор курса (канон: design_handoff_course_builder/).
 * Дерево «модули → уроки» слева + редактор шагов выбранного урока справа.
 * Монтируется на #fs-lms-course-builder. Сохранение — AJAX (см. CourseBuilderCallbacks
 * + reuse SaveLessonSteps). Контент шага хранится в нашей модели LessonDTO.steps[].
 *
 * Маппинг типов шага (дизайн → наш StepType): Лекция→text, Видео→video, Файл→material,
 * Практика→work (ссылка), Тест→assessment (ссылка).
 */

// Редактор шагов (лента чипов + редактор шага + автосейв) вынесен в общий
// `step-editor.js` — единый источник UI для курс-билдера и метабокса урока.
const acts = () => fs_lms_vars.ajax_actions;

const GRIP_SVG = '<svg width="12" height="12" viewBox="0 0 12 12"><path fill="currentColor" d="M4 2.5h1v1H4zm3 0h1v1H7zM4 5.5h1v1H4zm3 0h1v1H7zM4 8.5h1v1H4zm3 0h1v1H7z"/></svg>';

export const CourseBuilder = {
	init() {
		const mount = document.getElementById( 'fs-lms-course-builder' );
		if ( mount ) {
			createApp( mount );
		}
	},
};

// ══════════════════════════════════════════════════════════════
function createApp( mount ) {
	const courseId = parseInt( mount.dataset.courseId, 10 ) || 0;
	const subject  = String( mount.dataset.subject || '' );

	const state = { course: null, activeLessonId: null, activeModuleId: null };
	let stepEditor = null; // активный экземпляр createStepEditor() для выбранного урока
	const persist  = createPersistence( { courseId, mount, state, onPublishToggle: () => renderEditor() } );

	if ( courseId > 0 ) {
		mount.innerHTML = '<p class="fs-cb-loading">Загрузка курса…</p>';
		ajax( acts().getCourseBuilder, { course_id: courseId } )
			.then( ( tree ) => { state.course = tree; bootstrap(); } )
			.catch( ( msg ) => { mount.innerHTML = `<div class="notice notice-error"><p>${ esc( msg ) }</p></div>`; } );
	} else if ( subject ) {
		renderCreateCourse();
	} else {
		mount.innerHTML = '<div class="notice notice-warning"><p>Откройте конструктор из библиотеки «Курсы» или укажите предмет.</p></div>';
	}

	// ── создание курса ──
	function renderCreateCourse() {
		mount.innerHTML = `
			<div class="fs-cb-create">
				<h2>Новый курс</h2>
				<input type="text" class="fs-cb-create-title" placeholder="Название курса">
				<button type="button" class="button button-primary fs-cb-create-btn">Создать курс</button>
			</div>`;
		mount.querySelector( '.fs-cb-create-btn' ).addEventListener( 'click', () => {
			const title = mount.querySelector( '.fs-cb-create-title' ).value.trim();
			ajax( acts().createCourseDraft, { subject_key: subject, title } )
				.then( ( data ) => {
					const url = new URL( window.location.href );
					url.searchParams.set( 'course', data.id );
					url.searchParams.delete( 'subject' );
					window.location.href = url.toString();
				} )
				.catch( ( msg ) => showToast( msg, 'error' ) );
		} );
	}

	// ── каркас приложения ──
	function bootstrap() {
		const first = firstLesson();
		state.activeLessonId = first ? first.id : null;
		renderShell();
		renderTree();
		renderEditor();
	}

	function renderShell() {
		const c = state.course;
		mount.innerHTML = `
			<div class="course-strip">
				<div class="course-thumb" data-thumb>${ esc( ( c.title || '?' ).slice( 0, 2 ) ) }</div>
				<div class="course-strip-main">
					<input class="cs-title-input" value="${ esc( c.title ) }" placeholder="Название курса">
					<div class="cs-meta">
						<span><b data-module-count>${ c.modules.length }</b> модулей</span>
						<span><b data-lesson-count>${ totalLessons() }</b> уроков</span>
						<button type="button" class="course-flag${ 'publish' === c.status ? ' published' : '' }" data-toggle-course>
							${ 'publish' === c.status ? 'Опубликован' : 'Черновик' }
						</button>
					</div>
				</div>
			</div>
			<div class="postbox fs-cb-postbox">
				<div class="postbox-header"><h2>Конструктор курса</h2></div>
				<div class="builder">
					<div class="tree-pane">
						<div class="tree-head">
							<span class="th-title">Структура курса</span>
							<span class="th-count" data-tree-count></span>
						</div>
						<div class="tree-scroll" data-tree></div>
						<div class="tree-add">
							<button type="button" class="button" data-add-lesson>+ Урок</button>
							<button type="button" class="button" data-import-lesson>Импорт урока</button>
							<button type="button" class="button" data-add-module>+ Модуль</button>
						</div>
					</div>
					<div class="editor-pane" data-editor></div>
				</div>
			</div>`;
		mount.querySelector( '[data-add-lesson]' ).addEventListener( 'click', addLesson );
		mount.querySelector( '[data-import-lesson]' ).addEventListener( 'click', importLessonFlow );
		mount.querySelector( '[data-add-module]' ).addEventListener( 'click', addModule );

		const titleInput = mount.querySelector( '.cs-title-input' );
		const thumb      = mount.querySelector( '[data-thumb]' );
		titleInput.addEventListener( 'input', () => {
			c.title = titleInput.value;
			thumb.textContent = ( c.title || '?' ).slice( 0, 2 );
			persist.scheduleCourseMeta();
		} );
		mount.querySelector( '[data-toggle-course]' ).addEventListener( 'click', function() {
			c.status = 'publish' === c.status ? 'draft' : 'publish';
			const pub = 'publish' === c.status;
			this.textContent = pub ? 'Опубликован' : 'Черновик';
			this.classList.toggle( 'published', pub );
			persist.saveCourseMeta();
		} );
	}

	// ── helpers ──
	function totalLessons() {
		return state.course.modules.reduce( ( n, m ) => n + m.lessons.length, 0 );
	}
	function firstLesson() {
		for ( const m of state.course.modules ) {
			if ( m.lessons.length ) { return m.lessons[ 0 ]; }
		}
		return null;
	}
	function findLesson( id ) {
		for ( const m of state.course.modules ) {
			const l = m.lessons.find( ( x ) => x.id === id );
			if ( l ) { return { lesson: l, module: m }; }
		}
		return null;
	}

	// ══════════ TREE ══════════
	function renderTree() {
		const root = mount.querySelector( '[data-tree]' );
		root.innerHTML = '';

		state.course.modules.forEach( ( mod, mi ) => {
			if ( typeof mod.collapsed === 'undefined' ) { mod.collapsed = false; }
			const modEl = document.createElement( 'div' );
			modEl.className = 'module' + ( mod.collapsed ? ' collapsed' : '' ) + ( mod.id === state.activeModuleId ? ' active' : '' );

			const head = document.createElement( 'div' );
			head.className = 'module-head';
			head.innerHTML = `
				<span class="mod-grip">${ GRIP_SVG }</span>
				<span class="mod-caret"><svg width="12" height="12" viewBox="0 0 12 12"><path fill="currentColor" d="M3 4.5 6 8l3-3.5z"/></svg></span>
				<span class="mod-num">${ mi + 1 }</span>
				<span class="mod-main"><span class="mod-title"></span><span class="mod-desc"></span></span>`;
			head.querySelector( '.mod-title' ).textContent = mod.title;
			const descEl = head.querySelector( '.mod-desc' );
			if ( mod.description ) { descEl.textContent = mod.description; } else { descEl.remove(); }
			head.querySelector( '.mod-caret' ).addEventListener( 'click', ( e ) => { e.stopPropagation(); mod.collapsed = ! mod.collapsed; renderTree(); } );
			head.addEventListener( 'click', () => selectModule( mod.id ) );
			modEl.appendChild( head );
			modEl.draggable = true;
			attachModuleDrag( modEl, mod );

			const wrap = document.createElement( 'div' );
			wrap.className = 'module-lessons';
			mod.lessons.forEach( ( les ) => {
				const el = document.createElement( 'div' );
				el.className = 'lesson' + ( les.id === state.activeLessonId ? ' active' : '' );
				el.draggable = true;
				el.innerHTML = `
					<span class="les-grip">${ GRIP_SVG }</span>
					<span class="les-num">${ mi + 1 }.${ mod.lessons.indexOf( les ) + 1 }</span>
					<span class="les-title"></span>
					<span class="les-steps">${ les.steps.length }</span>`;
				el.querySelector( '.les-title' ).textContent = les.title;
				el.addEventListener( 'click', () => selectLesson( les.id ) );
				attachLessonDrag( el, les, mod );
				wrap.appendChild( el );
			} );
			modEl.appendChild( wrap );
			root.appendChild( modEl );
		} );

		mount.querySelector( '[data-tree-count]' ).textContent =
			`${ state.course.modules.length } модулей · ${ totalLessons() } уроков`;
	}

	// ── drag&drop ──
	let dragLessonId = null;
	let dragModuleId = null;

	function attachDnD( el, { onStart, onEnd, isValidTarget, onDrop, containsCheck = false } ) {
		el.addEventListener( 'dragstart', ( e ) => {
			onStart();
			el.classList.add( 'dragging' );
			e.dataTransfer.effectAllowed = 'move';
			e.stopPropagation();
		} );
		el.addEventListener( 'dragend', () => {
			if ( onEnd ) { onEnd(); }
			el.classList.remove( 'dragging' );
			mount.querySelectorAll( '.drop-before,.drop-after' ).forEach( ( n ) => n.classList.remove( 'drop-before', 'drop-after' ) );
		} );
		el.addEventListener( 'dragover', ( e ) => {
			if ( ! isValidTarget() ) { return; }
			e.preventDefault(); e.stopPropagation();
			const r = el.getBoundingClientRect();
			const after = ( e.clientY - r.top ) > r.height / 2;
			el.classList.toggle( 'drop-after', after );
			el.classList.toggle( 'drop-before', ! after );
		} );
		el.addEventListener( 'dragleave', ( ev ) => {
			if ( containsCheck && el.contains( ev.relatedTarget ) ) { return; }
			el.classList.remove( 'drop-before', 'drop-after' );
		} );
		el.addEventListener( 'drop', ( e ) => {
			if ( ! isValidTarget() ) { return; }
			e.preventDefault(); e.stopPropagation();
			const r = el.getBoundingClientRect();
			const after = ( e.clientY - r.top ) > r.height / 2;
			el.classList.remove( 'drop-before', 'drop-after' );
			onDrop( after );
		} );
	}

	function attachLessonDrag( el, les, mod ) {
		attachDnD( el, {
			onStart:       () => { dragLessonId = les.id; dragModuleId = null; },
			onEnd:         () => { dragLessonId = null; },
			isValidTarget: () => ! dragModuleId && dragLessonId !== les.id,
			onDrop:        ( after ) => moveLesson( dragLessonId, mod.id, les.id, after ),
		} );
	}

	function attachModuleDrag( el, mod ) {
		attachDnD( el, {
			onStart:       () => { dragModuleId = mod.id; dragLessonId = null; },
			onEnd:         () => { dragModuleId = null; },
			isValidTarget: () => !! dragModuleId && dragModuleId !== mod.id,
			onDrop:        ( after ) => moveModule( dragModuleId, mod.id, after ),
			containsCheck: true,
		} );
	}

	function moveModule( fromId, toId, after ) {
		if ( fromId === toId ) { return; }
		const fromIdx = state.course.modules.findIndex( ( m ) => m.id === fromId );
		if ( fromIdx < 0 ) { return; }
		const [ moved ] = state.course.modules.splice( fromIdx, 1 );
		const toIdx = state.course.modules.findIndex( ( m ) => m.id === toId );
		state.course.modules.splice( toIdx >= 0 && after ? toIdx + 1 : Math.max( 0, toIdx ), 0, moved );
		renderTree();
		persist.saveStructure( 'Модуль перемещён' );
	}

	function moveLesson( lessonId, targetModuleId, targetLessonId, after ) {
		if ( ! lessonId || lessonId === targetLessonId ) { return; }
		let moved = null;
		for ( const m of state.course.modules ) {
			const i = m.lessons.findIndex( ( l ) => l.id === lessonId );
			if ( i > -1 ) { moved = m.lessons.splice( i, 1 )[ 0 ]; break; }
		}
		if ( ! moved ) { return; }
		const tm = state.course.modules.find( ( m ) => m.id === targetModuleId );
		let ti = tm.lessons.findIndex( ( l ) => l.id === targetLessonId );
		if ( ti < 0 ) { ti = tm.lessons.length - 1; }
		tm.lessons.splice( after ? ti + 1 : ti, 0, moved );
		renderTree();
		persist.saveStructure( 'Урок перемещён' );
	}

	// ══════════ EDITOR ══════════
	function selectLesson( id ) {
		state.activeLessonId = id;
		state.activeModuleId = null;
		renderTree();
		renderEditor();
	}

	function selectModule( id ) {
		state.activeModuleId = id;
		state.activeLessonId = null;
		renderTree();
		renderEditor();
	}

	function renderEditor() {
		if ( stepEditor ) { stepEditor.destroy(); stepEditor = null; }
		const pane = mount.querySelector( '[data-editor]' );

		const f = state.activeLessonId ? findLesson( state.activeLessonId ) : null;
		if ( f ) { renderLessonEditor( pane, f ); return; }

		const mod = state.activeModuleId ? state.course.modules.find( ( m ) => m.id === state.activeModuleId ) : null;
		if ( mod ) { renderModuleEditor( pane, mod ); return; }

		pane.innerHTML = '<div class="editor-empty">Выберите урок или модуль слева</div>';
	}

	function renderLessonEditor( pane, f ) {
		const { lesson, module } = f;
		const mi = state.course.modules.indexOf( module ) + 1;
		const li = module.lessons.indexOf( lesson ) + 1;

		pane.innerHTML = `
			<div class="editor-top">
				<div class="editor-breadcrumb">
					<span>${ esc( state.course.title ) }</span><span>›</span>
					<span>Модуль ${ mi }: ${ esc( module.title ) }</span><span>›</span>
					<b>Урок ${ mi }.${ li }</b>
				</div>
				<div class="lesson-title-row">
					<input class="lesson-title-input" data-lesson-title value="${ esc( lesson.title ) }" placeholder="Название урока">
					<button type="button" class="lesson-flag ${ lesson.published ? 'published' : '' }" data-toggle-publish>
						${ lesson.published ? 'Опубликован' : 'Черновик' }
					</button>
				</div>
			</div>
			<div class="editor-body" data-step-mount></div>
			<div class="editor-footer">
				<span class="ef-status" data-status><span class="saved-dot"></span> Все изменения сохранены</span>
			</div>`;

		const titleInput = pane.querySelector( '[data-lesson-title]' );
		titleInput.addEventListener( 'input', () => {
			lesson.title = titleInput.value;
			mount.querySelectorAll( '.lesson.active .les-title' ).forEach( ( n ) => { n.textContent = lesson.title; } );
			persist.scheduleLessonMeta( lesson );
		} );
		pane.querySelector( '[data-toggle-publish]' ).addEventListener( 'click', () => persist.togglePublish( lesson ) );

		// Единый редактор шагов (общий модуль). Статус автосейва — в подвал курс-билдера.
		stepEditor = createStepEditor( {
			mount:      pane.querySelector( '[data-step-mount]' ),
			lesson,
			subjectKey: state.course.subject_key,
			setStatus:  persist.setStatus,
		} );
	}

	// Страница модуля (как у урока, но без шагов): имя + описание + удаление.
	function renderModuleEditor( pane, mod ) {
		const mi = state.course.modules.indexOf( mod ) + 1;

		pane.innerHTML = `
			<div class="editor-top">
				<div class="editor-breadcrumb">
					<span>${ esc( state.course.title ) }</span><span>›</span>
					<b>Модуль ${ mi }</b>
				</div>
				<div class="lesson-title-row">
					<input class="lesson-title-input" data-mod-title value="${ esc( mod.title ) }" placeholder="Название модуля">
					<button type="button" class="lesson-flag danger" data-mod-del>Удалить модуль</button>
				</div>
			</div>
			<div class="editor-body">
				<div class="module-page">
					<div class="sb-label">Описание модуля (необязательно)</div>
					<textarea class="field-input module-desc" data-mod-desc rows="5" placeholder="Кратко о модуле…">${ esc( mod.description || '' ) }</textarea>
				</div>
			</div>
			<div class="editor-footer">
				<span class="ef-status" data-status><span class="saved-dot"></span> Все изменения сохранены</span>
			</div>`;

		const titleInput = pane.querySelector( '[data-mod-title]' );
		titleInput.addEventListener( 'input', () => {
			mod.title = titleInput.value;
			mount.querySelectorAll( '.module.active .mod-title' ).forEach( ( n ) => { n.textContent = mod.title; } );
			persist.scheduleStructure();
		} );
		const descInput = pane.querySelector( '[data-mod-desc]' );
		descInput.addEventListener( 'input', () => {
			mod.description = descInput.value;
			persist.scheduleStructure();
		} );
		pane.querySelector( '[data-mod-del]' ).addEventListener( 'click', () => deleteModule( mod ) );
	}

	// ══════════ ADD lesson / module ══════════
	function addLesson() {
		const f = findLesson( state.activeLessonId );
		const mod = f ? f.module : state.course.modules[ 0 ];
		if ( ! mod ) { showToast( 'Сначала добавьте модуль', 'error' ); return; }
		ajax( acts().createLessonInModule, { course_id: courseId, module_id: mod.id, title: 'Новый урок' } )
			.then( ( node ) => {
				mod.lessons.push( node );
				mod.collapsed = false;
				selectLesson( node.id );
				showToast( 'Урок добавлен', 'success' );
			} )
			.catch( ( msg ) => showToast( msg, 'error' ) );
	}
	function addModule() {
		const mod = { id: tmpKey( 'm' ), title: 'Новый модуль', description: '', collapsed: false, lessons: [] };
		state.course.modules.push( mod );
		selectModule( mod.id ); // открыть страницу модуля — задать имя/описание
		persist.saveStructure( 'Модуль добавлен' );
	}

	async function deleteModule( mod ) {
		const note = mod.lessons.length
			? `Удалить модуль «${ mod.title }»? Уроки останутся в библиотеке, но будут убраны из курса.`
			: `Удалить модуль «${ mod.title }»?`;
		try {
			await ConfirmModal.confirm( { title: note, isDanger: true, confirmText: 'Удалить' } );
		} catch {
			return;
		}

		const idx = state.course.modules.findIndex( ( m ) => m.id === mod.id );
		if ( idx < 0 ) { return; }
		const removed = mod.lessons.map( ( l ) => l.id );
		state.course.modules.splice( idx, 1 );
		if ( state.activeModuleId === mod.id ) { state.activeModuleId = null; }
		if ( removed.includes( state.activeLessonId ) ) {
			const fl = firstLesson();
			state.activeLessonId = fl ? fl.id : null;
		}
		renderTree();
		renderEditor();
		persist.saveStructure( 'Модуль удалён' );
	}

	// ── импорт готового урока из библиотеки ──
	function importLessonFlow( e ) {
		const f   = findLesson( state.activeLessonId );
		const mod = f ? f.module : state.course.modules[ 0 ];
		if ( ! mod ) { showToast( 'Сначала добавьте модуль', 'error' ); return; }
		openLessonPicker( e.currentTarget, ( lessonId ) => importLesson( mod, lessonId ) );
	}

	function importLesson( mod, lessonId ) {
		if ( mod.lessons.some( ( l ) => l.id === lessonId ) ) {
			showToast( 'Урок уже в этом модуле', 'info' );
			return;
		}
		mod.lessons.push( { id: lessonId, title: '…', published: false, steps: [] } );
		mod.collapsed = false;
		renderTree();
		ajax( acts().saveCourseStructure, { course_id: courseId, modules: persist.structurePayload() } )
			.then( () => reloadTree( lessonId ) )
			.then( () => showToast( 'Урок добавлен в курс', 'success' ) )
			.catch( ( msg ) => showToast( msg, 'error' ) );
	}

	// Перезагрузка дерева с сервера (после импорта — чтобы подтянуть шаги урока).
	function reloadTree( selectLessonId ) {
		return ajax( acts().getCourseBuilder, { course_id: courseId } ).then( ( tree ) => {
			state.course = tree;
			if ( selectLessonId ) { state.activeLessonId = selectLessonId; }
			renderTree();
			renderEditor();
			const mc = mount.querySelector( '[data-module-count]' );
			const lc = mount.querySelector( '[data-lesson-count]' );
			if ( mc ) { mc.textContent = state.course.modules.length; }
			if ( lc ) { lc.textContent = totalLessons(); }
		} );
	}

	// ── пикер готового урока (reuse GetStepCandidates kind=lesson) ──
	function openLessonPicker( anchor, onPick ) {
		const inCourseIds = () => {
			const s = new Set();
			state.course.modules.forEach( ( m ) => m.lessons.forEach( ( l ) => s.add( l.id ) ) );
			return s;
		};
		openPicker( anchor, {
			placeholder: 'Поиск урока в библиотеке…',
			emptyText:   'Нет доступных уроков',
			fetchFn:     ( search ) => ajax( acts().getStepCandidates, { subject_key: state.course.subject_key, kind: 'lesson', source: 'subject', search } )
				.then( ( items ) => items.filter( ( it ) => ! inCourseIds().has( parseInt( it.id, 10 ) ) ) ),
			onPick:      ( id ) => onPick( id ),
		} );
	}

}
