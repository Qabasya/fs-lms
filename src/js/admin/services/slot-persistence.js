/** Serializes independent immutable slot snapshots so slower requests cannot overwrite newer edits. */
export function createSlotSaveQueue( persist ) {
	let pending = Promise.resolve();
	return ( slots ) => {
		const snapshot = slots.map( ( slot ) => ( { ...slot } ) );
		pending = pending.catch( () => {} ).then( () => persist( snapshot ) );
		return pending;
	};
}
