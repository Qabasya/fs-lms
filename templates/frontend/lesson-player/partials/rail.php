<?php
/**
 * Рейка шагов урока (Tasks.md З4): липкая колонка квадратов-номеров слева от
 * контента, без разворота. Квадраты, их состояния (текущий / пройден / закрыт)
 * и подсказку «иконка · тип · название» по наведению рисует rail.js из панелей
 * плеера — единый источник иконок и статусов (icons.js). На телефоне рейка
 * становится горизонтальной полосой над контентом, а тип и название текущего
 * шага выводит строка `#fsRailCur` (наведения на тач-экране нет).
 *
 * @package FS LMS
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<aside class="railwrap">
	<nav class="rail" id="fsRail" aria-label="<?php esc_attr_e( 'Шаги урока', 'fs-lms' ); ?>"></nav>
	<div class="rail-cur" id="fsRailCur" aria-live="polite"></div>
</aside>
<div class="rail-pop" id="fsRailPop" role="tooltip" hidden></div>
