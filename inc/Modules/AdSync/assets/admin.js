/**
 * Admin-JS модуля AdSync (self-contained, вне core-бандла и вне ESLint src/js).
 * Сохранение настроек секции «Синхронизация с доменом (AD)» в табе «Конфигурация».
 *
 * Глобал fsLmsAdSync = { ajaxurl, action, nonce } — локализуется в AdSyncSettingsController.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var $form = $( '#fs-adsync-form' );
		if ( ! $form.length || typeof window.fsLmsAdSync === 'undefined' ) {
			return;
		}

		// Генерация секрета HMAC на клиенте (crypto.getRandomValues) — строка define() для wp-config.php.
		// Копирование — через core-класс .js-copy-key (см. config-settings.js). Секрет в БД не сохраняется.
		$form.on( 'click', '[data-ad-generate-secret]', function () {
			var bytes = new Uint8Array( 32 );
			( window.crypto || window.msCrypto ).getRandomValues( bytes );
			var hex = '';
			for ( var i = 0; i < bytes.length; i++ ) {
				hex += ( '0' + bytes[ i ].toString( 16 ) ).slice( -2 );
			}
			$( '#fs-adsync-secret-value' ).val( "define( 'FS_LMS_AD_HMAC_SECRET', '" + hex + "' );" ); // для wp-config.php
			$( '#fs-adsync-secret-raw' ).val( hex ); // для .env Python-сервиса
			$( '#fs-adsync-secret-output' ).removeAttr( 'hidden' );
		} );

	} );
}( jQuery ) );
