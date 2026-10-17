/**
 * Диалог подтверждения. Результат: true — подтверждено, false — отмена; если передан `extraText`,
 * между ними появляется третья кнопка, и её выбор даёт строку 'extra'.
 *
 * @param {string}  message
 * @param {string}  [confirmText]
 * @param {string}  [cancelText]
 * @param {?string} [extraText] Подпись третьей кнопки (необязательна).
 * @return {Promise<boolean|string>}
 */
export function confirmDialog( message, confirmText = 'Подтвердить', cancelText = 'Отмена', extraText = null ) {
	return new Promise( ( resolve ) => {
		const overlay = document.createElement( 'div' );
		overlay.className = 'fs-confirm-overlay';

		const dialog = document.createElement( 'div' );
		dialog.className = 'fs-confirm-dialog';
		dialog.setAttribute( 'role', 'dialog' );
		dialog.setAttribute( 'aria-modal', 'true' );

		const msgEl = document.createElement( 'p' );
		msgEl.className   = 'fs-confirm-message';
		msgEl.textContent = message;

		const actions = document.createElement( 'div' );
		actions.className = 'fs-confirm-actions';

		const cancelBtn = document.createElement( 'button' );
		cancelBtn.type        = 'button';
		cancelBtn.className   = 'button';
		cancelBtn.textContent = cancelText;

		const okBtn = document.createElement( 'button' );
		okBtn.type        = 'button';
		okBtn.className   = 'button button-primary';
		okBtn.textContent = confirmText;

		const cleanup = ( result ) => {
			document.body.removeChild( overlay );
			resolve( result );
		};

		okBtn.addEventListener( 'click', () => cleanup( true ) );
		cancelBtn.addEventListener( 'click', () => cleanup( false ) );
		overlay.addEventListener( 'click', ( e ) => { if ( e.target === overlay ) { cleanup( false ); } } );
		overlay.addEventListener( 'keydown', ( e ) => { if ( e.key === 'Escape' ) { cleanup( false ); } } );

		actions.appendChild( cancelBtn );
		if ( extraText ) {
			const extraBtn = document.createElement( 'button' );
			extraBtn.type        = 'button';
			extraBtn.className   = 'button';
			extraBtn.textContent = extraText;
			extraBtn.addEventListener( 'click', () => cleanup( 'extra' ) );
			actions.appendChild( extraBtn );
		}
		actions.appendChild( okBtn );
		dialog.appendChild( msgEl );
		dialog.appendChild( actions );
		overlay.appendChild( dialog );
		document.body.appendChild( overlay );
		cancelBtn.focus();
	} );
}
