import './../_types.js';
const $ = jQuery;

export const LogsTable = {

	init() {
		if ( ! $( '#js-audit-log-tab, #js-pii-log-tab' ).length ) {
			return;
		}
		this.bindEvents();
	},

	bindEvents() {
		$( document ).on( 'click', '.js-export-audit', () => this._exportAudit() );
		$( document ).on( 'click', '.js-export-pii',   () => this._exportPii() );
	},

	_exportAudit() {
		const raw     = document.getElementById( 'js-audit-export-filters' );
		const filters = raw ? JSON.parse( raw.textContent ) : {};
		this._doExport( fs_lms_vars.ajax_actions.exportAuditLog, filters, '.js-export-audit' );
	},

	_exportPii() {
		const raw     = document.getElementById( 'js-pii-export-filters' );
		const filters = raw ? JSON.parse( raw.textContent ) : {};
		this._doExport( fs_lms_vars.ajax_actions.exportPiiLog, filters, '.js-export-pii' );
	},

	_doExport( action, filters, btnSelector ) {
		const $btn = $( btnSelector );
		$btn.prop( 'disabled', true ).text( 'Подготовка…' );

		$.post( fs_lms_vars.ajaxurl, {
			action,
			security: fs_lms_vars.nonces.manager,
			...filters,
		} )
			.done( ( res ) => {
				if ( res.success && res.data.url ) {
					window.location.href = res.data.url;
				} else {
					alert( res.data || 'Ошибка экспорта' );
				}
			} )
			.fail( () => {
				alert( 'Ошибка сервера при экспорте' );
			} )
			.always( () => {
				$btn.prop( 'disabled', false ).html(
					'<span class="dashicons dashicons-download" style="vertical-align:middle;margin-top:3px;"></span> Экспорт CSV'
				);
			} );
	},
};
