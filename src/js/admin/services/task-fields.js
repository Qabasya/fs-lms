/**
 * Динамические поля для новых типов заданий:
 * OptionsField, PairsField, OrderItemsField, AudioField.
 */

import { renderFieldError } from '../../common/validation-manager.js';

export const TaskFields = {

	/**
	 * @param {ParentNode} [root=document] Контейнер для привязки — метабокс (document)
	 *                                     или контейнер модалки-редактора задач.
	 */
	init( root = document ) {
		if ( ! root.querySelector( '.fs-task-options-field, .fs-task-pairs-field, .fs-task-order-field, .fs-task-audio-field, .fs-task-materials-field, .fs-task-criteria-field, .fs-lms-file-group' ) ) {
			return;
		}
		this.bindOptions( root );
		this.bindPairs( root );
		this.bindOrder( root );
		this.bindAudio( root );
		this.bindMaterials( root );
		this.bindCriteria( root );
		this.bindFileLink( root );
	},

	// ── Варианты ответа ──────────────────────────────────────────────────────

	bindOptions( root = document ) {
		root.querySelectorAll( '.fs-task-options-field' ).forEach( ( wrap ) => {
			const list = wrap.querySelector( '.fs-task-options__list' );
			const tpl  = wrap.querySelector( '.js-options-tpl' );
			const add  = wrap.querySelector( '.js-options-add' );
			const mode = wrap.querySelector( '.js-options-multiple' );

			if ( ! list || ! tpl || ! add ) return;

			const checkDup = () => this.checkDuplicates(
				list.querySelectorAll( '.js-option-text' ),
				'Вариант повторяется'
			);

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
					checkDup();
				}
			} );

			list.addEventListener( 'input', ( e ) => {
				if ( e.target.classList.contains( 'js-option-text' ) ) { checkDup(); }
			} );

			if ( mode ) {
				mode.addEventListener( 'change', () => {
					const type = mode.checked ? 'checkbox' : 'radio';
					list.querySelectorAll( '.js-option-correct' ).forEach( ( el ) => {
						el.type = type;
					} );
				} );
			}

			list.addEventListener( 'change', ( e ) => {
				const input = e.target;
				if ( input.classList.contains( 'js-option-correct' ) && input.type === 'radio' ) {
					list.querySelectorAll( '.js-option-correct' ).forEach( ( el ) => {
						if ( el !== input ) { el.checked = false; }
					} );
				}
			} );
		} );
	},

	// ── Пары ─────────────────────────────────────────────────────────────────

	bindPairs( root = document ) {
		root.querySelectorAll( '.fs-task-pairs-field' ).forEach( ( wrap ) => {
			const list = wrap.querySelector( '.fs-task-pairs__list' );
			const tpl  = wrap.querySelector( '.js-pairs-tpl' );
			const add  = wrap.querySelector( '.js-pairs-add' );

			if ( ! list || ! tpl || ! add ) return;

			const checkDup = () => {
				this.checkDuplicates( list.querySelectorAll( '.js-pair-left' ), 'Левая плашка повторяется' );
				this.checkDuplicates( list.querySelectorAll( '.js-pair-right' ), 'Правая плашка повторяется' );
			};

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
					checkDup();
				}
			} );

			list.addEventListener( 'input', ( e ) => {
				if ( e.target.classList.contains( 'js-pair-left' )
					|| e.target.classList.contains( 'js-pair-right' ) ) { checkDup(); }
			} );
		} );
	},

	// ── Сортировка ───────────────────────────────────────────────────────────

	bindOrder( root = document ) {
		root.querySelectorAll( '.fs-task-order-field' ).forEach( ( wrap ) => {
			const list = wrap.querySelector( '.fs-task-order__list' );
			const tpl  = wrap.querySelector( '.js-order-tpl' );
			const add  = wrap.querySelector( '.js-order-add' );

			if ( ! list || ! tpl || ! add ) return;

			const checkDup = () => this.checkDuplicates(
				list.querySelectorAll( '.js-order-text' ),
				'Элемент повторяется'
			);

			let dragging = null;

			const initItem = ( li ) => {
				const handle = li.querySelector( '.fs-task-order__handle' );
				if ( handle ) {
					handle.addEventListener( 'mousedown', () => { li.draggable = true; } );
					handle.addEventListener( 'mouseup',   () => { li.draggable = false; } );
				}
				li.addEventListener( 'dragstart', ( e ) => {
					dragging = li;
					e.dataTransfer.effectAllowed = 'move';
					requestAnimationFrame( () => li.classList.add( 'fs-dragging' ) );
				} );
				li.addEventListener( 'dragend', () => {
					li.draggable = false;
					li.classList.remove( 'fs-dragging' );
					dragging = null;
				} );
			};

			list.addEventListener( 'dragover', ( e ) => {
				e.preventDefault();
				if ( ! dragging ) return;
				const target = e.target.closest( '.fs-task-order__item' );
				if ( ! target || target === dragging ) return;
				const rect = target.getBoundingClientRect();
				if ( e.clientY < rect.top + rect.height / 2 ) {
					list.insertBefore( dragging, target );
				} else {
					list.insertBefore( dragging, target.nextSibling );
				}
			} );

			list.querySelectorAll( '.fs-task-order__item' ).forEach( initItem );

			add.addEventListener( 'click', () => {
				const li = document.createElement( 'li' );
				li.className = 'fs-task-order__item';
				li.innerHTML = tpl.innerHTML;
				list.appendChild( li );
				initItem( li );
			} );

			list.addEventListener( 'click', ( e ) => {
				if ( e.target.closest( '.js-order-remove' ) ) {
					e.target.closest( '.fs-task-order__item' ).remove();
					checkDup();
				}
			} );

			list.addEventListener( 'input', ( e ) => {
				if ( e.target.classList.contains( 'js-order-text' ) ) { checkDup(); }
			} );
		} );
	},

	// ── Аудио (WP media) ─────────────────────────────────────────────────────

	bindAudio( root = document ) {
		root.querySelectorAll( '.fs-task-audio-field' ).forEach( ( wrap ) => {
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

	// ── Предупреждение о повторяющихся значениях ──────────────────────────────

	checkDuplicates( inputs, message ) {
		const seen  = new Map();
		const dupes = new Set();

		inputs.forEach( ( input ) => {
			const value = input.value.trim().toLowerCase();
			if ( ! value ) { return; }
			if ( seen.has( value ) ) {
				dupes.add( input );
				dupes.add( seen.get( value ) );
			} else {
				seen.set( value, input );
			}
		} );

		inputs.forEach( ( input ) => {
			renderFieldError( input, dupes.has( input ) ? message : null );
		} );
	},

	// ── Материалы задания (Эпик 13, D16 → одиночный файл): выбор файла из медиабиблиотеки ──

	bindMaterials( root = document ) {
		root.querySelectorAll( '.fs-task-materials-field' ).forEach( ( wrap ) => {
			const idInput   = wrap.querySelector( '.js-materials-attachment-id' );
			const preview   = wrap.querySelector( '.fs-task-materials__preview' );
			const link      = wrap.querySelector( '.js-materials-link' );
			const selectBtn = wrap.querySelector( '.js-materials-select' );
			const removeBtn = wrap.querySelector( '.js-materials-remove' );

			if ( ! idInput || ! selectBtn ) return;

			selectBtn.addEventListener( 'click', () => {
				const frame = wp.media( {
					title:    'Выбрать файл',
					button:   { text: 'Использовать' },
					multiple: false,
				} );

				frame.on( 'select', () => {
					const att = frame.state().get( 'selection' ).first().toJSON();
					idInput.value = att.id;
					if ( link ) {
						link.href        = att.url;
						link.textContent = att.title || att.filename || `Файл #${ att.id }`;
					}
					if ( preview ) { preview.style.display = ''; }
					selectBtn.textContent = 'Заменить файл';
					if ( removeBtn ) { removeBtn.style.display = ''; }
				} );

				frame.open();
			} );

			if ( removeBtn ) {
				removeBtn.addEventListener( 'click', () => {
					idInput.value = 0;
					if ( link ) { link.href = '#'; link.textContent = ''; }
					if ( preview ) { preview.style.display = 'none'; }
					selectBtn.textContent = 'Выбрать файл';
					removeBtn.style.display = 'none';
				} );
			}
		} );
	},

	// ── Ссылка на файл (LinkField): выбор файла из медиабиблиотеки в URL-инпут ──

	bindFileLink( root = document ) {
		root.querySelectorAll( '.fs-lms-file-group' ).forEach( ( wrap ) => {
			const input     = wrap.querySelector( '.fs-lms-file-input' );
			const selectBtn = wrap.querySelector( '.js-file-link-select' );

			if ( ! input || ! selectBtn ) return;

			selectBtn.addEventListener( 'click', () => {
				const frame = wp.media( {
					title:    'Выбрать файл',
					button:   { text: 'Использовать' },
					multiple: false,
				} );

				frame.on( 'select', () => {
					const att = frame.state().get( 'selection' ).first().toJSON();
					input.value = att.url;
					input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				} );

				frame.open();
			} );
		} );
	},

	// ── Критерии оценивания (Эпик 13, D17): повторяемые строки label+баллы ──

	bindCriteria( root = document ) {
		root.querySelectorAll( '.fs-task-criteria-field' ).forEach( ( wrap ) => {
			const list = wrap.querySelector( '.fs-task-criteria__list' );
			const tpl  = wrap.querySelector( '.js-criteria-tpl' );
			const add  = wrap.querySelector( '.js-criteria-add' );

			if ( ! list || ! tpl || ! add ) return;

			add.addEventListener( 'click', () => {
				const idx = list.querySelectorAll( '.fs-task-criteria__item' ).length;
				const li  = document.createElement( 'li' );
				li.className = 'fs-task-criteria__item';
				li.innerHTML = tpl.innerHTML.replace( /__IDX__/g, idx );
				list.appendChild( li );
			} );

			list.addEventListener( 'click', ( e ) => {
				if ( e.target.closest( '.js-criteria-remove' ) ) {
					e.target.closest( '.fs-task-criteria__item' ).remove();
					this.reindexList( list, '.fs-task-criteria__item', '[name]', 'criteria' );
				}
			} );
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
