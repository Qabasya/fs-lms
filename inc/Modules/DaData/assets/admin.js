/**
 * Admin-JS модуля DaData (self-contained, вне core-бандла и вне ESLint src/js).
 * Сохранение токена секции «Автодополнение с помощью DaData» в табе «Конфигурация».
 *
 * Глобал fsLmsDaData = { ajaxurl, action, nonce } — локализуется в DaDataSettingsController.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var $form = $( '#fs-dadata-form' );
		if ( ! $form.length || typeof window.fsLmsDaData === 'undefined' ) {
			return;
		}

		$form.on( 'submit', function ( e ) {
			e.preventDefault();

			var $btn    = $( '#fs-dadata-save' );
			var $status = $( '#fs-dadata-status' );

			$btn.prop( 'disabled', true );
			$status.text( '' ).removeClass( 'fs-config-status--ok fs-config-status--err' );

			$.post( window.fsLmsDaData.ajaxurl, {
				action:       window.fsLmsDaData.action,
				security:     window.fsLmsDaData.nonce,
				dadata_token: $( '#fs-dadata-token' ).val()
			} ).done( function ( res ) {
				if ( res.success ) {
					$status.text( 'Сохранено.' ).addClass( 'fs-config-status--ok' );
				} else {
					$status.text( ( res.data && res.data.message ) || res.data || 'Ошибка.' ).addClass( 'fs-config-status--err' );
				}
			} ).fail( function () {
				$status.text( 'Ошибка сети.' ).addClass( 'fs-config-status--err' );
			} ).always( function () {
				$btn.prop( 'disabled', false );
			} );
		} );
	} );
}( jQuery ) );
