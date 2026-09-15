/**
 * Поле WPBakery модуля «Блоки статей» (self-contained, вне core-бандла и вне ESLint src/js).
 *
 * Автор редактирует видимое <textarea>, а в шорткод уходит скрытое .wpb_vc_param_value:
 * текст всегда кодируется в `fsb64:` + base64 от UTF-8 — так в атрибуте шорткода выживают
 * переводы строк, `[`, `]` и кавычки (разбор — BlockValueCodec::decodeText()).
 *
 * WPBakery подключает скрипт в форме каждого элемента, поэтому обработчики делегированные
 * и вешаются один раз.
 */
( function ( $ ) {
	'use strict';

	if ( window.fsLmsArticleBlocksFields ) {
		return;
	}
	window.fsLmsArticleBlocksFields = true;

	var PREFIX = 'fsb64:';
	var INPUT  = '.fs-lms-encoded-field__input';

	function encode( text ) {
		if ( ! text ) {
			return '';
		}

		var bytes  = new window.TextEncoder().encode( text );
		var binary = '';

		for ( var i = 0; i < bytes.length; i++ ) {
			binary += String.fromCharCode( bytes[ i ] );
		}

		return PREFIX + window.btoa( binary );
	}

	function sync( textarea ) {
		$( textarea ).siblings( 'input.wpb_vc_param_value' ).val( encode( textarea.value ) ).trigger( 'change' );
	}

	$( document ).on( 'input change', INPUT, function () {
		sync( this );
	} );

	// Tab в коде — отступ в 4 пробела, в таблице — табуляция (разделитель ячеек).
	// Shift+Tab и сочетания с модификаторами оставляем браузеру: выход из поля с клавиатуры.
	$( document ).on( 'keydown', INPUT, function ( event ) {
		if ( 'Tab' !== event.key || event.shiftKey || event.ctrlKey || event.altKey || event.metaKey ) {
			return;
		}

		event.preventDefault();

		var mode   = $( this ).closest( '.fs-lms-encoded-field' ).data( 'mode' );
		var insert = 'table' === mode ? '\t' : '    ';
		var start  = this.selectionStart;
		var end    = this.selectionEnd;

		this.value          = this.value.slice( 0, start ) + insert + this.value.slice( end );
		this.selectionStart = start + insert.length;
		this.selectionEnd   = start + insert.length;

		sync( this );
	} );
} )( jQuery );
