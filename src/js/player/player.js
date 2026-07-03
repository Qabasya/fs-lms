/**
 * Плеер курса (Эпик 14, D18) — точка входа бандла player.min.js.
 *
 * Полноэкранный SPA поверх шаблона lesson-player/player.php:
 * оболочка ЛК (сайдбар/топбар/тост) + лента шагов + рейка дерева курса.
 * Грузится только на маршруте плеера (/group/?gid&gl, ученик).
 */
import { initShell } from './shell.js';
import { initCore } from './core.js';

document.addEventListener( 'DOMContentLoaded', () => {
	initShell();
	initCore();
} );
