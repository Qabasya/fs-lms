/**
 * Admin-JS модуля SmartCaptcha (self-contained, вне core-бандла и вне ESLint src/js).
 * Сохранение ключей секции «Настройка Yandex SmartCaptcha» в табе «Конфигурация».
 *
 * Глобал fsLmsSmartCaptcha = { ajaxurl, action, nonce } — локализуется в SmartCaptchaSettingsController.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var $form = $( '#fs-smart-captcha-form' );
		if ( ! $form.length || typeof window.fsLmsSmartCaptcha === 'undefined' ) {
			return;
		}

		$form.on( 'submit', function ( e ) {
			e.preventDefault();

			var $btn    = $( '#fs-smart-captcha-save' );
			var $status = $( '#fs-smart-captcha-status' );

			$btn.prop( 'disabled', true );
			$status.text( '' ).removeClass( 'fs-config-status--ok fs-config-status--err' );

			$.post( window.fsLmsSmartCaptcha.ajaxurl, {
				action:             window.fsLmsSmartCaptcha.action,
				security:           window.fsLmsSmartCaptcha.nonce,
				captcha_site_key:   $( '#fs-captcha-site' ).val(),
				captcha_server_key: $( '#fs-captcha-server' ).val()
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
