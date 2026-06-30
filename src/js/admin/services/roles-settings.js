import '../_types.js';
import { showToast } from '../modules/toast.js';

export const RolesSettings = {
	init() {
		const wrap = document.querySelector( '.fs-lms-roles-tab' );
		if ( ! wrap ) { return; }

		wrap.addEventListener( 'change', ( e ) => {
			if ( ! e.target.classList.contains( 'fs-role-checkbox' ) ) { return; }
			this.saveRow( e.target.closest( 'tr' ), wrap );
		} );
	},

	saveRow( row, wrap ) {
		const userId  = row.dataset.userId;
		const nonce   = wrap.dataset.nonce;
		const action  = wrap.dataset.action;
		const checked = Array.from( row.querySelectorAll( '.fs-role-checkbox:checked' ) )
			.map( ( cb ) => cb.value );

		row.querySelectorAll( '.fs-role-checkbox' ).forEach( ( cb ) => { cb.disabled = true; } );

		const data = new URLSearchParams( {
			action,
			security : nonce,
			user_id  : userId,
		} );
		checked.forEach( ( r ) => data.append( 'roles[]', r ) );

		fetch( window.fs_lms_vars.ajaxurl, { method: 'POST', body: data } )
			.then( ( r ) => r.json() )
			.then( ( res ) => {
				if ( res.success ) {
					showToast( 'Изменения сохранены', 'success' );
				} else {
					showToast( res.data?.message || 'Ошибка сохранения', 'error' );
				}
			} )
			.catch( () => showToast( 'Ошибка запроса', 'error' ) )
			.finally( () => {
				row.querySelectorAll( '.fs-role-checkbox' ).forEach( ( cb ) => { cb.disabled = false; } );
			} );
	},
};
