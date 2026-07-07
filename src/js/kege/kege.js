/**
 * Станция КЕГЭ (Компьютерный ЕГЭ) — точка входа изолированного бандла (T15.10).
 * Guards по наличию разметки: #kegeEntry — ритуал входа/инструкции/регистрации/
 * активации + экран завершения; #kegeExam — реальный экзамен (сервер рендерит
 * только то, что актуально для текущего состояния попытки).
 */
import { initKegeEntry } from './kege-entry.js';
import { initKegeExam } from './kege-exam.js';

document.addEventListener( 'DOMContentLoaded', () => {
	if ( document.getElementById( 'kegeEntry' ) ) {
		initKegeEntry();
	}
	if ( document.getElementById( 'kegeExam' ) ) {
		initKegeExam();
	}
} );
