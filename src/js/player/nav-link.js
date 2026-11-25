/**
 * Чистые правила адресации плеера (без DOM, чтобы их можно было проверить тестами):
 * куда ведёт «Назад» и как номер шага связан с адресом `?step=`.
 */

/** Где в `sessionStorage` помнится экран кабинета, с которого открыли урок (переживает переходы между уроками). */
export const BACK_STORAGE_KEY = 'fsPlayerBack';

/** Адрес из `document.referrer`, если он — страница кабинета этого же сайта; иначе пусто. */
export function profileReferrer( referrer, profileUrl ) {
	try {
		const ref     = new URL( referrer );
		const profile = new URL( profileUrl );
		return ( ref.origin === profile.origin && ref.pathname === profile.pathname && ! ref.username && ! ref.password ) ? ref.href : '';
	} catch ( e ) {
		return '';
	}
}

/**
 * Возврат на известный адрес кабинета. История и referrer не обязаны описывать одну страницу.
 * На экране «Мои курсы» выбранная группа может не попасть в адрес кабинета — берём её из ссылки урока.
 * @param {{referrer: string, profileUrl: string, stored: string, fallback: string}} ctx
 */
export function profileReturnUrl( { referrer, profileUrl, stored, fallback } ) {
	const address = profileReferrer( referrer, profileUrl ) || profileReferrer( stored, profileUrl );
	if ( ! address ) { return fallback; }

	const target = new URL( address );
	const course = profileReferrer( fallback, profileUrl );
	if ( course && target.searchParams.get( 'screen' ) === 'student-lessons' && ! target.searchParams.has( 'group' ) ) {
		const group = new URL( course ).searchParams.get( 'group' );
		if ( group ) { target.searchParams.set( 'group', group ); }
	}
	return target.href;
}

/** Номер шага для адреса (с 1, виртуальный шаг «Трансляция» не считается); у виртуального — 0: параметр убирается. */
export function stepNumberOf( panels, index ) {
	if ( '1' === panels[ index ]?.dataset.virtual ) { return 0; }
	return index + 1 - panels.slice( 0, index ).filter( ( p ) => '1' === p.dataset.virtual ).length;
}

/**
 * Индекс шага по значению `?step=`: число — номер шага (как в {@see stepNumberOf}), иначе — ключ шага
 * (так ссылки из «Моих оценок» и дедлайнов держат шаг после правки порядка). -1 — такого шага нет.
 */
export function stepIndexFromParam( panels, value ) {
	if ( ! value ) { return -1; }
	if ( /^\d+$/.test( value ) ) {
		const wanted = parseInt( value, 10 );
		return panels.findIndex( ( _, i ) => stepNumberOf( panels, i ) === wanted );
	}
	return panels.findIndex( ( p ) => p.dataset.step === value );
}
