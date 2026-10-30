/**
 * Текст плитки и формулы «Перетаскивания пропусков» → безопасный HTML: всё экранируется, кроме `<sub>`/`<sup>` без атрибутов.
 * PHP-двойник — `Inc\Shared\TileText::html()`. Значение плитки в ответе ученика остаётся исходным (вместе с тегами).
 *
 * @param {unknown} text
 * @returns {string}
 */
export function tileHtml( text ) {
	const escaped = String( text ?? '' )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' )
		.replace( /'/g, '&#039;' );

	return escaped.replace( /&lt;(\/?)(sub|sup)&gt;/g, '<$1$2>' );
}

/** Читаемый текст без `<sub>`/`<sup>` (подписи, aria). */
export function tilePlain( text ) {
	return String( text ?? '' ).replace( /<\/?(?:sub|sup)>/g, '' );
}
