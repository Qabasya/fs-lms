/**
 * Точка входа изолированного бандла страницы контрольной/экзамена (Эпик 15, T15.5).
 * Переиспользует логику существующего сервиса (таймер/автосохранение/сдача) —
 * без изменений; здесь только отдельная точка сборки (assessment.min.js),
 * не тянущая остальной frontend-стек.
 */
import { initAssessment } from '../frontend/services/assessment.js';

document.addEventListener( 'DOMContentLoaded', () => {
	initAssessment();
} );
