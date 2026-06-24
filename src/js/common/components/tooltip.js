const $ = jQuery;

/**
 * TooltipComponent — CSS-based tooltips with keyboard support.
 *
 * Usage: add `.fs-lms-tooltip-wrap` with a child `.fs-lms-tooltip-body` anywhere.
 * The component adds aria-expanded and Enter/Space keyboard toggle.
 */
export const TooltipComponent = {
	init( context = document ) {
		$( context ).on( 'keydown', '.fs-lms-tooltip-wrap', function ( e ) {
			if ( e.key === 'Enter' || e.key === ' ' ) {
				e.preventDefault();
				const $wrap = $( this );
				const open  = $wrap.attr( 'aria-expanded' ) === 'true';
				$wrap.attr( 'aria-expanded', open ? 'false' : 'true' );
			}
		} );

		$( context ).on( 'focusout', '.fs-lms-tooltip-wrap', function () {
			$( this ).attr( 'aria-expanded', 'false' );
		} );
	},
};
