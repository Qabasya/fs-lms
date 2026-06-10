import './../_types.js';
const $ = jQuery;

export const EmailTemplateSettings = {

	init() {
		if ( ! $( '#js-email-templates' ).length ) {
			return;
		}
		this.bindEvents();
	},

	bindEvents() {
		$( '#js-email-templates' ).on( 'click', '.js-save-email-template', ( e ) => {
			this._handleSave( $( e.currentTarget ).closest( '.fs-email-template-card' ) );
		} );

		$( '#js-email-templates' ).on( 'click', '.js-reset-email-template', ( e ) => {
			this._handleReset( $( e.currentTarget ).closest( '.fs-email-template-card' ) );
		} );
	},

	_handleSave( $card ) {
		const type    = $card.data( 'type' );
		const subject = $card.find( '.js-email-subject' ).val().trim();
		const body    = $card.find( '.js-email-body' ).val().trim();
		const $btn    = $card.find( '.js-save-email-template' );
		const $notice = $card.find( '.js-template-notice' );

		$btn.prop( 'disabled', true ).text( 'Сохранение…' );
		$notice.hide();

		$.post( fs_lms_vars.ajaxurl, {
			action:   fs_lms_vars.ajax_actions.saveEmailTemplate,
			security: fs_lms_vars.nonces.manager,
			type,
			subject,
			body,
		} )
			.done( ( res ) => {
				if ( res.success ) {
					this._setStatus( $card, true );
					this._showNotice( $notice, res.data.message || 'Сохранено', 'success' );
					$card.find( '.js-reset-email-template' ).prop( 'disabled', false );
				} else {
					this._showNotice( $notice, res.data || 'Ошибка сохранения', 'error' );
				}
			} )
			.fail( () => {
				this._showNotice( $notice, 'Ошибка сервера', 'error' );
			} )
			.always( () => {
				$btn.prop( 'disabled', false ).text( 'Сохранить' );
			} );
	},

	_handleReset( $card ) {
		const type    = $card.data( 'type' );
		const $btn    = $card.find( '.js-reset-email-template' );
		const $notice = $card.find( '.js-template-notice' );

		$btn.prop( 'disabled', true ).text( 'Сброс…' );
		$notice.hide();

		$.post( fs_lms_vars.ajaxurl, {
			action:   fs_lms_vars.ajax_actions.resetEmailTemplate,
			security: fs_lms_vars.nonces.manager,
			type,
		} )
			.done( ( res ) => {
				if ( res.success ) {
					this._setStatus( $card, false );
					$card.find( '.js-email-subject' ).val( '' );
					$card.find( '.js-email-body' ).val( '' );
					$btn.prop( 'disabled', true );
					this._showNotice( $notice, res.data.message || 'Сброшено', 'success' );
				} else {
					this._showNotice( $notice, res.data || 'Ошибка', 'error' );
					$btn.prop( 'disabled', false );
				}
			} )
			.fail( () => {
				this._showNotice( $notice, 'Ошибка сервера', 'error' );
				$btn.prop( 'disabled', false );
			} )
			.always( () => {
				$btn.text( 'Сбросить к умолчанию' );
			} );
	},

	_setStatus( $card, isCustom ) {
		const $label = $card.find( '[data-status-label]' );
		$label
			.text( isCustom ? 'Переопределён' : 'По умолчанию' )
			.removeClass( 'fs-email-template-card__status--custom fs-email-template-card__status--default' )
			.addClass( isCustom
				? 'fs-email-template-card__status--custom'
				: 'fs-email-template-card__status--default'
			);
	},

	_showNotice( $el, text, type ) {
		const color = type === 'success' ? '#00a32a' : '#d63638';
		$el.text( text ).css( 'color', color ).show();
		setTimeout( () => $el.fadeOut( 400 ), 3000 );
	},
};
