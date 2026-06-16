import '../_types.js';

/**
 * LessonBucketService — управляет бакетами урока (практика / СР / ДЗ).
 *
 * Добавляет/удаляет чипы заданий и синхронизирует скрытые инпуты.
 * Загружает кандидатов через AJAX (GetLessonTaskCandidates).
 */
export const LessonBucketService = {

	/** @type {jQuery|null} Активный бакет при открытии пикера */
	_activeBucket: null,
	_searchTimer: null,

	init() {
		if ( ! $( '.fs-lms-lesson-metabox' ).length ) {
			return;
		}

		this._bindChipRemoval();
		this._bindSearch();
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

		// Закрыть дропдаун при клике вне
		$( document ).on( 'click', ( e ) => {
			if ( ! $( e.target ).closest( '.fs-lms-bucket-search-wrap' ).length ) {
				$( '.fs-lms-search-dropdown' ).hide();
			}
		} );
	},

	_bindCreateTask() {
		$( document ).on( 'click', '.fs-lms-create-task-in-bucket', ( e ) => {
			const $bucket       = $( e.currentTarget ).closest( '.fs-lms-bucket-field' );
			this._activeBucket  = $bucket;

			// Открываем существующую модалку создания задания с context=lesson
			if ( window.TaskModal ) {
				window.TaskModal.openWithContext( {
					subject_key : $bucket.data( 'subject' ),
					context     : 'lesson',
					onCreated   : ( task ) => this._addChip( $bucket, task.id, task.title ),
				} );
			}
		} );
	},

	/**
	 * Загружает кандидатов с сервера и показывает дропдаун.
	 *
	 * @param {string} search
	 * @param {jQuery} $bucket
	 */
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
			search     : search,
		} )
		.done( ( res ) => {
			if ( ! res.success || ! res.data.length ) {
				$dropdown.html( '<div class="fs-lms-dropdown-empty">Ничего не найдено</div>' ).show();
				return;
			}

			const html = res.data.map( ( t ) =>
				`<div class="fs-lms-dropdown-item" data-task-id="${ t.id }" data-task-title="${ $( '<div>' ).text( t.title ).html() }">
					${ $( '<div>' ).text( t.title ).html() }
				</div>`
			).join( '' );

			$dropdown.html( html ).show();
		} );
	},

	/**
	 * Добавляет задание в бакет как чип.
	 *
	 * @param {jQuery} $bucket
	 * @param {number} taskId
	 * @param {string} taskTitle
	 */
	_addChip( $bucket, taskId, taskTitle ) {
		const bucketId  = $bucket.data( 'bucket' );
		const metaBase  = `fs_lms_meta[${ bucketId }][task_ids][]`;

		// Не добавлять дубликат
		if ( $bucket.find( `.fs-lms-task-chip[data-task-id="${ taskId }"]` ).length ) {
			return;
		}

		const $chip = $( `
			<div class="fs-lms-task-chip" data-task-id="${ taskId }">
				<span class="fs-lms-chip-title">${ $( '<div>' ).text( taskTitle ).html() }</span>
				<button type="button" class="fs-lms-chip-remove" aria-label="Удалить">×</button>
				<input type="hidden" name="${ metaBase }" value="${ taskId }">
			</div>
		` );

		$bucket.find( '.fs-lms-bucket-chips' ).append( $chip );
	},
};

// Перехват клика по дропдаун-элементу (делегирование на document для динамических элементов)
$( document ).on( 'click', '.fs-lms-dropdown-item', function () {
	const $item     = $( this );
	const $bucket   = $item.closest( '.fs-lms-bucket-field' );
	const taskId    = parseInt( $item.data( 'task-id' ), 10 );
	const taskTitle = $item.data( 'task-title' );

	LessonBucketService._addChip( $bucket, taskId, taskTitle );
	$bucket.find( '.fs-lms-search-dropdown' ).hide();
	$bucket.find( '.fs-lms-task-search-input' ).val( '' );
} );
