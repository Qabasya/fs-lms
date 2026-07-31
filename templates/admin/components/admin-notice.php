<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Одиночный админ-нотис.
 *
 * @var string $type    Тип: error|warning|success|info
 * @var string $message Текст сообщения
 */
?>
<div class="notice notice-<?php echo esc_attr( $type ); ?>">
	<p><?php echo esc_html( $message ); ?></p>
</div>
