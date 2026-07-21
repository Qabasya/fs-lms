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

		// Дропдаун «Направления с доменными учётками»: кнопка открывает панель с чекбоксами,
		// клик снаружи закрывает, текст на кнопке отражает текущий выбор.
		var $subjectsDropdown = $form.find( '[data-fs-dropdown].fs-adsync-subjects' );
		var $subjectsToggle   = $subjectsDropdown.find( '.fs-adsync-subjects__toggle' );
		var $subjectsPanel    = $subjectsDropdown.find( '.fs-adsync-subjects__panel' );
		var $subjectsSummary  = $subjectsDropdown.find( '.fs-adsync-subjects__summary' );

		function updateSubjectsSummary() {
			var count = $subjectsPanel.find( 'input[name="provision_subjects[]"]:checked' ).length;
			$subjectsSummary.text( count > 0 ? 'Выбрано направлений: ' + count : 'Ничего не выбрано' );
		}

		function closeSubjectsDropdown() {
			$subjectsPanel.attr( 'hidden', true );
			$subjectsToggle.attr( 'aria-expanded', 'false' );
		}

		$subjectsToggle.on( 'click', function ( e ) {
			e.stopPropagation();
			var isOpen = ! $subjectsPanel.attr( 'hidden' );
			if ( isOpen ) {
				closeSubjectsDropdown();
			} else {
				$subjectsPanel.removeAttr( 'hidden' );
				$subjectsToggle.attr( 'aria-expanded', 'true' );
			}
		} );

		$subjectsPanel.on( 'click', function ( e ) {
			e.stopPropagation();
		} );

		$subjectsPanel.on( 'change', 'input[name="provision_subjects[]"]', updateSubjectsSummary );

		$( document ).on( 'click', closeSubjectsDropdown );

		// Сохранение настроек секции (сейчас: список направлений с доменными учётками).
		$form.on( 'submit', function ( e ) {
			e.preventDefault();

			var $status = $( '#fs-adsync-status' );
			var $btn    = $( '#fs-adsync-save' );

			var subjects = $form.find( 'input[name="provision_subjects[]"]:checked' )
				.map( function () { return this.value; } )
				.get();

			$btn.prop( 'disabled', true );
			$status.text( '' ).removeClass( 'fs-config-status--ok fs-config-status--err' );

			$.post( window.fsLmsAdSync.ajaxurl, {
				action:             window.fsLmsAdSync.action,
				security:           window.fsLmsAdSync.nonce,
				provision_subjects: subjects,
			} )
				.done( function ( res ) {
					if ( res && res.success ) {
						$status.text( 'Сохранено.' ).addClass( 'fs-config-status--ok' );
					} else {
						$status.text( ( res && res.data ) || 'Ошибка.' ).addClass( 'fs-config-status--err' );
					}
				} )
				.fail( function () {
					$status.text( 'Ошибка сети.' ).addClass( 'fs-config-status--err' );
				} )
				.always( function () {
					$btn.prop( 'disabled', false );
				} );
		} );

	} );
}( jQuery ) );
