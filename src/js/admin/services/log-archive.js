/**
 * @fileoverview Месячный архив тяжёлых журналов: «1. Скачать архив» → «2. Очистить журналы».
 *
 * Сервер сам решает, что войдёт в архив и что можно очистить ({@see \Inc\Services\Log\LogArchiveService}):
 * здесь только показ состояния и два действия. Очистка недоступна, пока не скачан архив.
 */

import '../_types.js';
import { post } from './slot-builder.js';
import { ConfirmModal } from '../modals/confirm-modal.js';
import { AlertModal } from '../modals/alert-modal.js';
import { escapeHtml as esc } from '../modules/utils.js';

/* global fs_lms_vars */

export const LogArchive = {
	init() {
		const root = document.getElementById( 'js-log-archive' );
		if ( ! root ) { return; }

		const list      = root.querySelector( '[data-archive-list]' );
		const last      = root.querySelector( '[data-archive-last]' );
		const exportBtn = root.querySelector( '[data-archive-export]' );
		const purgeBtn  = root.querySelector( '[data-archive-purge]' );
		const acts      = fs_lms_vars.ajax_actions;
		const nonce     = fs_lms_vars.nonces.manager;

		const fmtDate = ( s ) => String( s || '' ).slice( 0, 10 ).split( '-' ).reverse().join( '.' );
		const fmtN    = ( n ) => Number( n ).toLocaleString( 'ru-RU' );

		const refresh = async () => {
			try {
				const s = await post( acts.getLogArchiveStatus, nonce, {} );

				list.innerHTML = s.channels.map( ( c ) => `<li><b>${ esc( c.label ) }</b>: ${ fmtN( c.count ) } ${ c.whole
					? '(всё)'
					: `(до ${ esc( fmtDate( s.cutoff ) ) })` }</li>` ).join( '' );

				const l = s.last;
				last.hidden = ! l;
				if ( l ) {
					const total = Object.values( l.channels ).reduce( ( sum, c ) => sum + c.count, 0 );
					last.textContent = `Последний архив: ${ fmtDate( l.exported_at ) }, записей ${ fmtN( total ) }`
						+ ( l.purged_at ? `; очищено ${ fmtDate( l.purged_at ) }.` : '; журналы ещё не очищены.' );
				}
				purgeBtn.disabled = ! s.can_purge;
			} catch ( e ) {
				list.innerHTML = `<li>${ esc( String( e ) ) }</li>`;
			}
		};

		exportBtn.addEventListener( 'click', async () => {
			exportBtn.disabled = true;
			try {
				const d = await post( acts.exportLogArchive, nonce, {} );
				window.location.href = d.url;
				await refresh();
			} catch ( e ) {
				AlertModal.show( String( e ) );
			} finally {
				exportBtn.disabled = false;
			}
		} );

		purgeBtn.addEventListener( 'click', async () => {
			try {
				await ConfirmModal.confirm( {
					title:       'Очистить журналы?',
					message:     'Будут удалены записи, вошедшие в скачанный архив. Убедитесь, что файл сохранён: восстановить можно только из него.',
					isDanger:    true,
					confirmText: 'Очистить',
				} );
			} catch {
				return;
			}

			purgeBtn.disabled = true;
			try {
				await post( acts.purgeLogArchive, nonce, {} );
			} catch ( e ) {
				AlertModal.show( String( e ) );
			}
			await refresh();
		} );

		refresh();
	},
};
