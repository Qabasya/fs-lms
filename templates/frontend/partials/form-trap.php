<?php
/**
 * Honeypot-ловушка публичных форм (заявка, запись гостя на экзамен).
 *
 * Без подписи и подсказок: по тексту label, placeholder и autocomplete браузер узнаёт тип поля и вписывает
 * в него данные профиля, а ловушка должна оставаться пустой у человека. Скрыта CSS-классом `.fs-hp`
 * (за экраном, не display:none — простые боты такие поля пропускают). Читает и очищает значение
 * `src/js/frontend/services/form-trap.js` по атрибуту `data-fs-trap`.
 *
 * @package FS LMS
 *
 * @var string $honeypot Имя поля (FormGuardService::honeypotField()).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="fs-hp" aria-hidden="true">
	<input type="text" name="<?php echo esc_attr( $honeypot ); ?>" value="" tabindex="-1" autocomplete="off" inputmode="none" data-fs-trap>
</div>
