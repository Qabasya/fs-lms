/**
 * @fileoverview «Выдать ссылку» в списке гостей экзамена: копирует ссылку на форму заявки
 * (/apply/) с заполненными данными гостя. Ссылку собирает сервер ({@see \Inc\Services\Exam\ApplyPrefillLinkService}),
 * здесь только копирование и подтверждение на кнопке.
 */

import { copyToClipboard } from '../../common/utils.js';
import { AlertModal } from '../modals/alert-modal.js';

export const ExamGuestLink = {
	init() {
		const buttons = document.querySelectorAll( '.js-guest-apply-link' );
		if ( ! buttons.length ) { return; }

		buttons.forEach( ( btn ) => btn.addEventListener( 'click', async ( e ) => {
			e.preventDefault();
			if ( ! await copyToClipboard( btn.dataset.link || '' ) ) {
				AlertModal.show( 'Не удалось скопировать ссылку. Скопируйте её вручную: ' + ( btn.dataset.link || '' ) );
				return;
			}
			const label = btn.textContent;
			btn.textContent = 'Скопировано';
			setTimeout( () => { btn.textContent = label; }, 2000 );
		} ) );
	},
};
