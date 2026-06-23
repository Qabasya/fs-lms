/**
 * Динамические поля для новых типов заданий:
 * OptionsField, PairsField, OrderItemsField, AudioField.
 */

export const TaskFields = {

	init() {
		if ( ! document.querySelector( '.fs-task-options-field, .fs-task-pairs-field, .fs-task-order-field, .fs-task-audio-field' ) ) {
			return;
		}
		this.bindOptions();
		this.bindPairs();
		this.bindOrder();
		this.bindAudio();
	},

	// ── Варианты ответа ──────────────────────────────────────────────────────

	bindOptions() {
		document.querySelectorAll( '.fs-task-options-field' ).forEach( ( wrap ) => {
			const list = wrap.querySelector( '.fs-task-options__list' );
			const tpl  = wrap.querySelector( '.js-options-tpl' );
			const add  = wrap.querySelector( '.js-options-add' );
			const mode = wrap.querySelector( '.js-options-multiple' );

			if ( ! list || ! tpl || ! add ) return;

			add.addEventListener( 'click', () => {
				const idx  = list.querySelectorAll( '.fs-task-options__item' ).length;
				const li   = document.createElement( 'li' );
				li.className = 'fs-task-options__item';
				li.innerHTML = tpl.innerHTML.replace( /__IDX__/g, idx );
				list.appendChild( li );
			} );

			list.addEventListener( 'click', ( e ) => {
				if ( e.target.closest( '.js-options-remove' ) ) {
					e.target.closest( '.fs-task-options__item' ).remove();
					this.reindexList( list, '.fs-task-options__item', '[name]', 'options' );
				}
			} );

			if ( mode ) {
				mode.addEventListener( 'change', () => {
					const type = mode.checked ? 'checkbox' : 'radio';
					list.querySelectorAll( '.js-option-correct' ).forEach( ( el ) => {
						el.type = type;
					} );
				} );
			}
		} );
	},

	// ── Пары ─────────────────────────────────────────────────────────────────

	bindPairs() {
		document.querySelectorAll( '.fs-task-pairs-field' ).forEach( ( wrap ) => {
			const list = wrap.querySelector( '.fs-task-pairs__list' );
			const tpl  = wrap.querySelector( '.js-pairs-tpl' );
			const add  = wrap.querySelector( '.js-pairs-add' );

			if ( ! list || ! tpl || ! add ) return;

			add.addEventListener( 'click', () => {
				const idx = list.querySelectorAll( '.fs-task-pairs__item' ).length;
				const li  = document.createElement( 'li' );
				li.className = 'fs-task-pairs__item';
				li.innerHTML = tpl.innerHTML.replace( /__IDX__/g, idx );
				list.appendChild( li );
			} );

			list.addEventListener( 'click', ( e ) => {
				if ( e.target.closest( '.js-pairs-remove' ) ) {
					e.target.closest( '.fs-task-pairs__item' ).remove();
					this.reindexList( list, '.fs-task-pairs__item', '[name]', 'pairs' );
				}
			} );
		} );
	},

	// ── Сортировка ───────────────────────────────────────────────────────────

	bindOrder() {
		document.querySelectorAll( '.fs-task-order-field' ).forEach( ( wrap ) => {
			const list = wrap.querySelector( '.fs-task-order__list' );
			const tpl  = wrap.querySelector( '.js-order-tpl' );
			const add  = wrap.querySelector( '.js-order-add' );

			if ( ! list || ! tpl || ! add ) return;

			add.addEventListener( 'click', () => {
				const li = document.createElement( 'li' );
				li.className = 'fs-task-order__item';
				li.innerHTML = tpl.innerHTML;
				list.appendChild( li );
			} );

			list.addEventListener( 'click', ( e ) => {
				if ( e.target.closest( '.js-order-remove' ) ) {
					e.target.closest( '.fs-task-order__item' ).remove();
				}
			} );
		} );
	},

	// ── Аудио (WP media) ─────────────────────────────────────────────────────

	bindAudio() {
		document.querySelectorAll( '.fs-task-audio-field' ).forEach( ( wrap ) => {
			const idInput  = wrap.querySelector( '.js-audio-attachment-id' );
			const preview  = wrap.querySelector( '.fs-task-audio__preview' );
			const player   = wrap.querySelector( '.js-audio-player' );
			const titleEl  = wrap.querySelector( '.js-audio-title' );
			const selectBtn = wrap.querySelector( '.js-audio-select' );
			const removeBtn = wrap.querySelector( '.js-audio-remove' );

			if ( ! idInput || ! selectBtn ) return;

			selectBtn.addEventListener( 'click', () => {
				const frame = wp.media( {
					title:    'Выбрать аудиофайл',
					button:   { text: 'Использовать' },
					library:  { type: 'audio' },
					multiple: false,
				} );

				frame.on( 'select', () => {
					const att = frame.state().get( 'selection' ).first().toJSON();
					idInput.value = att.id;
					if ( player ) { player.src = att.url; }
					if ( titleEl ) { titleEl.textContent = att.title || att.filename || ''; }
					if ( preview ) { preview.style.display = ''; }
					if ( selectBtn ) { selectBtn.textContent = 'Заменить аудио'; }
					if ( removeBtn ) { removeBtn.style.display = ''; }
				} );

				frame.open();
			} );

			if ( removeBtn ) {
				removeBtn.addEventListener( 'click', () => {
					idInput.value = 0;
					if ( player ) { player.src = ''; }
					if ( titleEl ) { titleEl.textContent = ''; }
					if ( preview ) { preview.style.display = 'none'; }
					selectBtn.textContent = 'Выбрать аудио';
					removeBtn.style.display = 'none';
				} );
			}
		} );
	},

	// ── Утилита переиндексации ────────────────────────────────────────────────

	reindexList( list, itemSel, attrSel, segment ) {
		list.querySelectorAll( itemSel ).forEach( ( item, i ) => {
			item.querySelectorAll( attrSel ).forEach( ( el ) => {
				[ 'name', 'id', 'for' ].forEach( ( attr ) => {
					if ( el.hasAttribute( attr ) ) {
						el.setAttribute(
							attr,
							el.getAttribute( attr ).replace(
								new RegExp( `\\[${segment}\\]\\[\\d+\\]`, 'g' ),
								`[${segment}][${i}]`
							)
						);
					}
				} );
			} );
		} );
	},
};
