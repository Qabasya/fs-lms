import '../_types.js';
import { TaskModalManager } from '../managers/task-modal-manager.js';
import { DraftCreatorModal } from '../modals/draft-creator-modal.js';

/* global jQuery, fs_lms_vars */
const $ = jQuery;

/**
 * Сопоставление типа ссылки с AJAX-экшеном и nonce.
 * task   — задания внутри работы
 * work   — работы внутри урока
 * lesson — уроки внутри курса
 */
const REF_MAP = {
	task:   { action: 'getWorkTaskCandidates',     nonceKey: 'authorWork' },
	work:   { action: 'getLessonWorkCandidates',   nonceKey: 'authorLesson' },
	lesson: { action: 'getCourseLessonCandidates', nonceKey: 'authorCourse' },
};

/**
 * RefSelector — единый конструктор-селектор ссылок для банков
 * (работа→задания, урок→работы, курс→уроки). Поиск + чипы + порядок (drag-drop).
 * T1.24: для task-полей загружает фильтр коллекций.
 * T1.23: кнопка «Создать» открывает task-modal (для task) или draft-creator-modal (для work/lesson).
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

			this._addChip( $field, parseInt( $opt.data( 'ref-id' ), 10 ), String( $opt.data( 'ref-title' ) ) );

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

	// T1.23 — кнопки «Создать»
	_bindCreate() {
		$( document ).on( 'click', '.fs-lms-ref-create', ( e ) => {
			const $field   = $( e.currentTarget ).closest( '.fs-lms-ref-field' );
			const refType  = String( $field.data( 'ref-type' ) );

			if ( refType === 'task' ) {
				// Открываем существующую task-modal в режиме чипа
				TaskModalManager.openForChip( ( id, title ) => {
					this._addChip( $field, id, title );
				} );
			} else {
				// Работа из урока / Урок из курса — лёгкая модалка
				DraftCreatorModal.open( {
					refType,
					$field,
					onCreated: ( id, title ) => this._addChip( $field, id, title ),
				} );
			}
		} );
	},

	// T1.24 — загрузка коллекций для task ref-полей
	_loadCollections() {
		$( '.fs-lms-ref-field[data-ref-type="task"]' ).each( ( _, el ) => {
			const $field   = $( el );
			const subject  = String( $field.data( 'subject' ) );

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

		// T1.24: коллекция-фильтр только для task ref
		if ( refType === 'task' ) {
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
				$( '<div/>', {
					class: 'fs-lms-ref-option',
					text:  item.title,
				} ).attr( 'data-ref-id', item.id ).attr( 'data-ref-title', item.title ).appendTo( $dropdown );
			} );
		}

		$dropdown.removeAttr( 'hidden' );
	},

	_selectedIds( $field ) {
		return $field.find( '.fs-lms-ref-chip' ).map( ( _, el ) => parseInt( $( el ).data( 'ref-id' ), 10 ) ).get();
	},

	_addChip( $field, refId, title ) {
		if ( ! refId || this._selectedIds( $field ).includes( refId ) ) {
			return;
		}

		const tpl   = $field.find( '.fs-lms-ref-chip-template' )[ 0 ];
		const $chip = $( tpl.content.firstElementChild.cloneNode( true ) );

		$chip.attr( 'data-ref-id', refId );
		$chip.find( '.fs-lms-ref-title' ).text( title );
		$chip.find( 'input[type="hidden"]' ).val( refId );

		$field.find( '.fs-lms-ref-chips' ).append( $chip );
	},
};
