/**
 * Admin-JS настроек модуля Masterclass (self-contained, вне core-бандла и вне ESLint src/js).
 * Сохранение вкладки «Настройки → Мастер-классы».
 *
 * Глобал fsLmsMasterclassSettings = { ajaxurl, action, nonce } — локализуется в MasterclassSettingsController.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var $form = $( '#fs-masterclass-form' );
		if ( ! $form.length || typeof window.fsLmsMasterclassSettings === 'undefined' ) {
			return;
		}

		$form.on( 'submit', function ( e ) {
			e.preventDefault();

			var $btn    = $( '#fs-masterclass-save' );
			var $status = $( '#fs-masterclass-status' );
			var data    = {};

			$form.find( 'input[name]' ).each( function () {
				data[ this.name ] = $( this ).val();
			} );
			data.action   = window.fsLmsMasterclassSettings.action;
			data.security = window.fsLmsMasterclassSettings.nonce;

			$btn.prop( 'disabled', true );
			$status.text( '' ).removeClass( 'fs-config-status--ok fs-config-status--err' );

			$.post( window.fsLmsMasterclassSettings.ajaxurl, data ).done( function ( res ) {
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
