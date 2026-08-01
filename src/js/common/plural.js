/**
 * Русское склонение по числу — единственный источник правила для всех JS-бандлов.
 * PHP-зеркало: `Inc\Services\Shared\Pluralizer`.
 */

/**
 * Выбирает форму слова: pluralRu( 3, 'задание', 'задания', 'заданий' ) → 'задания'.
 *
 * @param {number} n    Число.
 * @param {string} one  Форма для 1.
 * @param {string} few  Форма для 2–4.
 * @param {string} many Форма для 5+ и 11–14.
 *
 * @return {string}
 */
export function pluralRu( n, one, few, many ) {
	const m10 = Math.abs( n ) % 10;
	const m100 = Math.abs( n ) % 100;

	if ( m10 === 1 && m100 !== 11 ) {
		return one;
	}

	if ( m10 >= 2 && m10 <= 4 && ( m100 < 10 || m100 >= 20 ) ) {
		return few;
	}

	return many;
}