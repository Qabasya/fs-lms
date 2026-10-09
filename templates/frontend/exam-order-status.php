<?php
/**
 * Блок статуса гостевой записи на странице «Спасибо» WooCommerce (этап 11a.5.6). Страница магазина не заменяется.
 *
 * @var list<array{state: string, title: string, lines: list<string>, refresh: bool}> $blocks
 * @var int    $order_id
 * @var string $order_key
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="fs-exam-order-status" data-exam-order="<?php echo esc_attr( (string) $order_id ); ?>" data-exam-key="<?php echo esc_attr( $order_key ); ?>">
	<?php foreach ( $blocks as $block ) : ?>
		<div class="fs-exam-order-status__item is-<?php echo esc_attr( $block['state'] ); ?>">
			<p class="fs-exam-order-status__title"><?php echo esc_html( $block['title'] ); ?></p>
			<?php foreach ( $block['lines'] as $line ) : ?>
				<p class="fs-exam-order-status__line"><?php echo esc_html( $line ); ?></p>
			<?php endforeach; ?>
			<?php if ( $block['refresh'] ) : ?>
				<button type="button" class="button" data-exam-check><?php esc_html_e( 'Проверить статус', 'fs-lms' ); ?></button>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
</section>
