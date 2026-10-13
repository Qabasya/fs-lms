import '../../_types.js';
import { AlertModal } from '../../modals/alert-modal.js';

const $ = jQuery;

/**
 * Вкладка «Настройки → Аватары»: выбор картинки из медиатеки для предмета, группы, кабинета.
 * Сохраняется сразу по выбору (одна сущность = один запрос), отдельной кнопки «Сохранить» нет.
 */
export const AvatarSettings = {

	init() {
		if ( ! $( '#tab-avatars' ).length ) {
			return;
		}
		this.bindEvents();
	},

	bindEvents() {
		$( document ).on( 'click', '.js-avatar-pick', ( e ) => this.pick( $( e.currentTarget ).closest( '.js-avatar-row' ) ) );
		$( document ).on( 'click', '.js-avatar-clear', ( e ) => this.save( $( e.currentTarget ).closest( '.js-avatar-row' ), 0 ) );
	},

	/** Открывает медиатеку; выбранная картинка уходит на сервер. */
	pick( $row ) {
		if ( ! window.wp || ! wp.media ) {
			AlertModal.show( { title: 'Ошибка', message: 'Медиатека недоступна на этой странице.' } );
			return;
		}

		const frame = wp.media( {
			title:    'Аватар',
			button:   { text: 'Выбрать' },
			library:  { type: 'image' },
			multiple: false,
		} );

		frame.on( 'select', () => {
			const att = frame.state().get( 'selection' ).first().toJSON();
			this.save( $row, att.id );
		} );

		frame.open();
	},

	save( $row, attachmentId ) {
		const $status = $row.find( '.js-avatar-status' );
		$status.text( '' ).removeClass( 'fs-config-status--ok fs-config-status--err' );
		$row.find( 'button' ).prop( 'disabled', true );

		$.post( fs_lms_vars.ajaxurl, {
			action:        fs_lms_vars.ajax_actions.saveAvatar,
			security:      fs_lms_vars.nonces.avatar,
			entity:        $row.data( 'entity' ),
			key:           $row.data( 'key' ),
			attachment_id: attachmentId,
		} )
			.done( ( res ) => {
				if ( ! res.success ) {
					$status.text( res.data || 'Ошибка.' ).addClass( 'fs-config-status--err' );
					return;
				}
				const url = res.data.url || '';
				$row.find( '.js-avatar-preview' ).attr( 'src', url ).prop( 'hidden', '' === url );
				$row.find( '.js-avatar-clear' ).prop( 'hidden', '' === url );
				$status.text( 'Сохранено.' ).addClass( 'fs-config-status--ok' );
			} )
			.fail( () => {
				$status.text( 'Ошибка сети.' ).addClass( 'fs-config-status--err' );
			} )
			.always( () => {
				$row.find( 'button' ).prop( 'disabled', false );
			} );
	},
};
