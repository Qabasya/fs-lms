/**
 * @fileoverview Чекбоксы «Оповещение отправлено» и «Запись подтверждена» в списке гостей экзамена:
 * отметка сохраняется сразу при клике. Подтвердить запись можно только после оповещения;
 * снятие оповещения снимает и подтверждение (сервер делает то же). При сбое чекбокс возвращается.
 */

import { showNotice } from '../modules/utils.js';

export const ExamGuestFlags = {
	init() {
		const boxes = document.querySelectorAll( '.js-guest-flag' );
		if ( ! boxes.length ) { return; }

		boxes.forEach( ( box ) => box.addEventListener( 'change', () => this.save( box ) ) );
	},

	/** Парный чекбокс «Запись подтверждена» той же строки. */
	confirmBoxOf( box ) {
		return box.closest( 'tr' )?.querySelector( '.js-guest-flag[data-flag="confirm"]' ) ?? null;
	},

	async save( box ) {
		const checked  = box.checked;
		const isNotify = 'notify' === box.dataset.flag;
		box.disabled   = true;

		const body = new FormData();
		body.append( 'action', box.dataset.action );
		body.append( 'security', box.dataset.nonce );
		body.append( 'participation_id', box.dataset.participation );
		if ( isNotify ) {
			body.append( 'notified', checked ? '1' : '0' );
		} else {
			body.append( 'session_id', box.dataset.session );
			body.append( 'confirmed', checked ? '1' : '0' );
		}

		try {
			const res  = await fetch( fs_lms_vars.ajaxurl, { method: 'POST', body } );
			const json = await res.json();
			if ( ! json.success ) {
				throw new Error( json.data?.message || json.data || 'Не удалось сохранить отметку.' );
			}
			if ( isNotify ) {
				const confirmBox = this.confirmBoxOf( box );
				if ( confirmBox ) {
					if ( ! checked ) { confirmBox.checked = false; }
					confirmBox.disabled = ! checked || '1' !== confirmBox.dataset.canConfirm;
				}
			}
		} catch ( e ) {
			box.checked = ! checked;
			showNotice( e.message || 'Не удалось сохранить отметку.', 'error' );
		} finally {
			box.disabled = false;
		}
	},
};
