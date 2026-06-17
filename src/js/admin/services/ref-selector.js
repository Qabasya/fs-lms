import '../_types.js';
import { TaskModalManager } from '../managers/task-modal-manager.js';
import { DraftCreatorModal } from '../modals/draft-creator-modal.js';

/* global jQuery, fs_lms_vars */
const $ = jQuery;

/**
 * Сопоставление типа ссылки с AJAX-экшеном и nonce.
 * item   — элементы работы (задания {key}_tasks + задачи fs_lms_problems)
 * work   — работы внутри урока
 * lesson — уроки внутри курса
 */
const REF_MAP = {
	item:   { action: 'getWorkItemCandidates',     nonceKey: 'authorWork' },
	work:   { action: 'getLessonWorkCandidates',   nonceKey: 'authorLesson' },
	lesson: { action: 'getCourseLessonCandidates', nonceKey: 'authorCourse' },
};

/**
 * RefSelector — единый конструктор-селектор ссылок для банков.
 * T1.24: для item-полей загружает фильтр коллекций.
 * T1.23: кнопка «Создать задание» → task-modal; «Создать задачу» → draft-creator-modal.
 * T1.31: unified item selector — поиск и по заданиям, и по задачам.
 */
export const RefSelector = {

	_searchTimer: null,

	init() {
		if ( ! $( '.fs-lms-ref-field' ).length ) {
			return;
		}

		this._bindRemove();
		this._bindSearch();
		this._bindDropdownSelect();
		this._bindReorder();
		this._bindCreate();
		this._loadCollections();
	},

	_bindRemove() {
		$( document ).on( 'click', '.fs-lms-ref-remove', ( e ) => {
			$( e.currentTarget ).closest( '.fs-lms-ref-chip' ).remove();
		} );
	},

	_bindSearch() {
		$( document ).on( 'input focus', '.fs-lms-ref-search', ( e ) => {
			const $input = $( e.currentTarget );
			const $field = $input.closest( '.fs-lms-ref-field' );

			clearTimeout( this._searchTimer );
			this._searchTimer = setTimeout( () => {
				this._fetchCandidates( String( $input.val() ).trim(), $field );
			}, 300 );
		} );

		$( document ).on( 'click', ( e ) => {
			if ( ! $( e.target ).closest( '.fs-lms-ref-search-wrap' ).length ) {
				$( '.fs-lms-ref-dropdown' ).attr( 'hidden', true ).empty();
			}
		} );
	},

	_bindDropdownSelect() {
		$( document ).on( 'click', '.fs-lms-ref-option', ( e ) => {
			const $opt   = $( e.currentTarget );
			const $field = $opt.closest( '.fs-lms-ref-field' );

			this._addChip(
				$field,
				parseInt( $opt.data( 'ref-id' ), 10 ),
				String( $opt.data( 'ref-title' ) ),
				String( $opt.data( 'item-type' ) || '' ),
			);

			$field.find( '.fs-lms-ref-dropdown' ).attr( 'hidden', true ).empty();
			$field.find( '.fs-lms-ref-search' ).val( '' );
		} );
	},

	_bindReorder() {
		$( document ).on( 'dragstart', '.fs-lms-ref-chip', ( e ) => {
			e.originalEvent.dataTransfer.effectAllowed = 'move';
			$( e.currentTarget ).addClass( 'is-dragging' );
		} );

		$( document ).on( 'dragend', '.fs-lms-ref-chip', ( e ) => {
			$( e.currentTarget ).removeClass( 'is-dragging' );
		} );

		$( document ).on( 'dragover', '.fs-lms-ref-chips', ( e ) => {
			e.preventDefault();
			const $container = $( e.currentTarget );
			const $dragging  = $container.find( '.is-dragging' );
			if ( ! $dragging.length ) {
				return;
			}

			const after = this._chipAfter( $container[ 0 ], e.originalEvent.clientY );
			if ( after === null ) {
				$container.append( $dragging );
			} else {
				$dragging.insertBefore( after );
			}
		} );
	},

	// T1.23 / T1.31 — кнопки «Создать задание» и «Создать задачу»
	_bindCreate() {
		$( document ).on( 'click', '.fs-lms-ref-create', ( e ) => {
			const $field  = $( e.currentTarget ).closest( '.fs-lms-ref-field' );
			const refType = String( $field.data( 'ref-type' ) );

			if ( refType === 'item' ) {
				TaskModalManager.openForChip( ( id, title ) => {
					this._addChip( $field, id, title, 'task' );
				} );
			} else {
				DraftCreatorModal.open( {
					refType,
					$field,
					onCreated: ( id, title ) => this._addChip( $field, id, title ),
				} );
			}
		} );

		$( document ).on( 'click', '.fs-lms-ref-create-problem', ( e ) => {
			const $field = $( e.currentTarget ).closest( '.fs-lms-ref-field' );
			DraftCreatorModal.open( {
				refType: 'problem',
				$field,
				onCreated: ( id, title ) => this._addChip( $field, id, title, 'problem' ),
			} );
		} );
	},

	// T1.24 — загрузка коллекций для item ref-полей
	_loadCollections() {
		$( '.fs-lms-ref-field[data-ref-type="item"]' ).each( ( _, el ) => {
			const $field  = $( el );
			const subject = String( $field.data( 'subject' ) );

			if ( ! subject || ! fs_lms_vars.ajax_actions.getWorkCollections ) {
				return;
			}

			$.post( fs_lms_vars.ajaxurl, {
				action:      fs_lms_vars.ajax_actions.getWorkCollections,
				security:    fs_lms_vars.nonces.authorWork,
				subject_key: subject,
			} ).done( ( resp ) => {
				if ( ! resp || ! resp.success || ! resp.data.length ) {
					return;
				}

				let html = '<option value="0">Все коллекции</option>';
				resp.data.forEach( ( c ) => {
					html += `<option value="${ parseInt( c.id, 10 ) }">${ $( '<div>' ).text( c.name ).html() }</option>`;
				} );

				const $select = $( '<select/>', { class: 'fs-lms-ref-collection postform' } ).html( html );
				$field.find( '.fs-lms-ref-search-wrap' ).before( $select );

				$select.on( 'change', () => {
					const $input = $field.find( '.fs-lms-ref-search' );
					this._fetchCandidates( String( $input.val() ).trim(), $field );
				} );
			} );
		} );
	},

	_chipAfter( container, y ) {
		const chips = [ ...container.querySelectorAll( '.fs-lms-ref-chip:not(.is-dragging)' ) ];
		return chips.reduce( ( closest, child ) => {
			const box    = child.getBoundingClientRect();
			const offset = y - box.top - box.height / 2;
			if ( offset < 0 && offset > closest.offset ) {
				return { offset, element: child };
			}
			return closest;
		}, { offset: Number.NEGATIVE_INFINITY, element: null } ).element;
	},

	_fetchCandidates( search, $field ) {
		const refType = String( $field.data( 'ref-type' ) );
		const subject = String( $field.data( 'subject' ) );
		const map     = REF_MAP[ refType ];

		if ( ! map || ! subject ) {
			return;
		}

		const data = {
			action:      fs_lms_vars.ajax_actions[ map.action ],
			security:    fs_lms_vars.nonces[ map.nonceKey ],
			subject_key: subject,
			scope:       'mine',
			search,
		};

		// T1.24: коллекция-фильтр для item ref
		if ( refType === 'item' ) {
			const $col = $field.find( '.fs-lms-ref-collection' );
			if ( $col.length ) {
				data.collection = parseInt( $col.val(), 10 ) || 0;
			}
		}

		$.post( fs_lms_vars.ajaxurl, data ).done( ( resp ) => {
			if ( ! resp || ! resp.success ) {
				return;
			}
			this._renderDropdown( $field, resp.data || [] );
		} );
	},

	_renderDropdown( $field, items ) {
		const $dropdown = $field.find( '.fs-lms-ref-dropdown' );
		const selected  = this._selectedIds( $field );
		$dropdown.empty();

		const available = items.filter( ( item ) => ! selected.includes( parseInt( item.id, 10 ) ) );

		if ( ! available.length ) {
			$dropdown.append( $( '<div/>', { class: 'fs-lms-ref-empty', text: 'Ничего не найдено' } ) );
		} else {
			available.forEach( ( item ) => {
				const label = item.type === 'problem'
					? `[Задача] ${ item.title }`
					: item.title;

				$( '<div/>', { class: 'fs-lms-ref-option', text: label } )
					.attr( 'data-ref-id', item.id )
					.attr( 'data-ref-title', item.title )
					.attr( 'data-item-type', item.type || '' )
					.appendTo( $dropdown );
			} );
		}

		$dropdown.removeAttr( 'hidden' );
	},

	_selectedIds( $field ) {
		return $field.find( '.fs-lms-ref-chip' ).map( ( _, el ) => parseInt( $( el ).data( 'ref-id' ), 10 ) ).get();
	},

	_addChip( $field, refId, title, itemType = '' ) {
		if ( ! refId || this._selectedIds( $field ).includes( refId ) ) {
			return;
		}

		const tpl   = $field.find( '.fs-lms-ref-chip-template' )[ 0 ];
		const $chip = $( tpl.content.firstElementChild.cloneNode( true ) );

		$chip.attr( 'data-ref-id', refId );
		$chip.attr( 'data-item-type', itemType );
		$chip.find( '.fs-lms-ref-title' ).text( title );
		$chip.find( 'input[type="hidden"]' ).val( refId );

		const $badge = $chip.find( '.fs-lms-item-type-badge' );
		if ( itemType === 'problem' ) {
			$badge.text( 'Задача' ).removeAttr( 'hidden' );
		} else {
			$badge.attr( 'hidden', true );
		}

		$field.find( '.fs-lms-ref-chips' ).append( $chip );
	},
};
