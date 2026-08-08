import { icoReplace } from '../../../common/icons.js';
import { escapeHtml as esc } from '../../../common/utils.js';
import { showToast } from '../../modules/toast.js';
import { ajax, acts } from '../step-ajax.js';
import { loadRefPreview, loadTaskPreview } from '../step-preview.js';

/**
 * ref-editor.js — тело ссылочного шага (task / work / assessment): выбор из
 * библиотеки, создание в новой вкладке с поллингом, превью, настройки попыток.
 * Вынесено из step-editor.js без изменения поведения: связки с инстансом
 * редактора (stepMeta, adminBase, subjectKey, openLibraryPicker,
 * renderStepsRow, renderStepBody, saveSteps, scheduleSave) передаются через `ctx`.
 */

export function refEditor( ed, step, ctx ) {
	const { stepMeta, adminBase, subjectKey, openLibraryPicker, renderStepsRow, renderStepBody, saveSteps, scheduleSave } = ctx;
	const meta     = stepMeta( step );
	const candKind = meta.candKind; // task | work | assessment
	const refId    = parseInt( step.payload.ref || 0, 10 );
	const isTask   = 'task' === candKind;
	const isWork   = 'work' === candKind;

	// ── Задача: отдельный UI ──────────────────────────────────────
	if ( isTask ) {
		if ( ! refId ) {
			ed.innerHTML =
				'<div class="fs-cb-task-pick">' +
				'<button type="button" class="button" data-pick>Выбрать существующую</button>' +
				'<button type="button" class="button button-primary" data-create>Добавить новую</button>' +
				'</div>';
		} else {
			const attVal  = parseInt( ( step.payload.settings || {} ).max_attempts ?? 0, 10 );
			const hintVal = parseInt( ( step.payload.settings || {} ).hint_after_errors ?? 0, 10 );
			ed.innerHTML =
				'<div class="fs-cb-ref">' +
				'<span class="fs-cb-ref-title">' + esc( step._title || step.title ) + '</span>' +
				'<a class="button" href="' + adminBase + 'post.php?post=' + refId + '&action=edit" target="_blank" rel="noopener">Редактировать ↗</a>' +
				'<button type="button" class="button fs-sb-btn-danger" data-pick>' + icoReplace( 13 ) + ' Заменить</button>' +
				'</div>' +
				'<div class="fs-cb-task-preview" data-task-preview></div>' +
				'<div class="fs-cb-step-attempts">' +
				'<div class="fs-cb-ss-row">' +
				'<label class="fs-cb-ss-label">Попыток (0 = ∞)' +
				'<input type="number" min="0" class="fs-cb-ss-num" data-attempts value="' + attVal + '">' +
				'</label>' +
				'<label class="fs-cb-ss-label">Отображать подсказку после ошибок (0 = сразу)' +
				'<input type="number" min="0" class="fs-cb-ss-num" data-hint value="' + hintVal + '">' +
				'</label>' +
				'</div>' +
				'</div>';

			const attInput  = ed.querySelector( '[data-attempts]' );
			const hintInput = ed.querySelector( '[data-hint]' );
			// Число ошибок для показа подсказки всегда меньше числа попыток
			// (max_attempts = 0 = ∞ — ограничения нет).
			const clampHint = () => {
				const mx = parseInt( attInput.value, 10 ) || 0;
				let h    = parseInt( hintInput.value, 10 ) || 0;
				if ( h < 0 ) { h = 0; }
				if ( mx > 0 && h >= mx ) { h = mx - 1; }
				hintInput.value = h;
				return h;
			};
			attInput.addEventListener( 'change', () => {
				step.payload.settings                   = step.payload.settings || {};
				step.payload.settings.max_attempts      = parseInt( attInput.value, 10 ) || 0;
				step.payload.settings.hint_after_errors = clampHint();
				scheduleSave();
			} );
			hintInput.addEventListener( 'change', () => {
				step.payload.settings                   = step.payload.settings || {};
				step.payload.settings.hint_after_errors = clampHint();
				scheduleSave();
			} );

			loadTaskPreview( ed, refId );
		}

		const pickBtn = ed.querySelector( '[data-pick]' );
		if ( pickBtn ) {
			pickBtn.addEventListener( 'click', ( e ) => openLibraryPicker( e, candKind, ( id, title, source ) => {
				step.payload.ref    = id;
				step._title         = title;
				step.title          = title;
				step.payload.source = 'bank' === source ? 'bank' : 'subject';
				delete step.payload.needs_review;
				renderStepsRow(); renderStepBody(); saveSteps();
			} ) );
		}

		const createBtn = ed.querySelector( '[data-create]' );
		if ( createBtn ) {
			createBtn.addEventListener( 'click', () => {
				const newWin = window.open( adminBase + 'post-new.php?post_type=fs_lms_problems', '_blank' );
				let lastHref    = '';
				const poll = setInterval( () => {
					if ( newWin && ! newWin.closed ) {
						try { lastHref = newWin.location.href; } catch ( e ) { /* навигация — ждём */ }
					}
					const urlSearch = lastHref.includes( '?' ) ? lastHref.split( '?' )[ 1 ] : '';
					const params    = new URLSearchParams( urlSearch );
					const postId    = params.get( 'post' );
					if ( postId && params.get( 'action' ) === 'edit' ) {
						clearInterval( poll );
						ajax( acts().getTaskPreview, { task_id: postId } )
							.then( ( data ) => {
								step.payload.ref    = parseInt( postId, 10 );
								step.payload.source = 'bank';
								step._title         = data.title || ( 'Задача #' + postId );
								step.title          = data.title || ( 'Задача #' + postId );
								renderStepsRow(); renderStepBody(); saveSteps();
								showToast( 'Задача добавлена в шаг', 'success' );
							} )
							.catch( () => {
								step.payload.ref    = parseInt( postId, 10 );
								step.payload.source = 'bank';
								step._title         = 'Задача #' + postId;
								step.title          = 'Задача #' + postId;
								renderStepsRow(); renderStepBody(); saveSteps();
								showToast( 'Задача добавлена в шаг', 'success' );
							} );
						return;
					}
					if ( newWin && newWin.closed ) { clearInterval( poll ); }
				}, 800 );
			} );
		}
		return;
	}

	// ── Работа / Контрольная: 2-кнопочный UI (аналог Задачи) ────
	if ( ! refId ) {
		ed.innerHTML =
			'<div class="fs-cb-task-pick">' +
			'<button type="button" class="button" data-pick>Выбрать существующую</button>' +
			'<button type="button" class="button button-primary" data-create>Добавить новую</button>' +
			'</div>';
	} else {
		ed.innerHTML =
			'<div class="fs-cb-ref">' +
			'<span class="fs-cb-ref-title">' + esc( step._title || step.title ) + '</span>' +
			'<a class="button" href="' + adminBase + 'post.php?post=' + refId + '&action=edit" target="_blank" rel="noopener">Редактировать ↗</a>' +
			'<button type="button" class="button fs-sb-btn-danger" data-pick>' + icoReplace( 13 ) + ' Заменить</button>' +
			'</div>' +
			'<div class="fs-cb-ref-tasks"></div>';
		loadRefPreview( ed.querySelector( '.fs-cb-ref-tasks' ), refId, isWork ? 'work' : 'assessment' );
	}

	const pickBtn = ed.querySelector( '[data-pick]' );
	if ( pickBtn ) {
		pickBtn.addEventListener( 'click', ( e ) => openLibraryPicker( e, candKind, ( id, title ) => {
			step.payload.ref = id; step._title = title; step.title = title; delete step.payload.needs_review;
			renderStepsRow(); renderStepBody(); saveSteps();
		} ) );
	}

	const createBtn = ed.querySelector( '[data-create]' );
	if ( createBtn ) {
		createBtn.addEventListener( 'click', () => {
			const postType  = isWork
				? subjectKey + '_works'
				: subjectKey + '_assessments';
			const newWin = window.open( adminBase + 'post-new.php?post_type=' + encodeURIComponent( postType ), '_blank' );
			let lastHref = '';
			const poll = setInterval( () => {
				if ( newWin && ! newWin.closed ) {
					try { lastHref = newWin.location.href; } catch ( e ) { /* навигация — ждём */ }
				}
				const urlSearch = lastHref.includes( '?' ) ? lastHref.split( '?' )[ 1 ] : '';
				const params    = new URLSearchParams( urlSearch );
				const postId    = params.get( 'post' );
				if ( postId && params.get( 'action' ) === 'edit' ) {
					clearInterval( poll );
					step.payload.ref = parseInt( postId, 10 );
					step._title      = meta.name + ' #' + postId;
					step.title       = step._title;
					renderStepsRow(); renderStepBody(); saveSteps();
					showToast( meta.name + ' добавлена в шаг', 'success' );
					return;
				}
				if ( newWin && newWin.closed ) { clearInterval( poll ); }
			}, 800 );
		} );
	}
}
