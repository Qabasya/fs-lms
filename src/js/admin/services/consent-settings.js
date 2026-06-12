import './../_types.js';
import { toSlug } from '../modules/utils.js';
import { AlertModal } from '../modals/alert-modal.js';
import { ConfirmModal } from '../modals/confirm-modal.js';
const $ = jQuery;

export const ConsentSettings = {

	init() {
		if ( ! $( '#tab-consents' ).length ) {
			return;
		}
		this.bindEvents();
	},

	bindEvents() {
		// Аккордеон
		$( '#js-consents-table' ).on( 'click', '.js-consent-toggle', ( e ) => {
			if ( $( e.target ).closest( 'a, button' ).length ) return;
			const key   = $( e.currentTarget ).data( 'key' );
			const $row  = $( `#consent-accordion-${key}` );
			const $icon = $( e.currentTarget ).find( '.accordion-arrow' );
			$row.toggleClass( 'hidden' );
			$icon.css( 'transform', $row.hasClass( 'hidden' ) ? '' : 'rotate(90deg)' );
		} );

		// Удалить согласие
		$( '#js-consents-table' ).on( 'click', '.js-delete-consent', async ( e ) => {
			e.preventDefault();
			const $btn = $( e.currentTarget );
			const key  = $btn.data( 'key' );
			const name = $btn.data( 'name' );
			try {
				await ConfirmModal.confirm( {
					message:     `Удалить определение «${name}»?\n\nСтраница WP останется для истории.`,
					confirmText: 'Удалить',
					isDanger:    true,
				} );
			} catch { return; }
			this._deleteConsent( key, $btn.closest( 'tr' ) );
		} );

		// Открыть модал
		$( document ).on( 'click', '.js-open-consent-modal', () => this._openModal() );
		$( document ).on( 'click', '.js-close-consent-modal', () => this._closeModal() );
		$( document ).on( 'keydown', ( e ) => {
			if ( e.key === 'Escape' ) this._closeModal();
		} );

		// Авто-генерация ключа из названия
		$( '#consent-def-name' ).on( 'input', function () {
			const $keyField = $( '#consent-def-key' );
			if ( $keyField.data( 'user-edited' ) ) return;
			$keyField.val( toSlug( $( this ).val() ) );
		} );
		$( '#consent-def-key' ).on( 'input', function () {
			$( this ).data( 'user-edited', $( this ).val() !== '' );
		} );

		// Сабмит формы
		$( '#js-consent-def-submit' ).on( 'click', () => this._submitModal() );
	},

	_openModal() {
		$( '#consent-def-name' ).val( '' );
		$( '#consent-def-key' ).val( '' ).removeData( 'user-edited' );
		$( '#js-consent-modal-notice' ).hide().text( '' );
		$( '#consent-definition-modal' ).show();
		setTimeout( () => $( '#consent-def-name' ).trigger( 'focus' ), 50 );
	},

	_closeModal() {
		$( '#consent-definition-modal' ).hide();
	},

	_submitModal() {
		const name = $( '#consent-def-name' ).val().trim();
		const key  = $( '#consent-def-key' ).val().trim();
		const $btn = $( '#js-consent-def-submit' );
		const $notice = $( '#js-consent-modal-notice' );

		if ( ! name || ! key ) {
			this._showModalError( 'Заполните все поля.' );
			return;
		}
		if ( ! /^[a-z0-9_\-]+$/.test( key ) ) {
			this._showModalError( 'Ключ может содержать только строчные буквы, цифры, _ и -.' );
			return;
		}

		$btn.prop( 'disabled', true ).text( 'Создание…' );
		$notice.hide();

		$.post( fs_lms_vars.ajaxurl, {
			action:   fs_lms_vars.ajax_actions.addConsentDefinition,
			security: fs_lms_vars.nonces.manager,
			name,
			key,
		} )
			.done( ( res ) => {
				if ( ! res.success ) {
					this._showModalError( res.data || 'Ошибка создания.' );
					return;
				}
				// Перезагружаем страницу для отображения новой строки в таблице
				window.location.reload();
			} )
			.fail( () => this._showModalError( 'Ошибка сервера.' ) )
			.always( () => $btn.prop( 'disabled', false ).text( 'Создать согласие' ) );
	},

	_deleteConsent( key, $mainRow ) {
		$.post( fs_lms_vars.ajaxurl, {
			action:   fs_lms_vars.ajax_actions.deleteConsentDefinition,
			security: fs_lms_vars.nonces.manager,
			key,
		} )
			.done( ( res ) => {
				if ( res.success ) {
					$mainRow.next( '.consent-accordion-row' ).remove();
					$mainRow.remove();
				} else {
					AlertModal.show( res.data || 'Ошибка удаления.' );
				}
			} )
			.fail( () => AlertModal.show( 'Ошибка сервера.' ) );
	},

	_showModalError( msg ) {
		$( '#js-consent-modal-notice' ).text( msg ).show();
	},
};
