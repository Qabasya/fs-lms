/**
 * Универсальный компонент автодополнения через DaData Suggestions API.
 * Инициализируется только при наличии токена — проверка на стороне вызывающего.
 */

const DADATA_BASE = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/';
const MIN_CHARS   = 3;
const DEBOUNCE_MS = 300;

function debounce( fn, ms ) {
	let timer;
	return ( ...args ) => {
		clearTimeout( timer );
		timer = setTimeout( () => fn( ...args ), ms );
	};
}

async function fetchSuggestions( endpoint, body, token ) {
	try {
		const res = await fetch( DADATA_BASE + endpoint, {
			method: 'POST',
			headers: {
				'Content-Type':  'application/json',
				'Accept':        'application/json',
				'Authorization': 'Token ' + token,
			},
			body: JSON.stringify( body ),
		} );
		if ( ! res.ok ) { return []; }
		const data = await res.json();
		return data.suggestions ?? [];
	} catch {
		return [];
	}
}

/**
 * Подключает автодополнение DaData к полю ввода.
 *
 * @param {HTMLInputElement} input
 * @param {{ endpoint: string, buildBody: (query: string) => object, getValue: (suggestion: object) => string }} config
 * @param {string} token  DaData API-токен (read-only)
 */
export function createDadataSuggest( input, { endpoint, buildBody, getValue }, token ) {
	const list = document.createElement( 'ul' );
	list.className = 'fs-dadata-suggestions';
	list.hidden    = true;
	list.setAttribute( 'role', 'listbox' );
	input.parentElement.appendChild( list );

	let activeIndex = -1;

	const close = () => {
		list.hidden = true;
		activeIndex = -1;
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
		const suggestions = await fetchSuggestions( endpoint, buildBody( query ), token );

		list.innerHTML = '';
		if ( ! suggestions.length ) { close(); return; }

		suggestions.forEach( ( s ) => {
			const value    = getValue( s );
			const li       = document.createElement( 'li' );
			li.className   = 'fs-dadata-suggestions__item';
			li.textContent = value;
			li.setAttribute( 'role', 'option' );
			li.addEventListener( 'mousedown', ( e ) => {
				e.preventDefault();
				select( value );
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
