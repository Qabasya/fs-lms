import './../_types.js';
const $ = jQuery;

export const ConsentSettings = {

	init() {
		if ( ! $( '#js-consent-hash-lookup' ).length ) {
			return;
		}
		this.bindEvents();
	},

	bindEvents() {
		$( '#js-consent-hash-lookup' ).on( 'click', () => {
			this._handleLookup();
		} );

		$( '#js-consent-hash-input' ).on( 'keydown', ( e ) => {
			if ( e.key === 'Enter' ) {
				this._handleLookup();
			}
		} );
	},

	_handleLookup() {
		const hash    = $( '#js-consent-hash-input' ).val().trim();
		const $result = $( '#js-consent-lookup-result' );
		const $btn    = $( '#js-consent-hash-lookup' );

		if ( ! hash ) {
			return;
		}

		$btn.prop( 'disabled', true ).text( 'Поиск…' );
		$result.hide().removeClass( 'fs-consent-tab__lookup-result--error' );

		$.post( fs_lms_vars.ajaxurl, {
			action:   fs_lms_vars.ajax_actions.lookupConsentByHash,
			security: fs_lms_vars.nonces.manager,
			hash,
		} )
			.done( ( res ) => {
				if ( ! res.success ) {
					$result
						.addClass( 'fs-consent-tab__lookup-result--error' )
						.html( '<strong>Ошибка:</strong> ' + this._esc( res.data || 'Неизвестная ошибка' ) )
						.show();
					return;
				}

				const data = res.data;
				if ( ! data.found ) {
					$result
						.addClass( 'fs-consent-tab__lookup-result--error' )
						.html( 'Версия с таким хешем не найдена.' )
						.show();
					return;
				}

				$result
					.html(
						'<p><strong>' + this._esc( data.version ) + '</strong> · ' + this._esc( data.date ) + '</p>'
						+ '<div style="max-height:400px; overflow-y:auto; border:1px solid #c3c4c7; padding:12px; border-radius:4px; background:#fff; margin-top:8px;">'
						+ data.content
						+ '</div>'
					)
					.show();
			} )
			.fail( () => {
				$result
					.addClass( 'fs-consent-tab__lookup-result--error' )
					.html( 'Ошибка сервера при поиске.' )
					.show();
			} )
			.always( () => {
				$btn.prop( 'disabled', false ).text( 'Найти' );
			} );
	},

	_esc( str ) {
		return $( '<span>' ).text( String( str ) ).html();
	},
};
