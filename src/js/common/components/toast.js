let container = null;

function getContainer() {
	if ( ! container || ! document.body.contains( container ) ) {
		container = document.createElement( 'div' );
		container.className = 'fs-toast-container';
		container.setAttribute( 'aria-live', 'polite' );
		container.setAttribute( 'aria-atomic', 'false' );
		document.body.appendChild( container );
	}
	return container;
}

function dismiss( toast ) {
	toast.classList.remove( 'fs-toast--visible' );
	setTimeout( () => toast.remove(), 300 );
}

export function showToast( message, type = 'error', duration = null ) {
	const auto = duration ?? ( type === 'error' || type === 'warning' ? 4000 : 2500 );

	const toast = document.createElement( 'div' );
	toast.className = `fs-toast fs-toast--${ type }`;
	toast.setAttribute( 'role', 'alert' );

	const msg = document.createElement( 'span' );
	msg.className   = 'fs-toast__message';
	msg.textContent = message;

	const closeBtn = document.createElement( 'button' );
	closeBtn.type      = 'button';
	closeBtn.className = 'fs-toast__close';
	closeBtn.setAttribute( 'aria-label', 'Закрыть' );
	closeBtn.innerHTML = '&times;';

	toast.appendChild( msg );
	toast.appendChild( closeBtn );
	getContainer().appendChild( toast );

	void toast.offsetHeight;
	toast.classList.add( 'fs-toast--visible' );

	let timer = setTimeout( () => dismiss( toast ), auto );

	closeBtn.addEventListener( 'click', () => {
		clearTimeout( timer );
		dismiss( toast );
	} );
	toast.addEventListener( 'mouseenter', () => clearTimeout( timer ) );
	toast.addEventListener( 'mouseleave', () => {
		timer = setTimeout( () => dismiss( toast ), auto );
	} );

	return toast;
}
