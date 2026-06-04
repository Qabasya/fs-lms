const $ = jQuery;

let $container = null;

function getContainer() {
    if ( ! $container || ! $container.length ) {
        $container = $( '<div class="fs-toast-container" aria-live="polite" aria-atomic="false"></div>' );
        $( 'body' ).append( $container );
    }
    return $container;
}

function dismiss( $toast ) {
    $toast.removeClass( 'fs-toast--visible' );
    setTimeout( () => $toast.remove(), 300 );
}

/**
 * @param {string} message
 * @param {'error'|'success'|'warning'|'info'} [type='error']
 * @param {number|null} [duration=null]
 * @returns {JQuery}
 */
export function showToast( message, type = 'error', duration = null ) {
    const auto = duration ?? ( type === 'error' || type === 'warning' ? 4000 : 2500 );

    const $toast = $( `<div class="fs-toast fs-toast--${type}" role="alert">
        <span class="fs-toast__message"></span>
        <button type="button" class="fs-toast__close" aria-label="Закрыть">&times;</button>
    </div>` );

    $toast.find( '.fs-toast__message' ).text( message );

    getContainer().append( $toast );
    void $toast[ 0 ].offsetHeight;
    $toast.addClass( 'fs-toast--visible' );

    let timer = setTimeout( () => dismiss( $toast ), auto );

    $toast.on( 'click', '.fs-toast__close', () => {
        clearTimeout( timer );
        dismiss( $toast );
    } );

    $toast.on( 'mouseenter', () => clearTimeout( timer ) );
    $toast.on( 'mouseleave', () => {
        timer = setTimeout( () => dismiss( $toast ), auto );
    } );

    return $toast;
}