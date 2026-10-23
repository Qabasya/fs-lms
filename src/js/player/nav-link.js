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
		return ( ref.origin === profile.origin && ref.pathname === profile.pathname ) ? ref.href : '';
	} catch ( e ) {
		return '';
	}
}

/**
 * Что делает «Назад».
 *
 * - пришли из кабинета и в истории есть куда вернуться — `back` (браузер сам вернёт экран в прежнем виде);
 * - пришли из кабинета в свежей вкладке или перешли между уроками — `go` на запомненный адрес кабинета;
 * - кабинет не открывали (прямая ссылка) — `link`: обычный переход по ссылке кнопки.
 *
 * @param {{referrer: string, profileUrl: string, stored: string, historyLength: number}} ctx
 *
 * @returns {{action: 'back'|'go'|'link', url: string}}
 */
export function backAction( { referrer, profileUrl, stored, historyLength } ) {
	const fromProfile = profileReferrer( referrer, profileUrl );
	if ( fromProfile ) {
		return historyLength > 1 ? { action: 'back', url: fromProfile } : { action: 'go', url: fromProfile };
	}
	const remembered = profileReferrer( stored || '', profileUrl );
	return remembered ? { action: 'go', url: remembered } : { action: 'link', url: '' };
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
