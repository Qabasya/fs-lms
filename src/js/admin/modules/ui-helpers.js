/**
 * JS-двойники PHP-рендереров из ui_renderers.php.
 * Генерируют ту же разметку что и PHP — классы должны совпадать.
 */

/**
 * <span class="fs-badge is-{color}">{text}</span>
 * Цвета: green, blue, gray, red, yellow, purple
 *
 * @param {string} text
 * @param {string} color
 * @returns {string}
 */
export function fsBadge( text, color ) {
	return `<span class="fs-badge is-${ color }">${ text }</span>`;
}

/**
 * <span class="fs-empty__title">{text}</span> внутри fs-empty
 *
 * @param {string} title
 * @param {string} [desc]
 * @returns {string}
 */
export function fsEmpty( title, desc = '' ) {
	return `<div class="fs-empty">
		<p class="fs-empty__title">${ title }</p>
		${ desc ? `<p class="fs-empty__desc">${ desc }</p>` : '' }
	</div>`;
}
