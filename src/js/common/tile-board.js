/**
 * Состояние «банк плиток + слоты» без DOM: общее для режима плиток «Пропусков в тексте»
 * и типа «Перетаскивание пропусков». Плитки различаются по id, а не по тексту (дубли возможны).
 * Способы ввода — перетаскивание (dragStart/drop) и тап (select/tapSlot); оба сводятся к place/release.
 *
 * @param {{tiles: string[], slots: number, onChange?: function}} options
 */
export function createBoard( { tiles, slots, onChange = () => {} } ) {
	const all      = tiles.map( ( text, id ) => ( { id, text } ) );
	const placed   = Array.from( { length: slots }, () => null );
	let selectedId = null;
	let dragId     = null;
	let frozen     = false;

	const byId     = ( id ) => all.find( ( t ) => t.id === id ) ?? null;
	const slotOf   = ( id ) => placed.indexOf( id );
	const changed  = () => onChange();

	function place( id, index ) {
		if ( frozen || ! byId( id ) || index < 0 || index >= slots ) {
			return;
		}
		const from = slotOf( id );
		if ( from === index ) {
			return;
		}
		if ( from !== -1 ) {
			placed[ from ] = null;
		}
		placed[ index ] = id;
		selectedId      = null;
		changed();
	}

	function release( index ) {
		if ( frozen || placed[ index ] === null || placed[ index ] === undefined ) {
			return;
		}
		placed[ index ] = null;
		changed();
	}

	function select( id ) {
		if ( frozen ) {
			return;
		}
		selectedId = selectedId === id ? null : id;
	}

	function tapSlot( index ) {
		if ( selectedId !== null ) {
			place( selectedId, index );
			return;
		}
		release( index );
	}

	function dragStart( id ) {
		if ( ! frozen ) {
			dragId = id;
		}
	}

	function drop( target ) {
		const id = dragId;
		dragId   = null;
		if ( frozen || id === null ) {
			return;
		}
		if ( target.bank ) {
			const from = slotOf( id );
			if ( from !== -1 ) {
				release( from );
			}
			return;
		}
		place( id, target.slot );
	}

	function restore( answer ) {
		placed.fill( null );
		Object.entries( answer ?? {} ).forEach( ( [ index, text ] ) => {
			const tile = all.find( ( t ) => t.text === text && ! placed.includes( t.id ) );
			if ( tile && index in placed ) {
				placed[ index ] = tile.id;
			}
		} );
	}

	return {
		place,
		release,
		select,
		tapSlot,
		dragStart,
		drop,
		restore,
		lock:     () => {
			frozen     = true;
			selectedId = null;
			dragId     = null;
		},
		locked:   () => frozen,
		selected: () => selectedId,
		dragging: () => dragId,
		slot:     ( index ) => byId( placed[ index ] ),
		bank:     () => all.filter( ( t ) => ! placed.includes( t.id ) ),
		collect:  () => Object.fromEntries( placed.map( ( id, index ) => [ index, byId( id )?.text ?? '' ] ) ),
	};
}
