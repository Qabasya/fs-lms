/** A score belongs to a slot in this work; selecting another bank task keeps that slot's score. */
export function workSlot( step, index ) {
	const value = Number( step.points ?? 1 );
	return {
		key: step.key || 'slot_' + index,
		taskId: parseInt( step.payload?.ref, 10 ) || 0,
		title: step._title || '',
		points: Number.isInteger( value ) && value >= 1 && value <= 999 ? value : 1,
	};
}

export function workTaskPoints( slots ) {
	return Object.fromEntries( slots.filter( ( slot ) => slot.taskId > 0 ).map( ( slot ) => [ slot.taskId, slot.points ?? 1 ] ) );
}
