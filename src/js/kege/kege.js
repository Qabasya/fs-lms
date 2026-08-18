/**
 * Станция КЕГЭ (Компьютерный ЕГЭ) — точка входа изолированного бандла (T15.10).
 * Guards по наличию разметки: #kegeEntry — ритуал входа/инструкции/регистрации/
 * активации + экран завершения; #kegeExam — реальный экзамен (сервер рендерит
 * только то, что актуально для текущего состояния попытки).
 */
import { initKegeEntry, initPreviewRestart } from './kege-entry.js';
import { initKegeExam } from './kege-exam.js';
import { useKegeAssessment } from './kege-state.js';

document.addEventListener( 'DOMContentLoaded', () => {
	// Состояние в localStorage — своё на каждую контрольную; область задаём один
	// раз здесь, до любого чтения (см. kege-state.js).
	useKegeAssessment( document.getElementById( 'kegeApp' )?.dataset.assessmentId );

	// Экзамен инициализируется первым: в предпросмотре ритуал может сразу раскрыть
	// экран экзамена (восстановленная стадия 'exam') и тут же отправить
	// 'fs-kege-preview-start' — подписчик этого события живёт в initKegeExam(),
	// и при обратном порядке событие уходило в пустоту.
	if ( document.getElementById( 'kegeExam' ) ) {
		initKegeExam();
	}
	if ( document.getElementById( 'kegeEntry' ) ) {
		initKegeEntry();
	}
	initPreviewRestart();
} );
