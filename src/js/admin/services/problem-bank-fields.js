/* global jQuery, fs_lms_vars */
const $ = jQuery;

/**
 * Заливает JSON boilerplate-контента (id ConditionField-поля → HTML) в уже
 * отрендеренные поля формы задачи: TinyMCE-инстанс, если поле в визуальном
 * режиме, иначе — напрямую в textarea. Ключи, которых нет в текущем шаблоне
 * (нет такого поля на экране), просто игнорируются.
 *
 * @param {string} raw JSON-строка из TaskTypeBoilerplateDTO.content
 */
function applyBoilerplateContent( raw ) {
	let decoded;
	try {
		decoded = JSON.parse( raw );
	} catch ( e ) {
		return;
	}
	if ( ! decoded || 'object' !== typeof decoded ) {
		return;
	}

	Object.keys( decoded ).forEach( ( fieldId ) => {
		const html = decoded[ fieldId ];
		if ( 'string' !== typeof html ) {
			return;
		}

		const editor = window.tinymce && window.tinymce.get( fieldId );
		if ( editor && ! editor.isHidden() ) {
			editor.setContent( html );
			return;
		}

		const el = document.getElementById( fieldId );
		if ( el ) {
			el.value = html;
		}
	} );
}

/**
 * ProblemBankFields — метабокс «Предмет и номер задания» банковской задачи
 * (`fs_lms_problems`). Поле «Номер задания» имеет смысл только при выбранном
 * предмете и ограничено номерами таксономии этого предмета (`data-numbers`,
 * см. `ProblemsController::numberOptionsFor()`) — переключаем видимость и
 * пересобираем список опций без AJAX.
 *
 * Третий dropdown — «Типовое условие» (boilerplate): список подгружается
 * AJAX-ом по (предмет, номер) — `term_slug` считаем на клиенте как
 * `{предмет}_{номер}` (см. `Inc\Services\Subject\TaskNumberTermGuard::normalizeSlug()`),
 * содержимое выбранного варианта заливается в поля условия ниже (см.
 * `applyBoilerplateContent()`), задача не создаётся заново — правится текущая.
 */
export const ProblemBankFields = {
	init() {
		const $subject = $( '#fs_lms_bank_task_subject' );
		if ( ! $subject.length ) {
			return;
		}
		const $row    = $( '.fs-bank-number-row' );
		const $number = $( '#fs_lms_bank_task_number' );
		const numbersBySubject = JSON.parse( $number.attr( 'data-numbers' ) || '{}' );

		const $bpRow = $( '.fs-bank-boilerplate-row' );
		const $bp    = $( '#fs_lms_bank_task_boilerplate' );

		const get = ( action, data ) => new Promise( ( resolve ) => {
			$.get( fs_lms_vars.ajaxurl, Object.assign( {
				action:   fs_lms_vars.ajax_actions[ action ],
				security: fs_lms_vars.nonces.taskCreation,
			}, data ) ).done( ( resp ) => resolve( resp && resp.success ? resp.data : null ) )
				.fail( () => resolve( null ) );
		} );

		const loadBoilerplates = () => {
			const subject = String( $subject.val() || '' );
			const number  = String( $number.val() || '' );

			$bpRow.prop( 'hidden', ! subject || ! number );
			$bp.empty().append( new Option( '— не выбрано —', '' ) ).prop( 'disabled', true );

			if ( ! subject || ! number ) {
				return;
			}

			get( 'getTaskBoilerplates', { subject_key: subject, term_slug: `${ subject }_${ number }` } )
				.then( ( list ) => {
					if ( ! list || ! list.length ) {
						return;
					}
					list.forEach( ( bp ) => $bp.append( new Option( bp.title, bp.uid ) ) );
					$bp.prop( 'disabled', false );
				} );
		};

		$subject.on( 'change', () => {
			const key     = String( $subject.val() || '' );
			const current = String( $number.val() || '' );
			const options = numbersBySubject[ key ] || [];

			$row.prop( 'hidden', ! key );

			$number.empty();
			$number.append( new Option( '— не выбран —', '' ) );
			options.forEach( ( n ) => $number.append( new Option( n, n ) ) );
			$number.val( options.includes( current ) ? current : '' );

			loadBoilerplates();
		} );

		$number.on( 'change', loadBoilerplates );

		$bp.on( 'change', () => {
			const uid = String( $bp.val() || '' );
			if ( ! uid ) {
				return;
			}
			const subject = String( $subject.val() || '' );
			const number  = String( $number.val() || '' );

			get( 'getBoilerplateContent', {
				subject_key: subject,
				term_slug:   `${ subject }_${ number }`,
				uid,
			} ).then( ( data ) => {
				if ( data && data.content ) {
					applyBoilerplateContent( data.content );
				}
			} );
		} );

		if ( $subject.val() && $number.val() ) {
			loadBoilerplates();
		}
	},
};

/**
 * ProblemBankFilters — фильтр-бар над нативной таблицей банка задач
 * (`restrict_manage_posts`, `templates/admin/problems/problem-filters.php`).
 * Тот же каскад «Номер» ограничен предметом, что и в метабоксе выше, но без
 * boilerplate-подстановки и без id (фильтры WP-таблиц id не гарантируют) —
 * селекторы по `name`.
 */
export const ProblemBankFilters = {
	init() {
		const $subject = $( 'select[name="fs_problem_subject"]' );
		const $number  = $( 'select[name="fs_problem_number"]' );
		if ( ! $subject.length || ! $number.length ) {
			return;
		}
		const numbersBySubject = JSON.parse( $number.attr( 'data-numbers' ) || '{}' );
		const allNumbers = Array.from( new Set( Object.values( numbersBySubject ).flat() ) );

		$subject.on( 'change', () => {
			const key     = String( $subject.val() || '' );
			const current = String( $number.val() || '' );
			const options = key ? ( numbersBySubject[ key ] || [] ) : allNumbers;

			$number.empty();
			$number.append( new Option( 'Все номера', '' ) );
			options.forEach( ( n ) => $number.append( new Option( n, n ) ) );
			$number.val( options.includes( current ) ? current : '' );
		} );
	},
};
