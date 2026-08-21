/* global jQuery */
const $ = jQuery;

/**
 * ProblemBankFields — метабокс «Предмет и номер задания» банковской задачи
 * (`fs_lms_problems`). Поле «Номер задания» имеет смысл только при выбранном
 * предмете и ограничено номерами таксономии этого предмета (`data-numbers`,
 * см. `ProblemsController::numberOptionsFor()`) — переключаем видимость и
 * пересобираем список опций без AJAX.
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

		$subject.on( 'change', () => {
			const key     = String( $subject.val() || '' );
			const current = String( $number.val() || '' );
			const options = numbersBySubject[ key ] || [];

			$row.prop( 'hidden', ! key );

			$number.empty();
			$number.append( new Option( '— не выбран —', '' ) );
			options.forEach( ( n ) => $number.append( new Option( n, n ) ) );
			$number.val( options.includes( current ) ? current : '' );
		} );
	},
};
