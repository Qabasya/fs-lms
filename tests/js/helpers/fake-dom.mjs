/**
 * Минимальная заглушка DOM для тестов виджетов без jsdom: элементы с className/classList, dataset,
 * атрибутами, детьми и обработчиками событий (`emit`).
 */
export class FakeElement {
	constructor( tag = 'div' ) {
		this.tagName    = tag.toUpperCase();
		this.className  = '';
		this.dataset    = {};
		this.attributes = {};
		this.children   = [];
		this.listeners  = {};
		this.parent     = null;
		this.disabled   = false;
		this.draggable  = false;
		this.hidden     = false;
		this.type       = '';
		this.value      = '';
		this._text      = '';
		const element   = this;
		this.classList  = {
			contains: ( name ) => element.className.split( /\s+/ ).includes( name ),
			add:      ( name ) => { if ( ! element.classList.contains( name ) ) { element.className = `${ element.className } ${ name }`.trim(); } },
			remove:   ( name ) => { element.className = element.className.split( /\s+/ ).filter( ( c ) => c && c !== name ).join( ' ' ); },
			toggle:   ( name, on ) => ( on ?? ! element.classList.contains( name ) ) ? element.classList.add( name ) : element.classList.remove( name ),
		};
	}

	get textContent() {
		return this.children.length ? this.children.map( ( c ) => c.textContent ).join( '' ) : this._text;
	}

	set textContent( value ) {
		this.children = [];
		this._text    = String( value );
	}

	appendChild( child ) {
		child.parent = this;
		this.children.push( child );
		return child;
	}

	replaceChildren( ...nodes ) {
		this.children = [];
		this._text    = '';
		nodes.forEach( ( node ) => this.appendChild( node ) );
	}

	setAttribute( name, value ) {
		this.attributes[ name ] = String( value );
	}

	removeAttribute( name ) {
		delete this.attributes[ name ];
	}

	addEventListener( name, listener ) {
		( this.listeners[ name ] ??= [] ).push( listener );
	}

	/** Вызывает обработчики события; `event` дополняется заглушками preventDefault/dataTransfer. */
	emit( name, event = {} ) {
		const e = { target: this, preventDefault() {}, dataTransfer: { setData() {}, effectAllowed: '' }, ...event };
		( this.listeners[ name ] ?? [] ).forEach( ( listener ) => listener( e ) );
	}

	/** Все потомки (в глубину), подходящие под класс. */
	byClass( name ) {
		return this.children.flatMap( ( c ) => [ ...( c.classList.contains( name ) ? [ c ] : [] ), ...c.byClass( name ) ] );
	}
}

export function installFakeDocument() {
	globalThis.window   = globalThis.window ?? {};
	globalThis.document = { createElement: ( tag ) => new FakeElement( tag ) };
}
