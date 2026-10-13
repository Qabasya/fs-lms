import '../../_types.js';
import { AlertModal } from '../../modals/alert-modal.js';
import { ConfirmModal } from '../../modals/confirm-modal.js';

const $ = jQuery;

export const ConfigSettings = {

	init() {
		if ( ! $( '#tab-config' ).length ) {
			return;
		}
		this.bindEvents();
	},

	bindEvents() {
		// Кнопка «Сохранить» есть в каждой карточке формы; сохраняется вся форма, статус показывается у нажатой кнопки.
		$( '#fs-config-form' ).on( 'submit', ( e ) => {
			e.preventDefault();
			const submitter = e.originalEvent && e.originalEvent.submitter;
			this.saveConfig( $( submitter ).closest( '.fs-card' ) );
		} );

		$( document ).on( 'click', '.js-generate-key', ( e ) => {
			const type = $( e.currentTarget ).data( 'type' );
			this.generateKey( type );
		} );

		$( document ).on( 'click', '.js-copy-key', ( e ) => {
			const targetId = $( e.currentTarget ).data( 'target' );
			this.copyToClipboard( targetId, e.currentTarget );
		} );
	},

	/** Поля секции «Экзамены для гостей»: присутствуют в форме только при активном WooCommerce (имена — `exam_*`, `center_*`). */
	examFields( $form ) {
		const fields = {};
		$form.find( '[data-exam-field]' ).each( ( _, el ) => {
			fields[ el.name ] = $( el ).val();
		} );
		return fields;
	},

	saveConfig( $card ) {
		const $form   = $( '#fs-config-form' );
		const $scope  = $card && $card.length ? $card : $form;
		const $status = $scope.find( '.js-config-status' ).first();
		const $btn    = $form.find( '.js-config-save' );

		$form.find( '.js-config-status' ).text( '' ).removeClass( 'fs-config-status--ok fs-config-status--err' );
		$btn.prop( 'disabled', true );

		$.post( fs_lms_vars.ajaxurl, {
			action:        fs_lms_vars.ajax_actions.saveConfig,
			security:      fs_lms_vars.nonces.config,
			otp_bypass_code: $form.find( '[name=otp_bypass_code]' ).val(),
			test_env:      $form.find( '[name=test_env]' ).is( ':checked' ) ? 1 : 0,
			consultation_url: $form.find( '[name=consultation_url]' ).val() || '',
			...this.examFields( $form ),
		} )
			.done( ( res ) => {
				if ( res.success ) {
					$status.text( 'Сохранено.' ).addClass( 'fs-config-status--ok' );
				} else {
					$status.text( res.data || 'Ошибка.' ).addClass( 'fs-config-status--err' );
				}
			} )
			.fail( () => {
				$status.text( 'Ошибка сети.' ).addClass( 'fs-config-status--err' );
			} )
			.always( () => {
				$btn.prop( 'disabled', false );
			} );
	},

	generateKey( type ) {
		const isEncKey   = type === 'enc_key';
		const outputId   = isEncKey ? 'fs-enc-key-output' : 'fs-hash-salt-output';
		const valueId    = isEncKey ? 'fs-enc-key-value' : 'fs-hash-salt-value';

		const doRequest = ( confirm = false ) => {
			$.post( fs_lms_vars.ajaxurl, {
				action:   fs_lms_vars.ajax_actions.generateKey,
				security: fs_lms_vars.nonces.config,
				type:     type,
				confirm:  confirm ? 1 : 0,
			} )
				.done( ( res ) => {
					if ( res.success ) {
						$( '#' + valueId ).val( res.data.define );
						$( '#' + outputId ).removeAttr( 'hidden' );
					} else {
						const msg = res.data || 'Ошибка генерации.';
						// Предупреждение о перегенерации — предлагаем подтверждение
						if ( msg.includes( 'зашифрованные данные' ) ) {
							ConfirmModal.show( {
								title:     'Внимание',
								message:   msg,
								confirmText: 'Всё равно сгенерировать',
								onConfirm: () => doRequest( true ),
							} );
						} else {
							AlertModal.show( { title: 'Ошибка', message: msg } );
						}
					}
				} )
				.fail( () => {
					AlertModal.show( { title: 'Ошибка', message: 'Ошибка сети.' } );
				} );
		};

		doRequest();
	},

	copyToClipboard( targetId, btn ) {
		const $textarea = $( '#' + targetId );
		if ( ! $textarea.length ) {
			return;
		}

		navigator.clipboard.writeText( $textarea.val() ).then( () => {
			const $btn = $( btn );
			const orig = $btn.text();
			$btn.text( 'Скопировано!' );
			setTimeout( () => $btn.text( orig ), 2000 );
		} );
	},
};
