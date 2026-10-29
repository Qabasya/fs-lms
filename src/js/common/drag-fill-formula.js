/**
 * Формула «Перетаскивания пропусков»: слоты — `{N}` (зеркало PHP `DragFillParser`).
 */

/** Число слотов — наибольший номер `{N}`. */
export function countSlots( formula ) {
	const numbers = Array.from( String( formula ).matchAll( /\{(\d+)\}/g ), ( m ) => Number( m[ 1 ] ) );

	return numbers.length ? Math.max( ...numbers ) : 0;
}

/** «Порядок неважен»: группа всех слотов одна — любая перестановка верна. */
export function setAllGroups( count ) {
	return Array.from( { length: count }, () => 1 );
}
