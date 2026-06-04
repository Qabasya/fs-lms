/**
 * Автодополнение адреса через DaData Suggestions API.
 * Инициализируется только при наличии токена в fs_lms_join_vars.dadata_token.
 */

const DADATA_URL  = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address';
const MIN_CHARS   = 3;
const DEBOUNCE_MS = 300;

function debounce( fn, ms ) {
	let timer;
	return ( ...args ) => {
		clearTimeout( timer );
		timer = setTimeout( () => fn( ...args ), ms );
	};
}

async function fetchSuggestions( query, token ) {
	try {
		const res = await fetch( DADATA_URL, {
			method: 'POST',
			headers: {
				'Content-Type':  'application/json',
				'Accept':        'application/json',
				'Authorization': 'Token ' + token,
			},
			body: JSON.stringify( { query, count: 7 } ),
		} );
		if ( ! res.ok ) { return []; }
		const data = await res.json();
		return data.suggestions ?? [];
	} catch {
		return [];
	}
}

export function initDadataAddress( token ) {
	if ( ! token ) { return; }

	const input = document.getElementById( 'fs_address' );
	if ( ! input ) { return; }

	const list = document.createElement( 'ul' );
	list.className = 'fs-dadata-suggestions';
	list.hidden    = true;
	list.setAttribute( 'role', 'listbox' );
	input.parentElement.appendChild( list );

	let activeIndex = -1;

	const close = () => {
		list.hidden  = true;
		activeIndex  = -1;
	};

	const select = ( value ) => {
		input.value = value;
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		close();
		input.focus();
	};

	const updateActive = () => {
		list.querySelectorAll( '.fs-dadata-suggestions__item' ).forEach( ( el, i ) => {
			el.classList.toggle( 'is-active', i === activeIndex );
		} );
	};

	const search = debounce( async ( query ) => {
		if ( query.length < MIN_CHARS ) { close(); return; }
		const suggestions = await fetchSuggestions( query, token );

		list.innerHTML = '';
		if ( ! suggestions.length ) { close(); return; }

		suggestions.forEach( ( s ) => {
			const li       = document.createElement( 'li' );
			li.className   = 'fs-dadata-suggestions__item';
			li.textContent = s.value;
			li.setAttribute( 'role', 'option' );
			li.addEventListener( 'mousedown', ( e ) => {
				e.preventDefault(); // не даём blur-у на input сработать раньше клика
				select( s.value );
			} );
			list.appendChild( li );
		} );

		list.hidden = false;
		activeIndex = -1;
	}, DEBOUNCE_MS );

	input.addEventListener( 'input',  ( e ) => search( e.target.value.trim() ) );
	input.addEventListener( 'blur',   ()    => setTimeout( close, 150 ) );

	input.addEventListener( 'keydown', ( e ) => {
		if ( list.hidden ) { return; }

		const items = list.querySelectorAll( '.fs-dadata-suggestions__item' );
		if ( ! items.length ) { return; }

		if ( e.key === 'ArrowDown' ) {
			e.preventDefault();
			activeIndex = Math.min( activeIndex + 1, items.length - 1 );
			updateActive();
		} else if ( e.key === 'ArrowUp' ) {
			e.preventDefault();
			activeIndex = Math.max( activeIndex - 1, 0 );
			updateActive();
		} else if ( e.key === 'Enter' && activeIndex >= 0 ) {
			e.preventDefault();
			select( items[ activeIndex ].textContent );
		} else if ( e.key === 'Escape' ) {
			close();
		}
	} );
}
