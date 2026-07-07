/**
 * Точка входа изолированного бандла страницы контрольной/экзамена (Эпик 15, T15.5).
 * Переиспользует логику существующего сервиса (таймер/автосохранение/сдача) —
 * без изменений; здесь только отдельная точка сборки (assessment.min.js),
 * не тянущая остальной frontend-стек.
 */
import { initAssessment } from '../frontend/services/assessment.js';
import { initEgeNavigator } from './ege-navigator.js';

document.addEventListener( 'DOMContentLoaded', () => {
	initAssessment();
	// D16.7: станция-навигатор обычного ЕГЭ (активна только при .fs-ege-nav).
	initEgeNavigator();
} );
