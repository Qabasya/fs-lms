/**
 * Контекст статьи для поиска в элементе «Задание» (self-contained, вне core-бандла и вне ESLint src/js).
 *
 * WPBakery шлёт подсказки autocomplete без ID записи и без данных формы, поэтому
 * к его запросу дописываем ID статьи и текущий выбор «Номера задания» — ещё не
 * сохранённый. Разбор — TaskSuggestions::articleContext().
 *
 * Параметр номера уходит и при пустом выборе: сервер тогда ищет по всему предмету,
 * а не по сохранённому номеру. Нет поля номера на странице (фронтенд-редактор
 * WPBakery) — параметр не шлём, сервер берёт номер, сохранённый у статьи.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.fsLmsArticleTaskContext;

	if ( ! cfg || ! $ || ! $.ajaxPrefilter ) {
		return;
	}

	// Поле номера рисует SubjectTaxonomyRegistrar: select, radio или checkbox с name tax_input[{key}_task_number][].
	var FIELD = '[name^="tax_input["][name$="_task_number][]"]';

	function postId() {
		return $( '#post_ID' ).val() || window.vc_post_id || '';
	}

	function numberParams() {
		var $fields = $( FIELD );

		if ( ! $fields.length ) {
			return null;
		}

		var values = [];

		$fields.filter( 'select' ).each( function () {
			values = values.concat( $( this ).val() || [] );
		} );
		$fields.filter( ':checked' ).each( function () {
			values.push( this.value );
		} );

		if ( ! values.length ) {
			values.push( '' );
		}

		return values.map( function ( value ) {
			return encodeURIComponent( cfg.numberParam + '[]' ) + '=' + encodeURIComponent( value );
		} ).join( '&' );
	}

	$.ajaxPrefilter( function ( options ) {
		var data = typeof options.data === 'string' ? options.data : '';

		if ( -1 === data.indexOf( 'action=vc_get_autocomplete_suggestion' )
			|| -1 === data.indexOf( 'shortcode=' + encodeURIComponent( cfg.shortcode ) ) ) {
			return;
		}

		var extra = [ encodeURIComponent( cfg.postParam ) + '=' + encodeURIComponent( postId() ) ];
		var numbers = numberParams();

		if ( null !== numbers ) {
			extra.push( numbers );
		}

		options.data = data + '&' + extra.join( '&' );
	} );
}( window.jQuery ) );
