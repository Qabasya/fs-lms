import { createStepEditor, ajax, esc } from '../admin/services/step-editor.js';
import { TaskEditor } from '../admin/services/task-editor.js';
import { confirmDialog } from '../common/components/confirm-dialog.js';
import { showToast } from '../common/components/toast.js';

/* global jQuery, fs_lms_teacher_editor_vars */

/**
 * teacher-editor.js — тич-редактор шагов урока в плеере (Этап 4).
 *
 * Отдельный бандл (jQuery, как и общий step-editor.js) от плеера (без jQuery) —
 * грузится ТОЛЬКО преподавателю группы занятия (см. Enqueue::enqueue_teacher_editor_assets()),
 * монтирует общий `createStepEditor` в read-only-bank режиме поверх teacher-эндпоинтов
 * Этапа 3 (COW-форк на первом сейве). Панель — drawer `#fsTeacherEditor` в player.php.
 */
( function ( $ ) {
	$( document ).ready( function () {
		const vars = window.fs_lms_teacher_editor_vars;
		if ( ! vars ) { return; }

		const cfg = { nonce: vars.nonce, ajaxurl: vars.ajaxurl };

		// Инлайновый редактор задач (авторинг банка, Фаза 1) — читает fs_lms_task_editor_vars.
		TaskEditor.init();

		// Панель записи занятия (broadcast-шаг) рендерится сервером для преподавателя и
		// грузит записи на входе в плеер — независимо от drawer тич-редактора (Р0.3).
		// Функция-объявление ниже хойстится; cfg/vars уже определены.
		initBroadcastPanels();

		const btn      = document.getElementById( 'fsTeacherEditBtn' );
		const closeBtn = document.getElementById( 'fsTeacherEditorClose' );
		const resetBtn = document.getElementById( 'fsTeacherEditorReset' );
		const panel    = document.getElementById( 'fsTeacherEditor' );
		const mount    = document.getElementById( 'fsTeacherEditorMount' );
		if ( ! btn || ! panel || ! mount ) { return; }

		let mounted      = false;
		let everForked    = false; // урок уже был форкнут ДО открытия редактора (is_forked при загрузке)
		let didReload     = false; // перезагрузка страницы — не более одного раза за сессию редактора

		function openPanel() {
			panel.hidden = false;
			if ( ! mounted ) { bootstrap(); }
		}
		function closePanel() {
			// Возврат в плеер с перезагрузкой: правки шагов автосохранены, но плеер
			// под панелью отрендерен сервером — перезагрузка сразу показывает изменения.
			window.location.reload();
		}

		btn.addEventListener( 'click', () => { panel.hidden ? openPanel() : closePanel(); } );
		if ( closeBtn ) { closeBtn.addEventListener( 'click', closePanel ); }

		function bootstrap() {
			mounted = true;
			mount.innerHTML = '<div class="tep-loading">Загрузка…</div>';

			ajax( vars.actions.getSteps, { group_lesson_id: vars.groupLessonId }, cfg )
				.then( ( resp ) => {
					everForked = !! resp.is_forked;
					toggleResetBtn( everForked );
					mount.innerHTML = '';

					createStepEditor( {
						mount:              mount,
						lesson:             { id: resp.lesson_id, steps: resp.steps },
						subjectKey:         resp.subject_key,
						readOnlyBank:       true,
						adminBase:          vars.adminBase,
						// Авторинг задач банка инлайновой модалкой (Фаза 1) — если есть AuthorLmsBank.
						canAuthor:          !! ( vars.capabilities && vars.capabilities.authorBank ),
						openTaskEditor:     ( o ) => TaskEditor.openModal( o ),
						confirmFn:          ( c ) => confirmDialog( c.message, c.confirmText || 'Подтвердить', 'Отмена' )
							.then( ( ok ) => { if ( ! ok ) { throw null; } } ),
						// Транспорт AJAX плеера (Р2.5): свои эндпоинты/нонс/ajaxurl + доп.
						// параметр group_lesson_id для запросов + свой сейв (COW-форк).
						transport: {
							actions: {
								getStepCandidates: vars.actions.getStepCandidates,
								getTaskPreview:    vars.actions.getTaskPreview,
								getRefPreview:     vars.actions.getRefPreview,
							},
							nonce:   vars.nonce,
							ajaxurl: vars.ajaxurl,
							params:  { group_lesson_id: vars.groupLessonId },
							persist: persistSteps,
						},
					} );
				} )
				.catch( ( msg ) => {
					mount.innerHTML = '<div class="tep-loading">Не удалось загрузить шаги урока.</div>';
					showToast( msg || 'Ошибка', 'error' );
				} );
		}

		function persistSteps( steps ) {
			return ajax(
				vars.actions.saveSteps,
				{ group_lesson_id: vars.groupLessonId, steps },
				cfg
			).then( ( resp ) => {
				// Тост «урок скопирован» и единственная перезагрузка страницы — только
				// на переходе непофорканный→форкнутый (не на каждый автосейв форкнутого урока).
				if ( resp.forked && ! everForked && ! didReload ) {
					didReload = true;
					everForked = true;
					toggleResetBtn( true );
					showToast( 'Урок скопирован для вашей группы — изменения не затронут курс', 'success' );
					window.setTimeout( () => window.location.reload(), 900 );
				}
			} );
		}

		// ── Сброс форка к версии курса (Этап 5) ─────────────────────────────
		function toggleResetBtn( show ) {
			if ( resetBtn ) { resetBtn.hidden = ! show; }
		}

		if ( resetBtn ) {
			resetBtn.addEventListener( 'click', () => {
				confirmDialog(
					'Правки группы будут удалены, урок вернётся к версии курса. Прогресс учеников по шагам, добавленным для группы, будет потерян.',
					'Сбросить',
					'Отмена'
				).then( ( ok ) => {
					if ( ! ok ) { return; }
					resetBtn.disabled = true;
					ajax( vars.actions.resetFork, { group_lesson_id: vars.groupLessonId }, cfg )
						.then( () => window.location.reload() )
						.catch( ( msg ) => {
							resetBtn.disabled = false;
							showToast( msg || 'Ошибка', 'error' );
						} );
				} );
			} );
		}

		// ── Панель записи занятия (broadcast-шаг) ────────────────────────────
		function initBroadcastPanels() {
			document.querySelectorAll( '.broadcast-teacher-panel' ).forEach( loadRecordingsPanel );
		}

		function loadRecordingsPanel( panelEl ) {
			const glid = panelEl.dataset.glid;
			panelEl.innerHTML = '<div class="btp-loading">Загрузка записей…</div>';

			ajax( vars.actions.listRecordings, { group_lesson_id: glid }, cfg )
				.then( ( resp ) => renderRecordingsPanel( panelEl, glid, resp ) )
				.catch( () => { panelEl.innerHTML = '<div class="btp-loading">Не удалось загрузить записи.</div>'; } );
		}

		function renderRecordingsPanel( panelEl, glid, data ) {
			const current    = data.current || [];
			const candidates = data.candidates || [];

			let html = '<div class="btp-title">Запись занятия</div>';

			if ( current.length ) {
				current.forEach( ( r ) => {
					html += `<div class="btp-row">
						<span class="btp-rec-meta">${ esc( r.s3_key ) } · ${ esc( r.recorded_at ) }</span>
						<button type="button" class="button fs-sb-btn-danger" data-detach="${ parseInt( r.id, 10 ) || 0 }">Отвязать</button>
					</div>`;
				} );
			} else {
				html += '<div class="btp-row"><span class="btp-rec-empty">Запись ещё не привязана.</span></div>';
			}

			if ( candidates.length ) {
				html += '<div class="btp-title">Доступные записи</div>';
				candidates.forEach( ( r ) => {
					html += `<div class="btp-row">
						<span class="btp-rec-meta">${ esc( r.s3_key ) } · ${ esc( r.recorded_at ) }</span>
						<button type="button" class="button button-primary" data-attach="${ parseInt( r.id, 10 ) || 0 }">Привязать</button>
					</div>`;
				} );
			}

			panelEl.innerHTML = html;

			panelEl.querySelectorAll( '[data-attach]' ).forEach( ( b ) => b.addEventListener( 'click', () => {
				ajax( vars.actions.attachRecording, { group_lesson_id: glid, recording_id: b.dataset.attach }, cfg )
					.then( () => loadRecordingsPanel( panelEl ) )
					.catch( ( msg ) => showToast( msg || 'Ошибка', 'error' ) );
			} ) );
			panelEl.querySelectorAll( '[data-detach]' ).forEach( ( b ) => b.addEventListener( 'click', () => {
				ajax( vars.actions.detachRecording, { group_lesson_id: glid, recording_id: b.dataset.detach }, cfg )
					.then( () => loadRecordingsPanel( panelEl ) )
					.catch( ( msg ) => showToast( msg || 'Ошибка', 'error' ) );
			} ) );
		}
	} );
}( jQuery ) );
