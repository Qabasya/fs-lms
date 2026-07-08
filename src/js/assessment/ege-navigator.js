/**
 * Станция-навигатор обычного ЕГЭ (D16.7, T16.11b) — прогрессивное улучшение
 * поверх той же формы попытки, что и одностраничный список: одно задание на
 * экран + боковое меню номеров. Autosave/файлы/сдача остаются на общем
 * контракте (frontend/services/assessment.js) — навигатор только управляет
 * видимостью .fs-attempt-question и подсвечивает меню.
 *
 * Без JS (или до инициализации) все задания видны списком — .fs-ege-nav.is-ready
 * включает режим «одно за раз» только после успешного init.
 */

/** Отвечено ли задание: есть текст ответа или прикреплён файл. */
function isAnswered( question ) {
	const textarea = question.querySelector( '.fs-attempt-answer' );
	const hasText  = textarea && '' !== textarea.value.trim();
	const hasFile  = question.querySelector( '.fs-attempt-files__chip' );
	return !! ( hasText || hasFile );
}

export function initEgeNavigator() {
	const nav = document.querySelector( '.fs-ege-nav' );
	if ( ! nav ) { return; }

	const form      = nav.querySelector( '.fs-attempt-form' );
	const questions = Array.from( nav.querySelectorAll( '.fs-attempt-question' ) );
	const menuBtns  = Array.from( nav.querySelectorAll( '.fs-ege-nav__num' ) );
	const prevBtn   = nav.querySelector( '[data-nav-prev]' );
	const nextBtn   = nav.querySelector( '[data-nav-next]' );
	if ( ! form || ! questions.length ) { return; }

	// Меню и задания генерируются одним и тем же отфильтрованным циклом на сервере,
	// поэтому K-я кнопка соответствует K-му заданию (индексация по позиции в DOM,
	// а не по data-nav-index — устойчиво к пропущенным задачам).
	let current = 0;

	nav.classList.add( 'is-ready' );

	function refreshMenu() {
		menuBtns.forEach( ( btn, i ) => {
			btn.classList.toggle( 'is-current', i === current );
			if ( questions[ i ] ) {
				btn.classList.toggle( 'is-answered', isAnswered( questions[ i ] ) );
			}
		} );
	}

	function show( index ) {
		current = Math.max( 0, Math.min( index, questions.length - 1 ) );
		questions.forEach( ( q, i ) => q.classList.toggle( 'is-active', i === current ) );
		if ( prevBtn ) { prevBtn.disabled = 0 === current; }
		if ( nextBtn ) { nextBtn.disabled = current === questions.length - 1; }
		refreshMenu();
	}

	menuBtns.forEach( ( btn, i ) => btn.addEventListener( 'click', () => show( i ) ) );
	if ( prevBtn ) { prevBtn.addEventListener( 'click', () => show( current - 1 ) ); }
	if ( nextBtn ) { nextBtn.addEventListener( 'click', () => show( current + 1 ) ); }

	// Обновляем маркеры «отвечено» при вводе и после манипуляций с файлами.
	form.addEventListener( 'input', refreshMenu );
	form.addEventListener( 'click', ( e ) => {
		if ( e.target.closest( '.fs-attempt-files' ) ) {
			setTimeout( refreshMenu, 150 );
		}
	} );

	show( 0 );
}
