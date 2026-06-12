import './../_types.js';
import { AlertModal } from '../modals/alert-modal.js';
const $ = jQuery;

const CHANNEL_ACTIONS = {
	entity_audit:   'exportEntityAuditLog',
	enrollment:     'exportEnrollmentLog',
	pii:            'exportPiiLog',
	export:         'exportExportLog',
	data_change:    'exportDataChangeLog',
	consent_change: 'exportConsentChangeLog',
	email:          'exportEmailLog',
	deletion:       'exportDeletionLog',
	auth:           'exportAuthLog',
};

export const LogsTable = {

	init() {
		if ( ! $( '[id^="js-"][id$="-tab"]' ).length ) {
			return;
		}
		this.bindEvents();
	},

	bindEvents() {
		$( document ).on( 'click', '.js-export-log-csv', ( e ) => {
			const $btn    = $( e.currentTarget );
			const channel = $btn.data( 'channel' );
			const action  = CHANNEL_ACTIONS[ channel ];

			if ( ! action || ! fs_lms_vars.ajax_actions[ action ] ) {
				// eslint-disable-next-line no-console
				console.error( '[fs-lms] Unknown export channel:', channel );
				return;
			}

			let filters = {};
			try {
				const raw = $btn.attr( 'data-filters' );
				if ( raw ) {
					filters = JSON.parse( raw );
				}
			} catch ( err ) {
				// ignore malformed JSON
			}

			this._doExport( fs_lms_vars.ajax_actions[ action ], filters, $btn );
		} );
	},

	_doExport( action, filters, $btn ) {
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
					AlertModal.show( res.data || 'Ошибка экспорта' );
				}
			} )
			.fail( () => {
				AlertModal.show( 'Ошибка сервера при экспорте' );
			} )
			.always( () => {
				$btn.prop( 'disabled', false ).html(
					'<span class="dashicons dashicons-download" style="vertical-align:middle;margin-top:3px;"></span> Экспорт CSV'
				);
			} );
	},
};
