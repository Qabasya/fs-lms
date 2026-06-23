/**
 * Управление тумблерами включения/выключения модулей на Dashboard.
 * Данные для AJAX берутся из window.fsLmsModules (локализуется ModulesDashboardController).
 */

const $ = jQuery;

export const ModuleToggle = {
	init() {
		this.bindEvents();
	},

	bindEvents() {
		$( document ).on( 'change', '.js-module-toggle', ( e ) => {
			this.handleToggle( e.currentTarget );
		} );
	},

	handleToggle( input ) {
		const $input  = $( input );
		const $card   = $input.closest( '.fs-module-card' );
		const moduleId = $card.data( 'module' );
		const enabled  = $input.prop( 'checked' );
		const $status  = $card.find( '.fs-module-card__status' );

		$input.prop( 'disabled', true );
		$status.text( 'Сохранение…' ).removeClass( 'fs-module-card__status--success' );

		$.post( fsLmsModules.ajaxurl, {
			action:   fsLmsModules.action,
			module:   moduleId,
			enabled:  enabled ? 1 : 0,
			security: fsLmsModules.nonce,
		} )
			.done( ( res ) => {
				if ( res.success ) {
					$status.text( 'Сохранено' ).addClass( 'fs-module-card__status--success' );
					// Через 800мс перезагружаем — секции/вкладки должны обновиться
					setTimeout( () => window.location.reload(), 800 );
				} else {
					$input.prop( 'checked', ! enabled );
					$status.text( 'Ошибка сохранения' );
					$input.prop( 'disabled', false );
				}
			} )
			.fail( () => {
				$input.prop( 'checked', ! enabled );
				$status.text( 'Ошибка сети' );
				$input.prop( 'disabled', false );
			} );
	},
};
