<?php
/**
 * Обратный отсчёт временной брони в корзине и на оформлении (этап 11a.4.5).
 *
 * @var string $until        Время окончания брони (местное, ЧЧ:ММ)
 * @var int    $seconds_left Сколько осталось секунд — считает сервер; клиент только тикает от этого числа
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="fs-apply-card__status fs-exam-hold" data-exam-hold data-seconds-left="<?php echo esc_attr( (string) $seconds_left ); ?>">
	<?php echo esc_html( sprintf( 'Место удерживается до %s, осталось', $until ) ); ?>
	<strong data-exam-hold-left>--:--</strong>
</div>
