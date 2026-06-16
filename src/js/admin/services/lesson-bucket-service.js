import '../_types.js';

/* global jQuery */
const $ = jQuery;

/**
 * LessonBucketService — управляет бакетами урока (практика / СР / ДЗ).
 */
export const LessonBucketService = {

	_searchTimer: null,

	init() {
		if ( ! $( '.fs-lms-lesson-metabox' ).length ) {
			return;
		}

		this._bindChipRemoval();
		this._bindSearch();
		this._bindDropdownSelect();
		this._bindCreateTask();
	},

	_bindChipRemoval() {
		$( document ).on( 'click', '.fs-lms-chip-remove', ( e ) => {
			$( e.currentTarget ).closest( '.fs-lms-task-chip' ).remove();
		} );
	},

	_bindSearch() {
		$( document ).on( 'input', '.fs-lms-task-search-input', ( e ) => {
			const $input  = $( e.currentTarget );
			const $bucket = $input.closest( '.fs-lms-bucket-field' );

			clearTimeout( this._searchTimer );
			this._searchTimer = setTimeout( () => {
				this._fetchCandidates( $input.val().trim(), $bucket );
			}, 300 );
		} );

		$( document ).on( 'click', ( e ) => {
			if ( ! $( e.target ).closest( '.fs-lms-bucket-search-wrap' ).length ) {
				$( '.fs-lms-search-dropdown' ).hide();
			}
		} );
	},

	_bindDropdownSelect() {
		$( document ).on( 'click', '.fs-lms-dropdown-item', ( e ) => {
			const $item     = $( e.currentTarget );
			const $bucket   = $item.closest( '.fs-lms-bucket-field' );
			const taskId    = parseInt( $item.data( 'task-id' ), 10 );
			const taskTitle = String( $item.data( 'task-title' ) );

			this._addChip( $bucket, taskId, taskTitle );
			$bucket.find( '.fs-lms-search-dropdown' ).hide();
			$bucket.find( '.fs-lms-task-search-input' ).val( '' );
		} );
	},

	_bindCreateTask() {
		$( document ).on( 'click', '.fs-lms-create-task-in-bucket', ( e ) => {
			const $bucket = $( e.currentTarget ).closest( '.fs-lms-bucket-field' );

			if ( window.TaskModal ) {
				window.TaskModal.openWithContext( {
					subject_key: $bucket.data( 'subject' ),
					context    : 'lesson',
					onCreated  : ( task ) => this._addChip( $bucket, task.id, task.title ),
				} );
			}
		} );
	},

	_fetchCandidates( search, $bucket ) {
		const vars       = window.fs_lms_vars;
		const subjectKey = $bucket.data( 'subject' );
		const $dropdown  = $bucket.find( '.fs-lms-search-dropdown' );

		if ( search.length < 2 ) {
			$dropdown.hide();
			return;
		}

		$.post( vars.ajaxurl, {
			action     : vars.ajax_actions.getLessonTaskCandidates,
			security   : vars.nonces.authorLesson,
			subject_key: subjectKey,
			scope      : 'subject',
			search,
		} ).done( ( res ) => {
			if ( ! res.success || ! res.data.length ) {
				$dropdown.html( '<div class="fs-lms-dropdown-empty">Ничего не найдено</div>' ).show();
				return;
			}

			const html = res.data.map( ( t ) => {
				const escaped = $( '<div>' ).text( t.title ).html();
				return `<div class="fs-lms-dropdown-item" data-task-id="${ t.id }" data-task-title="${ escaped }">${ escaped }</div>`;
			} ).join( '' );

			$dropdown.html( html ).show();
		} );
	},

	_addChip( $bucket, taskId, taskTitle ) {
		const bucketId = $bucket.data( 'bucket' );
		const metaBase = `fs_lms_meta[${ bucketId }][task_ids][]`;

		if ( $bucket.find( `.fs-lms-task-chip[data-task-id="${ taskId }"]` ).length ) {
			return;
		}

		const escaped = $( '<div>' ).text( taskTitle ).html();
		const $chip   = $( `
			<div class="fs-lms-task-chip" data-task-id="${ taskId }">
				<span class="fs-lms-chip-title">${ escaped }</span>
				<button type="button" class="fs-lms-chip-remove" aria-label="Удалить">×</button>
				<input type="hidden" name="${ metaBase }" value="${ taskId }">
			</div>
		` );

		$bucket.find( '.fs-lms-bucket-chips' ).append( $chip );
	},
};
