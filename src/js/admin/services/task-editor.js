/**
 * task-editor.js — Unified inline task editor (Этап 6, Phase F · путь A).
 * jQuery object pattern.
 *
 * Источник истины полей — PHP `Fields/*`. Модалка НЕ строит поля в JS:
 * она запрашивает готовый HTML шаблона (GetTaskEditorForm), навешивает на него
 * общее поведение из {@link TaskFields} и отправляет поля как `fs_lms_meta[...]`
 * — тем же путём, что и нативный метабокс.
 *
 * TaskEditor.init()           — вызвать один раз из admin.js
 * TaskEditor.openModal(opts)  — открыть оверлей-редактор
 *
 * opts: { subjectKey, postId?, templateId?, title?, onSave(id, title) }
 */

import { showToast } from '../modules/toast.js';
import { TaskFields } from './task-fields.js';

/* global jQuery, fs_lms_task_editor_vars */
const $ = jQuery;

export const TaskEditor = {
	_vars: null,
	_$overlay: null,

	init() {
		this._vars = window.fs_lms_task_editor_vars || null;
	},

	openModal( opts ) {
		if ( ! this._vars ) { return; }
		this._close();

		const {
			subjectKey,
			postId     = 0,
			templateId = null,
			title      = '',
			onSave,
		} = opts;

		const schemas = this._vars.schema || {};
		const ids     = Object.keys( schemas );
		if ( ! ids.length ) { return; }

		const activeId = ( templateId && schemas[ templateId ] ) ? templateId : ids[ 0 ];

		const $overlay = $( '<div class="fs-te-overlay">' );
		const $modal   = $( '<div class="fs-te-modal">' );

		// Header
		const $hdr   = $( '<div class="fs-te-header">' );
		const $close = $( '<button type="button" class="fs-te-close" aria-label="Закрыть">&times;</button>' );
		$hdr.append( '<h2 class="fs-te-heading">Редактор задания</h2>', $close );

		// Body
		const $body = $( '<div class="fs-te-body">' );

		// — Название
		const $titleWrap = $( '<div class="fs-te-row">' ).append(
			'<label class="fs-te-label">Название</label>',
			$( '<input type="text" class="fs-te-input" placeholder="Введите название…" autocomplete="off">' ).val( title )
		);
		const $titleInput = $titleWrap.find( 'input' );

		// — Тип задания
		const $typeWrap = $( '<div class="fs-te-row">' ).append( '<label class="fs-te-label">Тип задания</label>' );
		const $select   = $( '<select class="fs-te-select">' );
		ids.forEach( ( id ) => {
			const s   = schemas[ id ];
			const $op = $( '<option>' ).val( id ).text( s.label );
			if ( id === activeId ) { $op.prop( 'selected', true ); }
			$select.append( $op );
		} );
		$typeWrap.append( $select );

		// — Поля (рендерятся PHP, грузятся по AJAX)
		const $fieldsWrap = $( '<div class="fs-te-fields">' );

		$body.append( $titleWrap, $typeWrap, $fieldsWrap );

		// Footer
		const $footer    = $( '<div class="fs-te-footer">' );
		const $saveBtn   = $( '<button type="button" class="button button-primary fs-te-save">Сохранить</button>' );
		const $cancelBtn = $( '<button type="button" class="button fs-te-cancel">Отмена</button>' );
		$footer.append( $saveBtn, $cancelBtn );

		$modal.append( $hdr, $body, $footer );
		$overlay.append( $modal );
		$( 'body' ).append( $overlay );
		this._$overlay = $overlay;

		// Load fields for current template
		const loadFields = () => this._loadFields( $fieldsWrap, $select.val(), subjectKey, postId );
		loadFields();
		$select.on( 'change', loadFields );

		// Close handlers
		const closeHandler = () => this._close();
		$close.on( 'click', closeHandler );
		$cancelBtn.on( 'click', closeHandler );
		$overlay.on( 'click', ( e ) => { if ( e.target === $overlay[ 0 ] ) { closeHandler(); } } );
		$( document ).on( 'keydown.fste', ( e ) => { if ( 'Escape' === e.key ) { closeHandler(); } } );

		// Save
		$saveBtn.on( 'click', () => {
			const t = $titleInput.val().trim();
			if ( ! t ) {
				$titleInput.addClass( 'fs-te-input--error' ).trigger( 'focus' );
				return;
			}
			$titleInput.removeClass( 'fs-te-input--error' );

			// Поля сериализуются как fs_lms_meta[...] — тот же формат, что у метабокса.
			const fieldParams = $fieldsWrap.find( 'input, select, textarea' ).serialize();
			this._save(
				{ subjectKey, postId, template: $select.val(), title: t, fieldParams },
				onSave,
				$saveBtn
			);
		} );

		$titleInput.trigger( 'focus' );
	},

	_close() {
		if ( this._$overlay ) {
			this._$overlay.remove();
			this._$overlay = null;
		}
		$( document ).off( 'keydown.fste' );
	},

	/**
	 * Грузит HTML полей шаблона из PHP и навешивает общее поведение TaskFields.
	 */
	_loadFields( $wrap, templateId, subjectKey, postId ) {
		if ( ! this._vars ) { return; }
		$wrap.html( '<div class="fs-te-loading">Загрузка…</div>' );

		$.post( this._vars.ajax_url, {
			action:      this._vars.actions.getTaskEditorForm,
			security:    this._vars.nonces.taskContent,
			subject_key: subjectKey,
			template:    templateId,
			post_id:     postId || 0,
		} )
			.done( ( res ) => {
				if ( res && res.success ) {
					$wrap.html( res.data.html );
					TaskFields.init( $wrap[ 0 ] );
				} else {
					$wrap.html( '<div class="fs-te-loading">Не удалось загрузить поля.</div>' );
				}
			} )
			.fail( () => {
				$wrap.html( '<div class="fs-te-loading">Ошибка сети.</div>' );
			} );
	},

	_save( { subjectKey, postId, template, title, fieldParams }, onSave, $btn ) {
		if ( ! this._vars ) { return; }
		$btn.prop( 'disabled', true ).text( 'Сохранение…' );

		const scalars = $.param( {
			action:      this._vars.actions.saveTaskContent,
			security:    this._vars.nonces.taskContent,
			subject_key: subjectKey,
			template,
			title,
			post_id:     postId || 0,
		} );
		const body = fieldParams ? `${ scalars }&${ fieldParams }` : scalars;

		$.post( this._vars.ajax_url, body )
			.done( ( res ) => {
				if ( res && res.success ) {
					this._close();
					if ( typeof onSave === 'function' ) {
						onSave( res.data.id, res.data.title );
					}
				} else {
					$btn.prop( 'disabled', false ).text( 'Сохранить' );
					showToast( res?.data?.message || 'Ошибка сохранения', 'error' );
				}
			} )
			.fail( () => {
				$btn.prop( 'disabled', false ).text( 'Сохранить' );
				showToast( 'Ошибка сети. Попробуйте ещё раз.', 'error' );
			} );
	},
};
