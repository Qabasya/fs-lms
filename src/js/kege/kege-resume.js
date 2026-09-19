/**
 * Публичный экзамен: вопрос «продолжить или начать заново» при заходе на страницу
 * с незавершённой попыткой в этом браузере.
 *
 * Состояние живёт в localStorage, а браузер в школе бывает общим: следующий
 * ученик не должен молча получить чужие ответы и остаток времени. Спрашиваем
 * только когда попытка ещё в силе — с истёкшим сроком экзамен сам завершится
 * и покажет результат (см. kege-exam.js).
 */
import { clearKegeState, loadKegeState } from './kege-state.js';

/**
 * @returns {Promise<void>} Разрешается, когда выбор сделан (или спрашивать нечего)
 */
export function resolvePublicResume() {
	const app = document.getElementById( 'kegeApp' );
	if ( ! app || '1' !== app.dataset.public ) { return Promise.resolve(); }

	const state = loadKegeState();
	if ( 'exam' !== state.stage || ! state.deadlineTs || state.deadlineTs <= Date.now() ) {
		return Promise.resolve();
	}

	const minutesLeft = Math.max( 1, Math.ceil( ( state.deadlineTs - Date.now() ) / 60000 ) );
	const answered    = Object.keys( state.answers ).length;

	return new Promise( ( resolve ) => {
		const ovl  = document.createElement( 'div' );
		ovl.className = 'kege-ovl';

		const card = document.createElement( 'div' );
		card.className = 'kege-mcard';

		const h4 = document.createElement( 'h4' );
		h4.textContent = 'Найдена незавершённая попытка';

		const p = document.createElement( 'p' );
		p.textContent = 'Осталось ' + minutesLeft + ' мин, дано ответов: ' + answered + '. Продолжить или начать заново?';

		const row = document.createElement( 'div' );
		row.className = 'kege-m-row';

		const restart = document.createElement( 'button' );
		restart.type = 'button';
		restart.className = 'kege-m-ghost';
		restart.textContent = 'Начать заново';

		const resume = document.createElement( 'button' );
		resume.type = 'button';
		resume.className = 'kege-btn kege-btn--cyan';
		resume.textContent = 'Продолжить';

		row.append( restart, resume );
		card.append( h4, p, row );
		ovl.appendChild( card );
		document.body.appendChild( ovl );

		const done = () => { ovl.remove(); resolve(); };
		resume.addEventListener( 'click', done );
		restart.addEventListener( 'click', () => { clearKegeState(); done(); } );
	} );
}
