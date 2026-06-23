/**
 * task-editor.js — Unified inline task editor (Этап 6, Phase F).
 * jQuery object pattern. Schema from window.fs_lms_task_editor_vars.
 *
 * TaskEditor.init()        — вызвать один раз из admin.js
 * TaskEditor.openModal(opts) — открыть оверлей-редактор
 *
 * opts: { subjectKey, postId?, templateId?, data?, title?, onSave(id, title) }
 */

/* global jQuery, wp, fs_lms_task_editor_vars */
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
			data       = {},
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

		// — Поля
		const $fieldsWrap = $( '<div class="fs-te-fields">' );

		$body.append( $titleWrap, $typeWrap, $fieldsWrap );

		// Footer
		const $footer  = $( '<div class="fs-te-footer">' );
		const $saveBtn = $( '<button type="button" class="button button-primary fs-te-save">Сохранить</button>' );
		const $cancelBtn = $( '<button type="button" class="button fs-te-cancel">Отмена</button>' );
		$footer.append( $saveBtn, $cancelBtn );

		$modal.append( $hdr, $body, $footer );
		$overlay.append( $modal );
		$( 'body' ).append( $overlay );
		this._$overlay = $overlay;

		// Render fields
		const renderFields = () => {
			const id     = $select.val();
			const schema = schemas[ id ] || null;
			this._renderFields( $fieldsWrap, schema, data );
		};
		renderFields();
		$select.on( 'change', renderFields );

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
			const tId   = $select.val();
			const fData = this._collectFields( $fieldsWrap, schemas[ tId ] );
			this._save(
				{ subjectKey, postId, template: tId, title: t, data: fData },
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

	_renderFields( $wrap, schema, existingData ) {
		$wrap.empty();
		if ( ! schema ) { return; }
		schema.fields.forEach( ( field ) => {
			const $row = $( '<div class="fs-te-row">' ).append(
				$( '<label class="fs-te-label">' ).text( field.label )
			);
			const val = existingData[ field.key ] ?? null;

			switch ( field.type ) {
				case 'rich_text':
				case 'text':
					$row.append( this._fieldText( field, val ) );
					break;
				case 'options':
					$row.append( this._fieldOptions( field, val ) );
					break;
				case 'pairs':
					$row.append( this._fieldPairs( field, val ) );
					break;
				case 'order_items':
					$row.append( this._fieldOrderItems( field, val ) );
					break;
				case 'gap_text':
					$row.append( this._fieldGapText( field, val ) );
					break;
				case 'audio':
					$row.append( this._fieldAudio( field, val ) );
					break;
				case 'hint':
					$row.append( this._fieldHint( field, val ) );
					break;
				default:
					$row.append( this._fieldText( field, val ) );
			}
			$wrap.append( $row );
		} );
	},

	_fieldText( field, val ) {
		const text = typeof val === 'string' ? val : '';
		if ( 'rich_text' === field.type ) {
			return $( '<textarea class="fs-te-textarea" rows="6">' )
				.attr( 'data-field', field.key )
				.val( text );
		}
		return $( '<input type="text" class="fs-te-input">' )
			.attr( 'data-field', field.key )
			.val( text );
	},

	_fieldOptions( field, val ) {
		const parsed  = ( val && typeof val === 'object' ) ? val : {};
		const multi   = !! parsed.multiple;
		const options = Array.isArray( parsed.options ) ? parsed.options : [];

		const uid   = `fste-${ field.key }-${ Date.now() }`;
		const $wrap = $( '<div class="fs-te-options">' ).attr( 'data-field', field.key );
		const $mode = $( '<div class="fs-te-options__mode">' );
		const $chk  = $( '<input type="checkbox">' ).prop( 'id', uid ).prop( 'checked', multi );
		$mode.append( $chk, $( '<label>' ).prop( 'for', uid ).text( 'Множественный выбор' ) );
		$wrap.append( $mode );

		const $list = $( '<ul class="fs-te-options__list">' );
		options.forEach( ( opt, i ) => this._appendOptionRow( $list, field.key, opt, i, multi ) );
		$wrap.append( $list );

		const $add = $( '<button type="button" class="button fs-te-add-btn">+ Вариант</button>' );
		$add.on( 'click', () => {
			const idx = $list.children().length;
			this._appendOptionRow( $list, field.key, {}, idx, $chk.prop( 'checked' ) );
		} );
		$wrap.append( $add );

		$chk.on( 'change', () => {
			const isMulti = $chk.prop( 'checked' );
			$list.find( '.js-opt-correct' ).each( ( _, el ) => { el.type = isMulti ? 'checkbox' : 'radio'; } );
		} );

		return $wrap;
	},

	_appendOptionRow( $list, fieldKey, opt, idx, multi ) {
		const $li  = $( '<li class="fs-te-options__item">' );
		const $inp = $( `<input type="${ multi ? 'checkbox' : 'radio' }" class="js-opt-correct">` )
			.attr( 'name', `fste-correct-${ fieldKey }` )
			.prop( 'checked', !! opt.correct );
		const $txt = $( '<input type="text" class="fs-te-input js-opt-text">' )
			.val( opt.text || '' )
			.attr( 'placeholder', 'Вариант ответа…' );
		const $del = $( '<button type="button" class="fs-te-del-btn" aria-label="Удалить">&times;</button>' );
		$del.on( 'click', () => $li.remove() );
		$li.append( $inp, $txt, $del );
		$list.append( $li );
	},

	_fieldPairs( field, val ) {
		const parsed = ( val && typeof val === 'object' ) ? val : {};
		const pairs  = Array.isArray( parsed.pairs ) ? parsed.pairs : [];

		const $wrap = $( '<div class="fs-te-pairs">' ).attr( 'data-field', field.key );
		const $list = $( '<ul class="fs-te-pairs__list">' );
		pairs.forEach( ( p ) => this._appendPairRow( $list, p ) );
		$wrap.append( $list );

		const $add = $( '<button type="button" class="button fs-te-add-btn">+ Пара</button>' );
		$add.on( 'click', () => this._appendPairRow( $list, {} ) );
		$wrap.append( $add );
		return $wrap;
	},

	_appendPairRow( $list, pair ) {
		const $li = $( '<li class="fs-te-pairs__item">' );
		$li.append(
			$( '<input type="text" class="fs-te-input js-pair-left">' ).val( pair.left || '' ).attr( 'placeholder', 'Левая часть…' ),
			$( '<span class="fs-te-arrow">→</span>' ),
			$( '<input type="text" class="fs-te-input js-pair-right">' ).val( pair.right || '' ).attr( 'placeholder', 'Правая часть…' )
		);
		const $del = $( '<button type="button" class="fs-te-del-btn" aria-label="Удалить">&times;</button>' );
		$del.on( 'click', () => $li.remove() );
		$li.append( $del );
		$list.append( $li );
	},

	_fieldOrderItems( field, val ) {
		const parsed = ( val && typeof val === 'object' ) ? val : {};
		const items  = Array.isArray( parsed.items ) ? parsed.items : [];

		const $wrap = $( '<div class="fs-te-order">' ).attr( 'data-field', field.key );
		const $list = $( '<ul class="fs-te-order__list">' );
		items.forEach( ( text ) => this._appendOrderRow( $list, text ) );
		$wrap.append( $list );

		const $add = $( '<button type="button" class="button fs-te-add-btn">+ Элемент</button>' );
		$add.on( 'click', () => this._appendOrderRow( $list, '' ) );
		$wrap.append( $add );
		return $wrap;
	},

	_appendOrderRow( $list, text ) {
		const $li = $( '<li class="fs-te-order__item">' );
		$li.append(
			$( '<span class="fs-te-grip" aria-hidden="true">⠿</span>' ),
			$( '<input type="text" class="fs-te-input js-order-text">' ).val( text ).attr( 'placeholder', 'Элемент…' )
		);
		const $del = $( '<button type="button" class="fs-te-del-btn" aria-label="Удалить">&times;</button>' );
		$del.on( 'click', () => $li.remove() );
		$li.append( $del );
		$list.append( $li );
	},

	_fieldGapText( field, val ) {
		const parsed = ( val && typeof val === 'object' ) ? val : {};
		const text   = parsed.text || '';
		const $wrap  = $( '<div>' ).attr( 'data-field', field.key );
		$wrap.append(
			$( '<textarea class="fs-te-textarea" rows="4">' )
				.attr( 'placeholder', 'Текст с [[правильный ответ]] или [[a|b]] для пропусков…' )
				.val( text ),
			$( '<p class="description">' ).text( 'Пропуски: [[ответ]] или [[a|b]] для нескольких вариантов.' )
		);
		return $wrap;
	},

	_fieldAudio( field, val ) {
		const parsed = ( val && typeof val === 'object' ) ? val : {};
		const attId  = parseInt( parsed.attachment_id, 10 ) || 0;
		const $wrap  = $( '<div class="fs-te-audio">' ).attr( 'data-field', field.key );
		const $id    = $( '<input type="hidden" class="js-audio-id">' ).val( attId );
		const $name  = $( '<span class="fs-te-audio__name">' ).text( attId ? `Файл ID: ${ attId }` : 'Не выбран' );
		const $pick  = $( '<button type="button" class="button">Выбрать аудио</button>' );
		$pick.on( 'click', () => {
			if ( ! wp || ! wp.media ) { return; }
			const frame = wp.media( {
				title:   'Выбрать аудио',
				button:  { text: 'Выбрать' },
				library: { type: 'audio' },
				multiple: false,
			} );
			frame.on( 'select', () => {
				const att = frame.state().get( 'selection' ).first().toJSON();
				$id.val( att.id );
				$name.text( att.filename || att.title || `Файл ID: ${ att.id }` );
			} );
			frame.open();
		} );
		$wrap.append( $id, $name, $pick );
		return $wrap;
	},

	_fieldHint( field, val ) {
		const text = typeof val === 'string' ? val : '';
		return $( '<textarea class="fs-te-textarea" rows="3">' )
			.attr( 'data-field', field.key )
			.attr( 'placeholder', 'Подсказка для ученика (необязательно)…' )
			.val( text );
	},

	_collectFields( $wrap, schema ) {
		const result = {};
		if ( ! schema ) { return result; }

		schema.fields.forEach( ( field ) => {
			switch ( field.type ) {
				case 'options': {
					const $f      = $wrap.find( `[data-field="${ field.key }"]` );
					const multi   = $f.find( 'input[type="checkbox"][id^="fste-"]' ).prop( 'checked' );
					const options = [];
					$f.find( '.fs-te-options__item' ).each( ( i, li ) => {
						const $li = $( li );
						options.push( {
							id:      i,
							text:    $li.find( '.js-opt-text' ).val().trim(),
							correct: $li.find( '.js-opt-correct' ).prop( 'checked' ),
						} );
					} );
					result[ field.key ] = { multiple: !! multi, options };
					break;
				}
				case 'pairs': {
					const pairs = [];
					$wrap.find( `[data-field="${ field.key }"] .fs-te-pairs__item` ).each( ( _, li ) => {
						const $li = $( li );
						pairs.push( { left: $li.find( '.js-pair-left' ).val().trim(), right: $li.find( '.js-pair-right' ).val().trim() } );
					} );
					result[ field.key ] = { pairs };
					break;
				}
				case 'order_items': {
					const items = [];
					$wrap.find( `[data-field="${ field.key }"] .js-order-text` ).each( ( _, el ) => {
						const v = $( el ).val().trim();
						if ( v ) { items.push( v ); }
					} );
					result[ field.key ] = { items };
					break;
				}
				case 'gap_text': {
					result[ field.key ] = { text: $wrap.find( `[data-field="${ field.key }"] textarea` ).val() };
					break;
				}
				case 'audio': {
					result[ field.key ] = { attachment_id: parseInt( $wrap.find( `[data-field="${ field.key }"] .js-audio-id` ).val(), 10 ) || 0 };
					break;
				}
				default: {
					result[ field.key ] = $wrap.find( `[data-field="${ field.key }"]` ).val() || '';
					break;
				}
			}
		} );
		return result;
	},

	_save( { subjectKey, postId, template, title, data }, onSave, $btn ) {
		if ( ! this._vars ) { return; }
		$btn.prop( 'disabled', true ).text( 'Сохранение…' );

		$.post( this._vars.ajax_url, {
			action:      this._vars.actions.saveTaskContent,
			security:    this._vars.nonces.taskContent,
			subject_key: subjectKey,
			template,
			title,
			post_id:     postId || 0,
			data:        JSON.stringify( data ),
		} )
			.done( ( res ) => {
				if ( res && res.success ) {
					this._close();
					if ( typeof onSave === 'function' ) {
						onSave( res.data.id, res.data.title );
					}
				} else {
					$btn.prop( 'disabled', false ).text( 'Сохранить' );
					// eslint-disable-next-line no-alert
					alert( res?.data?.message || 'Ошибка сохранения' );
				}
			} )
			.fail( () => {
				$btn.prop( 'disabled', false ).text( 'Сохранить' );
				// eslint-disable-next-line no-alert
				alert( 'Ошибка сети. Попробуйте ещё раз.' );
			} );
	},
};
